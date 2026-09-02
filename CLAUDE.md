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

**Analytics** — `app/Services/MetricsService.php` aggregates spending by category for the current month.

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