<?php

declare(strict_types=1);

namespace App\Bot;

use App\DataObjects\DueProduct;
use App\DataObjects\ShoppingList;
use App\Jobs\ProcessReceipt;
use App\Models\History;
use App\Services\ShoppingListService;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Keyboard\ReplyButton;
use DefStudio\Telegraph\Keyboard\ReplyKeyboard;
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
        }

        $document = $this->message?->document();
        // photos() holds the same picture in several sizes, largest last.
        $photo = $this->message?->photos()->last();
        $attachment = $document ?? $photo;

        if ($attachment === null) {
            $this->chat->message('Send me a receipt: a PDF file or a photo.')->send();

            return;
        }

        $filename = $this->filename($document?->filename(), $attachment->id());

        if (History::where('filename', $filename)->exists()) {
            $this->chat->message('This receipt is already saved.')->send();

            return;
        }

        $disk = Storage::disk((string) config('receipts.disk'));
        $directory = $disk->path((string) config('receipts.path'));

        $storedPath = $this->bot->store($attachment, $directory, $filename);

        $this->chat->message('Receipt received. Reading it now…')->send();

        ProcessReceipt::dispatch($storedPath, $filename, $this->chat->id);
    }

    /**
     * Documents keep their own name. Photos have none, so derive a stable name
     * from the Telegram file id — the same photo then always maps to the same
     * filename, which is what makes the pipeline idempotent.
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
            ->row([
                ReplyButton::make(self::MENU_SHOPLIST),
                ReplyButton::make(self::MENU_RECEIPT),
            ])
            ->resize()
            ->persistent();
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
