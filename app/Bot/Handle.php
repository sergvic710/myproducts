<?php

declare(strict_types=1);

namespace App\Bot;

use App\DataObjects\DueProduct;
use App\DataObjects\ReconciliationReport;
use App\DataObjects\ShoppingList;
use App\DataObjects\WalletExpense;
use App\Exceptions\WalletException;
use App\Jobs\ProcessReceipt;
use App\Models\History;
use App\Services\ShoppingListService;
use App\Services\Wallet\BudgetBakersClient;
use App\Services\Wallet\ReconciliationService;
use Carbon\CarbonImmutable;
use DefStudio\Telegraph\DTO\Photo;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Keyboard\ReplyButton;
use DefStudio\Telegraph\Keyboard\ReplyKeyboard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;

class Handle extends WebhookHandler
{
    /**
     * The labels on the menu under the message box.
     *
     * A reply keyboard sends its label as an ordinary message, not a command,
     * so these strings are matched in handleChatMessage(). Changing one here
     * changes what the button does, which is why they are constants.
     */
    private const MENU_SHOPLIST = 'Shopping list';

    private const MENU_RECEIPT = 'Receipt';

    private const MENU_CHECK = 'Check history';

    /**
     * Also where the menu gets installed: a reply keyboard arrives attached to
     * a message and then stays in the chat until it is replaced.
     */
    public function start(): void
    {
        $this->chat
            ->message('Hello. Send me a receipt as a PDF file or as a photo, or use the menu below.')
            ->replyKeyboard($this->menu())
            ->send();
    }

    /**
     * Ask for a receipt.
     *
     * Uploading works without this — a PDF or photo sent to the bot is read
     * whether or not anything was typed first. The command exists so that the
     * feature is discoverable from the menu rather than being folklore.
     */
    public function receipt(string $parameter = ''): void
    {
        if (! $this->isKnownChat()) {
            $this->reply('Unknown command. Send me a receipt as a PDF file or as a photo.');

            return;
        }

        $this->chat
            ->message('Send the receipt now — a PDF file or a photo of it. I will read it and save what is on it.')
            ->replyKeyboard($this->menu())
            ->send();
    }

    /**
     * What the purchase history says is running out.
     *
     * The string parameter is not optional decoration: Telegraph calls a
     * command with the text after it ("/shoplist please"), so a method with no
     * parameter is a fatal error waiting for someone to type one.
     */
    public function shoplist(string $parameter = ''): void
    {
        if (! $this->isKnownChat()) {
            $this->reply('Unknown command. Send me a receipt as a PDF file or as a photo.');

            return;
        }

        $this->render(app(ShoppingListService::class)->due());
    }

    /**
     * Dismiss one item until its next predicted due date.
     *
     * This is the button's callback action. Telegraph can only dispatch a
     * callback to a public method, which means /snooze also exists as a typed
     * command — that is a side effect of the package, not an intention. It is
     * harmless: the id is validated and the chat guard applies either way.
     *
     * Callback parameters arrive as strings, hence the signature.
     */
    public function snooze(string $p = ''): void
    {
        if (! $this->isKnownChat()) {
            $this->reply('Unknown command. Send me a receipt as a PDF file or as a photo.');

            return;
        }

        if (! ctype_digit($p)) {
            $this->reply('Nothing to skip. Send /shoplist to see what is due.');

            return;
        }

        $service = app(ShoppingListService::class);
        $snoozed = $service->snooze((int) $p);

        $this->reply($snoozed === null
            ? 'That item is gone.'
            : sprintf('Skipped %s until %s.', $snoozed->name, $snoozed->dueDate->format('j M')));

        $this->render($service->due());
    }

    /**
     * Groceries paid for in Wallet with no receipt saved here.
     *
     * The parameter is Telegraph's, not ours — a command is always called with
     * whatever was typed after it. The range comes from the buttons.
     */
    public function checkhistory(string $parameter = ''): void
    {
        if (! $this->isKnownChat()) {
            $this->reply('Unknown command. Send me a receipt as a PDF file or as a photo.');

            return;
        }

        $this->chat
            ->message('Wallet against receipts — how far back should I look?')
            ->keyboard($this->rangeKeyboard())
            ->send();
    }

    /**
     * The range buttons' callback action.
     *
     * Public because Telegraph dispatches callbacks to public methods, which
     * means /checkdays exists as a typed command too — the same quirk as
     * /snooze, and harmless for the same reasons. It is not advertised.
     */
    public function checkdays(string $d = ''): void
    {
        if (! $this->isKnownChat()) {
            $this->reply('Unknown command. Send me a receipt as a PDF file or as a photo.');

            return;
        }

        $days = (int) $d;

        // An allowlist rather than a cast, so a button left over from an older
        // message cannot ask for a decade of records.
        if (! in_array($days, $this->ranges(), true)) {
            $this->reply('That range is no longer offered. Send /checkhistory.');

            return;
        }

        // Answer the tap before going near the network, or the button sits
        // there spinning for as long as Wallet takes.
        $this->reply('Checking Wallet…');

        $this->runCheck($days);
    }

