# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

GrociesApp is a grocery expense tracking system for Finnish stores (Prisma, Lidl). It combines a Laravel web UI, a Telegram bot for receipt uploads, multiple receipt parsers (PDF + OCR), optional AI-powered extraction (Mistral via Prism), and Google Drive sync.

## Development Commands

```bash
# Start everything concurrently (server, queue, logs, vite)
composer dev

# Individual services
php artisan serve
php artisan queue:listen
npm run dev
npm run build

# Database
php artisan migrate
php artisan tinker

# Tests (sqlite in-memory, no DB service needed)
php artisan test
php artisan test --filter=Receipt        # receipt pipeline only

# Read one receipt by hand (needs ANTHROPIC_API_KEY)
php artisan receipt:parse path/to/receipt.pdf
php artisan receipt:parse path/to/receipt.pdf --save

# Code style
./vendor/bin/pint

# Telegram bot (needs a public HTTPS url, see TELEGRAPH_WEBHOOK_URL)
php artisan telegraph:new-bot        # register a bot token
php artisan telegraph:set-webhook    # point Telegram at this app
php artisan telegraph:debug-webhook  # ask Telegram what it sees
php artisan telegraph:unset-webhook
php artisan bot:commands             # advertise the commands in the client command menu
```

## Docker Setup

The app runs in Docker with MariaDB. The DB host is `db`, user `superuser`. Use `docker-compose up` to start all services (app on :80, phpMyAdmin on :8080).

## Architecture

**Core domain objects:**
- `History` — a line item from a receipt (links Product, Shop, price, date, amount)
- `Product` — a grocery item with Category and Unit
- `Shop` — a store (Prisma, Lidl, …). Shops are looked up by name; the parser can only return a shop that already exists in the table.

**Receipt processing pipeline:**
1. Receipt arrives via Telegram bot (`app/Bot/Handle.php`, PDF or photo) or Google Drive sync (`app/Services/ImportHistoryService.php`). The file is stored and `ProcessReceipt` is dispatched — nothing is parsed in the web request.
2. `app/Jobs/ProcessReceipt.php` (queued) runs `app/Services/Parser/AiReceiptParser.php`, which sends the file to an LLM via Prism and gets back structured JSON. The model reads PDF and images natively, so one parser covers every shop.

   Provider and model live in `config/receipts.php` (`RECEIPT_PROVIDER` + `RECEIPT_MODEL`). Any Prism provider that supports documents, images and structured output works: `anthropic`, `gemini`, `mistral`. Switching is an `.env` change.
3. `app/Repositories/ProductRepository.php::saveReceipt()` writes the `ParsedReceipt` DTO to the DB inside a transaction.

**Constrained values:** the parser passes `Shop::pluck('name')` and `Category::pluck('name')` to Claude as JSON-schema enums, so the model can only return shops and categories that already exist. Unknown categories fall back to `default` (`config/receipts.php`); an unknown shop raises `ReceiptParseException`. Units (`kpl`/`kg`/`l`) are the one thing still auto-created.

**Idempotency:** `histories.filename` is the dedupe key. `Handle`, `ProcessReceipt`, and `ImportHistoryService::existRecord()` all check it, and `saveReceipt()` uses `updateOrCreate`.

**Deprecated, still on disk:** `app/Services/Parser/Prisma.php` (pdfparser regex), `app/Services/Parser/Lidl.php` (OCR Space), `app/Services/AiService.php` (Mistral). Nothing calls them.

**Telegram bot** is powered by `defstudio/telegraph`. The webhook handler is `App\Bot\Handle`. Documents and photos sent to the bot are stored to `storage/app/private/bot/docs/` (the `local` disk is rooted at `storage/app/private`) and queued for reading.

A command is simply a public method on `Handle` — nothing registers it. `bot:commands` only advertises the list to Telegram for autocomplete. Callback buttons dispatch the same way, to a public method named by the button's `action`, which is why `/snooze` also exists as a typed command.

**Two kinds of keyboard, and they do not mix.** The menu under the message box (`Handle::menu()`, buttons `Shopping list` and `Receipt`) is a *reply* keyboard: it belongs to the chat, survives every later message, and its taps arrive as ordinary text equal to the label — which is why `handleChatMessage()` matches them against `MENU_*` constants rather than treating them as commands. The shopping list's per-item buttons are an *inline* keyboard, which belongs to one message. A message carries only one `reply_markup`, so the two can never ride together; the menu is installed by `/start` and `/receipt` and simply persists while the list uses its own buttons.

`/receipt` only asks for a file. Uploading has never needed a command — a PDF or photo sent to the bot is read either way — so the command exists for discoverability, not to gate the pipeline.

**Analytics** — `app/Services/MetricsService.php` aggregates spending by category for the current month.

**Time to buy (`/shoplist`)** — `app/Services/ShoppingListService.php` predicts what is running out from the gaps between purchases, and the bot lists it with a tap-to-skip button per item.

A product needs three purchases on *distinct dates* before it is predictable (`COUNT(DISTINCT date)`, so two lines of one receipt are one trip). Its interval is the **median of the recent gaps**, not the mean: grocery data is full of outliers, and a habit that changes should be followed rather than averaged with its own past. Items are dropped when the interval looks seasonal or the product looks abandoned — both rules are needed, and both are in `config/shoplist.php` along with every other threshold, which are guesses until they meet a year of receipts.

**No date arithmetic happens in SQL.** Production is MariaDB, tests are sqlite, and `DATEDIFF`/`julianday` each break the other engine — the database only groups and counts, Carbon does the rest.

Tapping a button writes a `product_snoozes` row and **never a `History` row**; the bot invents no purchases. Only the day of the tap is stored, and the hide window is derived at read time against the *current* interval — a stored `snoozed_until` would freeze the interval as it was at the tap and drift out of agreement with it. There is deliberately no invalidation code: a purchase on or after the tap moves the due date to at least the end of the snooze window, so a snooze can never outlive the receipt that makes it irrelevant.

`/shoplist` answers only chats that have used the bot before (`Handle::isKnownChat()`). Since unknown chats are allowed and auto-stored, that stops a passer-by, not someone determined — tightening it means an allowlist of chat ids, and that one method is where it would go.

**Frontend** — Blade + Tailwind CSS + Alpine.js + Flowbite. Assets bundled via Vite.

## Key Integrations

| Package | Purpose | Config |
|---|---|---|
| `defstudio/telegraph` | Telegram bot | `config/telegraph.php` |
| `cdsmths/laravel-ocr-space` | OCR for Lidl receipts | `config/ocr-space.php` |
| `prism-php/prism` | AI/LLM (Claude, via Anthropic) | `config/prism.php`, `config/receipts.php` |
| `spatie/laravel-medialibrary` | Product/category images | `config/media-library.php` |
| `yaza/laravel-google-drive-storage` | Cloud receipt sync | `config/filesystems.php` |
| `smalot/pdfparser` | Prisma PDF parsing | — |

## Known Issues / Tech Debt

- `Product::$fillable` lists `shop_id` but the `products` table has no such column — passing it throws
- `MetricsService::getTotalSumByCategory()` only assigns `$dateStart`/`$dateEnd` inside `if (!$date)`, so passing a date errors
- The Telegraph webhook has no secret and `allow_messages_from_unknown_chats` is `true`
- `tests/Feature/ExampleTest.php` and `ProfileTest.php` are stock Breeze tests for routes this app no longer has; they fail