<?php

declare(strict_types=1);

namespace App\Services\SGroup;

use App\Exceptions\SGroupSessionExpiredException;
use App\Jobs\ImportSGroupReceipt;
use App\Models\History;
use App\Models\SGroupSession;
use Carbon\CarbonImmutable;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lists S-Etukortti receipts and queues the ones not saved yet.
 *
 * Listing is one cheap request per month of range, so this runs every 15
 * minutes: it picks receipts up soon after the purchase, and keeps the session
 * in use. Only new receipts cost anything — a detail request and, for an
 * unfamiliar product, an LLM call — and those happen on the queue.
 */
class SGroupSync
{
    /**
     * @return int|null receipts queued, or null when there is no usable session
     */
    public function run(?CarbonImmutable $from = null, bool $notify = true): ?int
    {
        $session = SGroupSession::active();

        if (! $session || ! config('sgroup.enabled')) {
            return null;
        }

        $to = CarbonImmutable::now();
        $from ??= $this->defaultStart();
        $client = new SEtukorttiClient($session);
        $chatId = $notify ? $session->telegraph_chat_id : null;
        $queued = 0;

        try {
            // A month at a time: the list endpoint takes a free range, but a
            // first sync over a long archive should not be one huge answer.
            for ($start = $from->startOfDay(); $start->lte($to); $start = $start->startOfMonth()->addMonth()) {
                $end = $start->endOfMonth()->min($to);

                foreach ($client->receipts($start, $end) as $row) {
                    $id = (string) ($row['receiptId'] ?? '');

                    if ($id === '' || ImportSGroupReceipt::alreadySaved($id)) {
                        continue;
                    }

                    ImportSGroupReceipt::dispatch($id, $chatId);
                    $queued++;
                }
            }
        } catch (SGroupSessionExpiredException $e) {
            $this->expire($session);

            return null;
        }

        return $queued;
    }

    /**
     * Mark the session dead and say so once, in the chat that sent it.
     */
    public function expire(SGroupSession $session): void
    {
        if ($session->expired_at !== null) {
            return;
        }

        $session->forceFill(['expired_at' => now()])->save();

        Log::info('S-Etukortti session expired.', [
            'session' => $session->id,
            'last_ok_at' => $session->last_ok_at?->toIso8601String(),
        ]);

        $chat = $session->telegraph_chat_id ? TelegraphChat::find($session->telegraph_chat_id) : null;

        try {
            $chat?->message(
                "The S-Etukortti session has expired, so new S-ryhmä receipts are not being fetched.\n"
                .'Log in at s-etukortti.fi and send: /sgroup <sessionId> <xsrf-token>'
            )->send();
        } catch (Throwable $e) {
            Log::warning('Could not send the Telegram reply.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A few days before the newest S-Etukortti receipt already saved: a receipt
     * can reach the archive after a later one. With none saved yet, the
     * configured initial window.
     */
    private function defaultStart(): CarbonImmutable
    {
        $latest = History::where('filename', 'like', config('sgroup.filename_prefix').'%')->max('date');

        return $latest
            ? CarbonImmutable::parse(substr((string) $latest, 0, 10))->subDays((int) config('sgroup.overlap_days'))
            : CarbonImmutable::now()->subDays((int) config('sgroup.initial_days'));
    }
}
