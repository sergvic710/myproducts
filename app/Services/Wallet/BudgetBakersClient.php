<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Exceptions\WalletException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Talks to the BudgetBakers Wallet REST API.
 *
 * Wallet also runs an MCP server on the same data, but it needs OAuth or a
 * JSON-RPC handshake to reach exactly these calls, so this uses REST.
 * The token is the same one either way.
 *
 * Like AiReceiptParser, this class only makes the call and throws — retries,
 * logging and user-facing messages belong to the job.
 */
class BudgetBakersClient
{
    /** Records come back paged; 200 is the server's ceiling, 400 above it. */
    private const PAGE_LIMIT = 200;

    public function isConfigured(): bool
    {
        return filled(config('budgetbakers.token'));
    }

    /**
     * Create one transaction and return its Wallet id.
     *
     * The endpoint takes a batch, so a single record is posted as a
     * one-element array and its outcome read back from results[0].
     *
     * @param  array<string, mixed>  $record
     */
    public function createRecord(array $record): string
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->post('/v1/api/records', [$record]));

        $result = $response->json('results.0');

        if (! is_array($result)) {
            throw WalletException::rejected('the response contained no result for the transaction.');
        }

        if (isset($result['error'])) {
            throw WalletException::rejected((string) $result['error']);
        }

        if (! isset($result['id']) || ! is_string($result['id']) || $result['id'] === '') {
            throw WalletException::rejected('the transaction came back without an id.');
        }

        return $result['id'];
    }

    /**
     * Every expense in one category since a date, following the cursor.
     *
     * Two filter shapes on one endpoint, and they are not the same. `categoryId`
     * is a BARE uuid: prefix it `eq.` and the API answers 200 with zero records,
     * silently, which reads as "you bought nothing". `recordDate` is the
     * opposite and needs `gte.` — without it the server applies its own
     * three-month window and says so in `appliedRecordDateFilters`.
     * `recordType` is bare like `categoryId` (`eq.expense` is a 400).
     *
     * Filtering the type here rather than afterwards is what keeps a refund —
     * a Groceries record with a positive amount — from being reported as a
     * receipt somebody forgot to upload.
     *
     * @return list<array<string, mixed>>
     */
    public function expenseRecords(string $categoryId, CarbonImmutable $since): array
    {
        return $this->paginate('/v1/api/records', 'records', [
            'categoryId' => $categoryId,
            'recordDate' => 'gte.'.$since->utc()->toIso8601ZuluString(),
            'recordType' => 'expense',
        ]);
    }

    /**
     * Walk a paged collection until the server stops offering a next offset.
     *
     * Three stops, because only one of them is the normal one. An empty page
     * and a page cap are belt and braces; the one that earns its keep is the
     * offset that fails to advance — `nextOffset` is null on the last page, but
     * a server echoing the same offset back would otherwise spin forever on a
     * stream of perfectly valid 200s.
     *
     * @param  array<string, string|int>  $query
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path, string $key, array $query): array
    {
        $items = [];
        $offset = 0;
        $maxPages = max(1, (int) config('budgetbakers.max_pages'));

        for ($page = 0; $page < $maxPages; $page++) {
            $response = $this->send(fn (PendingRequest $request): Response => $request->get(
                $path,
                $query + ['limit' => self::PAGE_LIMIT, 'offset' => $offset],
            ));

            $batch = $response->json($key, []);

            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $next = $response->json('nextOffset');

            if (! is_int($next) || $next <= $offset) {
                break;
            }

            $offset = $next;
        }

        return $items;
    }

    /**
     * Every account this token can see. Only the wallet:accounts command needs
     * the whole list; the job looks accounts up by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function accounts(): array
    {
        $accounts = $this->send(
            fn (PendingRequest $request): Response => $request->get('/v1/api/accounts', ['limit' => 200])
        )->json('accounts', []);

        return is_array($accounts) ? $accounts : [];
    }

    /**
     * Wallet id of the account with this name, or null when there is none.
     */
    public function findAccountId(string $name): ?string
    {
        return $this->lookup('account', $name, function (string $name): ?string {
            $accounts = $this->send(
                fn (PendingRequest $request): Response => $request->get('/v1/api/accounts', $this->nameFilter($name))
            )->json('accounts', []);

            return $this->matchByName($accounts, $name);
        });
    }

    /**
     * Wallet id of the category with this name, or null when there is none.
     */
    public function findCategoryId(string $name): ?string
    {
        return $this->lookup('category', $name, function (string $name): ?string {
            $categories = $this->send(
                fn (PendingRequest $request): Response => $request->get('/v1/api/categories', $this->nameFilter($name))
            )->json('categories', []);

            // A renamed base category keeps its original `name` and carries the
            // user's wording in `customName`, so both have to be considered.
            return $this->matchByName($categories, $name, ['name', 'customName']);
        });
    }

    /**
     * Accounts and categories are created once and then keep their ids, so a
     * receipt should not spend two requests re-discovering them.
     *
     * A miss is not cached — it usually means the name has yet to be typed
     * correctly, and the next attempt should see the fix straight away.
     */
    private function lookup(string $kind, string $name, callable $resolve): ?string
    {
        $key = sprintf('budgetbakers.%s.%s', $kind, Str::slug($name) ?: md5($name));

        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $id = $resolve($name);

        if ($id !== null) {
            Cache::put($key, $id, (int) config('budgetbakers.cache_ttl'));
        }

        return $id;
    }

    /**
     * Wallet's text filters are prefixed, not plain: a bare `?name=Cash` is
     * rejected with "filter must start with 'eq.', 'contains.', or
     * 'contains-i.' prefix".
     *
     * `contains-i.` rather than `eq.` so that a shop configured with "cash"
     * still finds the account named "Cash" — matchByName() then insists on the
     * whole name matching, so a near miss like "Cash reserve" is not accepted.
     * The limit is raised from the default 30 so a short name that matches many
     * accounts cannot push the exact one off the first page.
     *
     * @return array<string, string|int>
     */
    private function nameFilter(string $name): array
    {
        return ['name' => 'contains-i.'.trim($name), 'limit' => 200];
    }

    /**
     * The `?name=` filter is a substring search rather than an exact match,
     * so the exact name still has to be picked out of the results.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $fields
     */
    private function matchByName(mixed $items, string $name, array $fields = ['name']): ?string
    {
        if (! is_array($items)) {
            return null;
        }

        $wanted = mb_strtolower(trim($name));

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }

            foreach ($fields as $field) {
                if (mb_strtolower(trim((string) ($item[$field] ?? ''))) === $wanted) {
                    return (string) $item['id'];
                }
            }
        }

        return null;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        if (! $this->isConfigured()) {
            throw WalletException::notConfigured();
        }

        try {
            $response = $call($this->request());
        } catch (ConnectionException $e) {
            throw new WalletException('Could not reach BudgetBakers: '.$e->getMessage(), 0, $e);
        }

        // A rate limit is the one status the caller can do something about, so
        // it keeps the server's own Retry-After rather than a guess.
        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');

            throw WalletException::rateLimited(is_numeric($retryAfter) ? (int) $retryAfter : null);
        }

        // 207 means the batch partly failed. With one record in the batch that
        // is a rejected record, and results[0] carries the reason.
        if ($response->failed() && $response->status() !== 207) {
            throw WalletException::httpError($response->status(), $response->body());
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('budgetbakers.base_url'))
            ->withToken((string) config('budgetbakers.token'))
            ->timeout((int) config('budgetbakers.timeout'))
            ->acceptJson();
    }
}