    protected function handleUnknownCommand(Stringable $message): void
    {
        $this->reply('Unknown command. Send me a receipt as a PDF file or as a photo.');
    }

    /**
     * Save the attached receipt and hand it to the queue.
     *
     * Nothing is parsed here: reading a receipt takes longer than the ~60s
     * after which Telegram retries the webhook.
     */
    protected function handleChatMessage(Stringable $message): void
    {
        // A reply-keyboard tap is an ordinary message whose text is the label.
        switch (trim((string) $message)) {
            case self::MENU_SHOPLIST:
                $this->shoplist();

                return;
            case self::MENU_RECEIPT:
                $this->receipt();

                return;
            case self::MENU_CHECK:
                $this->checkhistory();

                return;
        }

        $document = $this->message?->document();
        // photos() holds the same picture in several sizes, largest last.
        $photo = $this->message?->photos()->last();
        $attachment = $document ?? $photo;

        if ($attachment === null) {
            $this->chat->message('Send me a receipt: a PDF file or a photo.')->send();

            return;
        }

        $disk = Storage::disk((string) config('receipts.disk'));
        $directory = $disk->path((string) config('receipts.path'));

        // A document carries its own name, which is the same every time the
        // same file is sent, so it can be checked before spending a download.
        if ($document !== null) {
            $filename = $this->filename($document->filename(), $document->id());

            if (History::where('filename', $filename)->exists()) {
                $this->chat->message('This receipt is already saved.')->send();

                return;
            }

            $storedPath = $this->bot->store($attachment, $directory, $filename);
        } else {
            $filename = $this->storePhoto($attachment, $directory);

            if ($filename === null) {
                $this->chat->message('This receipt is already saved.')->send();

                return;
            }

            $storedPath = $directory.'/'.$filename;
        }

        $this->chat->message('Receipt received. Reading it now…')->send();

        ProcessReceipt::dispatch($storedPath, $filename, $this->chat->id);
    }

    /**
     * Download a photo and name it after what is in it, not where it came from.
     *
     * Telegram issues a fresh file id every time the same picture is sent, so a
     * name derived from the id makes a re-send look like a new receipt — that
     * is how two byte-identical Lidl photos ended up counted twice. Hashing the
     * bytes makes the name stable across re-sends, which in turn makes every
     * existing check work, since `filename` is already the dedupe key in
     * histories, wallet_records and ProcessReceipt.
     *
     * Returns null when this exact picture is already saved.
     */
    private function storePhoto(Photo $photo, string $directory): ?string
    {
        // Deterministic, so a retried webhook overwrites its own partial
        // download instead of littering the directory.
        $incoming = 'incoming_'.substr(sha1($photo->id()), 0, 16).'.jpg';
        $incomingPath = $this->bot->store($photo, $directory, $incoming);

        $hash = is_file($incomingPath) ? sha1_file($incomingPath) : false;

        if ($hash === false) {
            // Nothing to hash. Fall back to the old id-derived name rather than
            // dropping a receipt somebody just sent.
            return $this->filename(null, $photo->id());
        }

        $filename = 'photo_'.substr($hash, 0, 32).'.jpg';
        $finalPath = $directory.'/'.$filename;

        if (History::where('filename', $filename)->exists()) {
            @unlink($incomingPath);

            return null;
        }

        // The same bytes may already be on disk from an upload whose parse
        // never finished. Keep that copy and let this one go.
        if (is_file($finalPath)) {
            @unlink($incomingPath);

            return $filename;
        }

        rename($incomingPath, $finalPath);

        return $filename;
    }

    /**
     * Documents keep their own name.
     *
     * The file-id fallback is only reached when a photo cannot be hashed; see
     * storePhoto(), which names a picture after its contents.
     */
    private function filename(?string $documentName, string $fileId): string
    {
        $documentName = $documentName !== null ? trim($documentName) : '';

        if ($documentName !== '') {
            return substr(basename($documentName), -255);
        }

        return 'photo_'.substr(sha1($fileId), 0, 16).'.jpg';
    }

    /**
     * The shopping list is one chat that has used the bot before, not anyone
     * who finds it.
     *
     * Telegram chats are stored on first contact (see the security block in
     * config/telegraph.php), so by the time a command runs the row already
     * exists — what distinguishes a stranger is that it was created by this
     * very request. This stops a passer-by, not someone determined, who need
     * only send a second message. Tightening it further means an allowlist of
     * chat ids, and this is the one place that would change.
     */
    private function isKnownChat(): bool
    {
        return $this->chat->exists && ! $this->chat->wasRecentlyCreated;
    }

