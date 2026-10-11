<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\History;
use App\Models\Shop;
use App\Models\WalletRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the dashboard shows, in one place.
 *
 * `histories.total` is the authoritative money column: it is the line total the
 * receipt actually charged, while `price` is the unit price. Summing
 * `price * amount` disagrees with the paper whenever a line was weighed or
 * discounted, so every sum here is over `total`.
 *
 * No date arithmetic happens in SQL. Production is MariaDB and the tests are
 * sqlite, and DATEDIFF/julianday each break the other engine — the database
 * only groups, counts and sums, Carbon does the rest. For the same reason
 * `histories.date` is read as a string and cut with substr: the migration says
 * `->date()` but the column is a timestamp holding midnight, and the model has
 * no cast.
 */
final class DashboardService
{
    /**
     * A queue that has not been touched in this long means no worker is
     * running — the jobs themselves look perfectly healthy while they sit
     * there, so their age is the only thing that gives it away.
     */
    private const STALE_QUEUE_MINUTES = 5;

    /**
     * Spending in one calendar month, next to the month before it.
     *
     * @return array{month: string, total: float, receipts: int, items: int,
     *               averageReceipt: float, previousMonth: string,
     *               previousTotal: float, change: float|null}
     */
    public function spending(?CarbonImmutable $month = null): array
    {
        $month ??= CarbonImmutable::now();
        $previous = $month->subMonth();

        $current = $this->monthTotals($month);
        $before = $this->monthTotals($previous);

        return [
            'month' => $month->format('F Y'),
            'total' => $current['total'],
            'receipts' => $current['receipts'],
            'items' => $current['items'],
            'averageReceipt' => $current['receipts'] > 0
                ? $current['total'] / $current['receipts']
                : 0.0,
            'previousMonth' => $previous->format('F Y'),
            'previousTotal' => $before['total'],
            // No baseline means no comparison. Showing "+100%" against a month
            // with no receipts in it would be an invented fact.
            'change' => $before['total'] > 0.0
                ? ($current['total'] - $before['total']) / $before['total'] * 100
                : null,
        ];
    }

    /**
     * Did the pipeline work? The bot, the queue and the Wallet push all run
     * unattended, so this is the one block that has to be on the page: a
     * receipt stuck in `jobs` is otherwise completely silent.
     *
     * @return array{status: string, lastReceipt: array{filename: string, shop: string, date: string, receivedAt: CarbonImmutable, items: int, total: float}|null,
     *               pendingJobs: int, oldestJobMinutes: int|null, queueStalled: bool,
     *               failedJobs: int, walletEnabled: bool, walletPending: int}
     */
    public function pipeline(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $pendingJobs = $this->tableCount('jobs');
        $failedJobs = $this->tableCount('failed_jobs');
        $oldestJobMinutes = $this->oldestJobMinutes($now);

        $walletEnabled = (bool) config('budgetbakers.enabled')
            && filled(config('budgetbakers.token'));
        $walletPending = $walletEnabled ? $this->walletBacklog() : 0;

        $queueStalled = $oldestJobMinutes !== null
            && $oldestJobMinutes >= self::STALE_QUEUE_MINUTES;

        return [
            'status' => match (true) {
                $failedJobs > 0, $queueStalled => 'error',
                $pendingJobs > 0, $walletPending > 0 => 'warn',
                default => 'ok',
            },
            'lastReceipt' => $this->recentReceipts(1)[0] ?? null,
            'pendingJobs' => $pendingJobs,
            'oldestJobMinutes' => $oldestJobMinutes,
            'queueStalled' => $queueStalled,
            'failedJobs' => $failedJobs,
            'walletEnabled' => $walletEnabled,
            'walletPending' => $walletPending,
        ];
    }

    /**
     * The last few receipts, one row each.
     *
     * `histories` holds one row per line item, so a receipt is a distinct
     * `filename` and has to be grouped back together. Ordering is by when the
     * rows were written rather than by the date on the receipt — an old receipt
     * uploaded today is the one worth looking at.
     *
     * @return list<array{filename: string, shop: string, date: string, receivedAt: CarbonImmutable, items: int, total: float}>
     */
    public function recentReceipts(int $limit = 5): array
    {
        $rows = History::query()
            ->selectRaw('filename, shop_id, COUNT(*) as items, SUM(total) as total, MIN(date) as date, MAX(created_at) as received_at')
            ->whereNotNull('filename')
            ->groupBy('filename', 'shop_id')
            ->orderByRaw('MAX(created_at) DESC')
            ->limit($limit)
            ->get();

        $shops = Shop::pluck('name', 'id');

        return $rows->map(fn ($row): array => [
            'filename' => (string) $row->filename,
            'shop' => (string) ($shops[$row->shop_id] ?? '—'),
            'date' => substr((string) $row->date, 0, 10),
            'receivedAt' => CarbonImmutable::parse($row->received_at),
            'items' => (int) $row->items,
            'total' => (float) $row->total,
        ])->all();
    }

    /**
     * @return array{items: int, total: float, receipts: int}
     */
    private function monthTotals(CarbonImmutable $month): array
    {
        // whereDate, not whereBetween on datetime bounds. The column is a
        // timestamp holding midnight and sqlite compares it as a string, where
        // '2026-09-01' sorts BELOW '2026-09-01 00:00:00' — the first day of the
        // month drops out silently, and only on sqlite.
        $row = History::query()
            ->whereDate('date', '>=', $month->startOfMonth()->toDateString())
            ->whereDate('date', '<=', $month->endOfMonth()->toDateString())
            ->selectRaw('COUNT(*) as items, SUM(total) as total, COUNT(DISTINCT filename) as receipts')
            ->first();

        return [
            'items' => (int) ($row->items ?? 0),
            'total' => (float) ($row->total ?? 0),
            'receipts' => (int) ($row->receipts ?? 0),
        ];
    }

    /**
     * How long the oldest queued job has been waiting, in minutes.
     *
     * `jobs.available_at` rather than `created_at`: a released job is not late
     * until the backoff it asked for has passed.
     */
    private function oldestJobMinutes(CarbonImmutable $now): ?int
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }

        $availableAt = DB::table('jobs')->min('available_at');

        if ($availableAt === null) {
            return null;
        }

        return max(0, (int) CarbonImmutable::createFromTimestamp((int) $availableAt)->diffInMinutes($now));
    }

    /**
     * Receipts that have not settled in Wallet — never pushed, or pushed and
     * failed. A `skipped` row is settled: the shop has no Wallet template and
     * was deliberately not pushed.
     */
    private function walletBacklog(): int
    {
        return History::query()
            ->leftJoin('wallet_records', 'wallet_records.filename', '=', 'histories.filename')
            ->whereNotNull('histories.filename')
            ->where(function ($query): void {
                $query->whereNull('wallet_records.id')
                    ->orWhereNotIn('wallet_records.status', [WalletRecord::CREATED, WalletRecord::SKIPPED]);
            })
            ->distinct()
            ->count('histories.filename');
    }

    /**
     * The queue tables only exist on the database driver, and a dashboard is
     * not worth a 500 on a machine running `QUEUE_CONNECTION=sync`.
     */
    private function tableCount(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }
}