    /**
     * Show the list, replacing the previous one when this came from a button.
     *
     * A tap edits the message in place rather than sending another: the list is
     * one living thing, and five taps should not leave five copies of it in the
     * chat. messageId and callbackQueryId are typed properties that are only
     * initialised on the callback path, so isset() is the test, not null.
     */
    private function render(ShoppingList $list): void
    {
        $text = $this->format($list);
        $keyboard = $this->keyboard($list);

        if (isset($this->callbackQueryId)) {
            $this->chat->edit($this->messageId)->message($text)->keyboard($keyboard)->send();

            return;
        }

        $this->chat->message($text)->keyboard($keyboard)->send();
    }

    /**
     * Same edit-in-place rule as render(), for output that is already a string.
     */
    private function renderText(string $text): void
    {
        if (isset($this->callbackQueryId)) {
            $this->chat->edit($this->messageId)->message($text)->keyboard($this->rangeKeyboard())->send();

            return;
        }

        $this->chat->message($text)->keyboard($this->rangeKeyboard())->send();
    }

    private function format(ShoppingList $list): string
    {
        if ($list->candidateCount === 0) {
            return 'Nothing to predict yet. I need three purchases of a product on three '
                .'different days before I can guess when it runs out.';
        }

        if ($list->isEmpty()) {
            $text = sprintf('Nothing is due right now. Tracking %d products.', $list->candidateCount);

            return $list->snoozedCount > 0
                ? $text.sprintf(' %d skipped for now.', $list->snoozedCount)
                : $text;
        }

        $lines = ['<b>Time to buy</b>'];
        $number = 0;

        if ($list->overdue !== []) {
            $lines[] = '';
            $lines[] = '<b>Overdue</b>';

            foreach ($list->overdue as $item) {
                $lines[] = $this->line(++$number, $item);
            }
        }

        if ($list->dueSoon !== []) {
            $lines[] = '';
            $lines[] = '<b>Due soon</b>';

            foreach ($list->dueSoon as $item) {
                $lines[] = $this->line(++$number, $item);
            }
        }

        $lines[] = '';
        $lines[] = 'Tap an item to skip it until next time.';

        if ($list->snoozedCount > 0) {
            $lines[] = sprintf('%d skipped for now.', $list->snoozedCount);
        }

        return implode("\n", $lines);
    }

    private function line(int $number, DueProduct $item): string
    {
        $when = $item->isOverdue()
            ? sprintf('%d days over', $item->daysOverdue())
            : sprintf('in %d days', $item->daysUntilDue());

        if ($item->isOverdue() && $item->daysOverdue() === 0) {
            $when = 'due today';
        }

        return sprintf(
            '%d. %s — %s, usually every %d',
            $number,
            $this->escape($item->name),
            $when,
            $item->intervalDays,
        );
    }

    /**
     * One button per item, then a Refresh that also keeps the keyboard from
     * ever being empty — an edit with no buttons is not a clean way to clear
     * one, and this way the empty list needs no special case.
     *
     * Only the product id travels in the callback data. Telegram allows 64
     * bytes; a name in there would both risk that limit and go stale.
     */
    private function keyboard(ShoppingList $list): Keyboard
    {
        $keyboard = Keyboard::make();
        $limit = (int) config('shoplist.name_limit');

        foreach ($list->all() as $index => $item) {
            // Button labels are not parsed as HTML, so the raw name goes here.
            $keyboard = $keyboard->row([
                Button::make(sprintf('%d. %s', $index + 1, Str::limit($item->name, $limit)))
                    ->action('snooze')
                    ->param('p', $item->productId),
            ]);
        }

        return $keyboard->row([Button::make('Refresh')->action('shoplist')]);
    }

    /**
     * The two buttons pinned under the message box.
     *
     * This is a reply keyboard, not the inline one the shopping list uses: it
     * belongs to the chat rather than to a message, so it survives every later
     * message and does not compete with the per-item buttons. A message can
     * carry only one of the two, which is why the list never re-sends this.
     */
    private function menu(): ReplyKeyboard
    {
        return ReplyKeyboard::make()
            // Two rows rather than three across: "Check history" is the long
            // label, and three on one line wraps to unreadable on a phone.
            ->row([
                ReplyButton::make(self::MENU_SHOPLIST),
                ReplyButton::make(self::MENU_RECEIPT),
            ])
            ->row([
                ReplyButton::make(self::MENU_CHECK),
            ])
            ->resize()
            ->persistent();
    }

    /**
     * @return list<int>
     */
    private function ranges(): array
    {
        return array_values(array_map(intval(...), (array) config('checkhistory.ranges')));
    }

    private function rangeKeyboard(): Keyboard
    {
        return Keyboard::make()->row(array_map(
            static fn (int $days): Button => Button::make($days.' days')
                ->action('checkdays')
                ->param('d', $days),
            $this->ranges(),
        ));
    }

    /**
     * Note this asks isConfigured(), not budgetbakers.enabled. That flag turns
     * *pushing* off, and this reads — in fact a check is at its most useful
     * exactly when pushing is off, because then it is the only thing telling
     * you what Wallet knows that the app does not.
     */
    private function runCheck(int $days): void
    {
        if (! app(BudgetBakersClient::class)->isConfigured()) {
            $this->renderText('Wallet is not connected. Set BUDGETBAKERS_TOKEN.');

            return;
        }

        try {
            $report = app(ReconciliationService::class)->check($days);
        } catch (WalletException $e) {
            Log::warning('Could not check Wallet history.', ['error' => $e->getMessage()]);

            $this->renderText('Could not check Wallet: '.$this->escape($e->getMessage()));

            return;
        }

        $this->renderText($this->formatReport($report));
    }

    private function formatReport(ReconciliationReport $report): string
    {
        $since = $report->since->format('j M');

        if ($report->total() === 0) {
            return sprintf('No Groceries expenses in Wallet since %s.', $since);
        }

        if ($report->isClean()) {
            return sprintf('All %d Groceries expenses since %s have a receipt.', $report->total(), $since);
        }

        $lines = [
            '<b>Wallet against receipts</b>',
            sprintf(
                'Since %s — %d Groceries expenses, %d with a receipt.',
                $since,
                $report->total(),
                count($report->matched),
            ),
        ];

        if ($report->missing !== []) {
            $lines[] = '';
            $lines[] = sprintf(
                '<b>No receipt (%d) — %.2f EUR</b>',
                count($report->missing),
                $report->missingTotal(),
            );

            $seen = [];
            $max = (int) config('checkhistory.max_lines');

            foreach (array_slice($report->missing, 0, $max) as $expense) {
                $key = $expense->shopId.'|'.$expense->date;
                $seen[$key] = ($seen[$key] ?? 0) + 1;

                $lines[] = $this->missingLine($expense, $seen[$key]);
            }

            if (count($report->missing) > $max) {
                $lines[] = sprintf('…and %d more.', count($report->missing) - $max);
            }
        }

        if ($report->unknownShop !== []) {
            $lines[] = '';
            $lines[] = sprintf('<b>Unknown payee (%d)</b>', count($report->unknownShop));

            foreach ($report->unknownByPayee() as $row) {
                $lines[] = sprintf(
                    '%s — %d expenses, %.2f EUR',
                    $this->escape($row['payee']),
                    $row['count'],
                    $row['total'],
                );
            }

            $lines[] = 'Set a Wallet payee on the matching shop, or add the shop.';
        }

        foreach ($report->ambiguousPayees as $payee) {
            $lines[] = sprintf('"%s" is claimed by two shops — check Shops.', $this->escape($payee));
        }

        if ($report->matched !== []) {
            $lines[] = '';
            $lines[] = sprintf('%d matched a receipt.', count($report->matched));
        }

        return $this->fit($lines);
    }

    private function missingLine(WalletExpense $expense, int $nthThatDay): string
    {
        return sprintf(
            '%s — %s — %.2f%s',
            CarbonImmutable::parse($expense->date)->format('j M'),
            $this->escape($expense->label()),
            $expense->amount,
            // Two expenses at one shop on one day is not a duplicate report,
            // it is a second trip. Saying so stops it reading as a bug.
            $nthThatDay > 1 ? sprintf(' (%d%s that day)', $nthThatDay, $this->ordinal($nthThatDay)) : '',
        );
    }

    private function ordinal(int $n): string
    {
        return match (true) {
            $n % 100 >= 11 && $n % 100 <= 13 => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };
    }

    /**
     * Telegram counts characters and max_lines counts lines, so the line cap
     * alone cannot promise anything — payee names are whatever Wallet holds.
     * Drop lines off the end until it fits, keeping the tail note honest.
     *
     * @param  list<string>  $lines
     */
    private function fit(array $lines): string
    {
        $limit = (int) config('checkhistory.char_limit');
        $text = implode("\n", $lines);

        while (mb_strlen($text) > $limit && count($lines) > 3) {
            array_splice($lines, -1);
            $text = implode("\n", $lines)."\n…trimmed to fit.";
        }

        return $text;
    }

    /**
     * Messages go out with HTML parse mode (config/telegraph.php), and product
     * names are whatever the receipt said — "Ben & Jerry's" is a 400 from
     * Telegram if it is not escaped. Button labels must NOT go through this.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
