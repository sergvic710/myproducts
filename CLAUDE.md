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

# BudgetBakers Wallet
php artisan wallet:accounts          # list Wallet accounts + check each shop's settings
php artisan wallet:accounts --category=Groceries
```

## Docker Setup

The app runs in Docker with MariaDB. The DB host is `db`, user `superuser`. Use `docker-compose up` to start all services (app on :80, phpMyAdmin on :8080).

## Deployment (CI/CD)

`.github/workflows/deploy.yml` runs on every push to `main` (and by hand via *Run workflow*):

1. **test** — PHP 8.4, `php artisan test` over `tests/Unit` and the `Receipt`, `Shoplist`, `Wallet`, `CheckHistory` and `Dashboard` feature suites. The paths are listed one by one so the stock Breeze tests stay out (see Known Issues) — which also means **a new suite runs nowhere until it is added to `deploy.yml`**.
2. **deploy** (only if tests pass, GitHub environment `production`) — builds Vite assets, rsyncs `public/build/` to the server, runs `scripts/deploy.sh <sha>` over SSH, then smoke-tests `<APP_URL>/up` (repository variable `APP_URL`, default `https://groceapp.codetandem.com`).

Production: https://groceapp.codetandem.com/

`scripts/deploy.sh` runs in the app checkout on the server: maintenance mode → `git reset --hard <sha>` → `composer install --no-dev` → `migrate --force` → `optimize` + `filament:optimize` → `storage:link` → `queue:restart` → up → `telegraph:set-webhook` (after up, a failure only warns). On failure it brings the app back up. It can also be run by hand: `bash scripts/deploy.sh` deploys `origin/main`.

Repository secrets: `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY`, `DEPLOY_PATH` (absolute path of the checkout), optional `SSH_PORT` (default 22) and `SSH_KNOWN_HOSTS` (otherwise `ssh-keyscan` is used).

The server is managed by HestiaCP and runs PHP directly (no Docker); deploy as the Hestia user that owns the site (PHP-FPM runs as that user, so no permission fixes are needed). PHP must be 8.4+ to satisfy `composer.lock`. Hestia ships several PHP versions and a per-user Composer, so repository variables `PHP_BIN` (e.g. `/usr/bin/php8.4`) and `COMPOSER_BIN` (e.g. `/home/<user>/.composer/composer`) pin them; the defaults are `php` and `composer` from PATH. Run the queue worker as a Hestia cron job every minute: `cd <DEPLOY_PATH> && /usr/bin/php8.4 artisan queue:work --stop-when-empty --max-time=55`. One-time server setup: clone the repo to `DEPLOY_PATH` with a remote the server can `git fetch` (deploy key), create `.env` (`APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, DB, `QUEUE_CONNECTION=database`, …), add the CI public key to the deploy user's `authorized_keys`.

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
4. `ProcessReceipt` then dispatches `app/Jobs/PushReceiptToWallet.php`, which creates one expense in BudgetBakers Wallet. It is a separate job on purpose: the receipt is already committed, so a Wallet outage, an expired token or an unconfigured shop can never fail or roll back a receipt that was read correctly.

**Constrained values:** the parser passes `Shop::pluck('name')` and `Category::pluck('name')` to Claude as JSON-schema enums, so the model can only return shops and categories that already exist. Unknown categories fall back to `default` (`config/receipts.php`); an unknown shop raises `ReceiptParseException`. Units (`kpl`/`kg`/`l`) are the one thing still auto-created.

**Idempotency:** `histories.filename` is the dedupe key. `Handle`, `ProcessReceipt`, and `ImportHistoryService::existRecord()` all check it, and `saveReceipt()` uses `updateOrCreate`.

A **photo is named after its contents** — `photo_<sha1 of the bytes, 32 chars>.jpg`, in `Handle::storePhoto()`. Telegram issues a fresh file id every time the same picture is sent, so the old id-derived name let one receipt be saved twice; hashing the bytes makes the name stable across re-sends and so fixes every check at once, since they all key on `filename`. The photo has to be downloaded before it can be hashed, so the duplicate is caught after the download rather than before it. Documents keep their own name, which is already stable — the residual gap is the same PDF arriving under two different names.

**Deprecated, still on disk:** `app/Services/Parser/Prisma.php` (pdfparser regex), `app/Services/Parser/Lidl.php` (OCR Space), `app/Services/AiService.php` (Mistral). Nothing calls them.

**Telegram bot** is powered by `defstudio/telegraph`. The webhook handler is `App\Bot\Handle`. Documents and photos sent to the bot are stored to `storage/app/private/bot/docs/` (the `local` disk is rooted at `storage/app/private`) and queued for reading.

A command is simply a public method on `Handle` — nothing registers it. `bot:commands` only advertises the list to Telegram for autocomplete. Callback buttons dispatch the same way, to a public method named by the button's `action`, which is why `/snooze` also exists as a typed command.

**Two kinds of keyboard, and they do not mix.** The menu under the message box (`Handle::menu()`, buttons `Shopping list`, `Receipt` and `Check history`) is a *reply* keyboard: it belongs to the chat, survives every later message, and its taps arrive as ordinary text equal to the label — which is why `handleChatMessage()` matches them against `MENU_*` constants rather than treating them as commands. The shopping list's per-item buttons are an *inline* keyboard, which belongs to one message. A message carries only one `reply_markup`, so the two can never ride together; the menu is installed by `/start` and `/receipt` and simply persists while the list uses its own buttons.

`/receipt` only asks for a file. Uploading has never needed a command — a PDF or photo sent to the bot is read either way — so the command exists for discoverability, not to gate the pipeline.

**BudgetBakers Wallet** — one receipt becomes one expense transaction: amount is the receipt total (negative), date is the receipt date, payee and category come from the shop.

*Wallet exposes no template API.* Templates exist only inside the Wallet apps — the MCP server's 20 tools and the REST OpenAPI spec have no template tool and no `/templates` path. So the `shops` row **is** the template: `wallet_account`, `wallet_category`, `wallet_payee`, editable in the shop CRUD and the Filament panel. A shop with an empty `wallet_account` is saved as usual and simply never pushed — you get a Telegram warning, not a failure.

Wallet also runs an MCP server (`https://mcp.wallet.budgetbakers.com`), but it needs OAuth 2.1 + PKCE (no `client_credentials` grant, so a queue worker cannot mint its own token) or a JSON-RPC handshake to reach exactly the three calls we need. `app/Services/Wallet/BudgetBakersClient.php` uses the REST API instead — same data, same token, same permissions.

Setup: generate a personal token at `web.budgetbakers.com/settings/mcp-server` and **enable the `records.create` scope there** — tokens are read-only by default and every push otherwise comes back `create permission not granted for records`. Put it in `BUDGETBAKERS_TOKEN`; `BUDGETBAKERS_ENABLED=false` or an empty token turns the whole thing off. Requires a Wallet Premium subscription. Note Wallet's own sync lag is up to 15 minutes, so a new transaction may not reach the mobile app immediately.

`wallet_records` is the push ledger, one row per receipt keyed by the same `filename` as `histories`. The row is claimed before the API call, so a retried job can tell a receipt that already reached Wallet (`created`/`skipped`) from one that never did.

**Checking the other direction (`/checkhistory`)** — `app/Services/Wallet/ReconciliationService.php` pulls Groceries expenses out of Wallet and asks whether a receipt exists here for that date and shop. Missing ones are a receipt to upload; a payee matching no shop is a shop to configure, so they are reported apart.

*Two filter shapes on one endpoint.* `GET /v1/api/records` wants `categoryId` as a **bare uuid** and `recordDate` **prefixed** `gte.`. Prefix the category `eq.` and the API answers **200 with zero records** — silently, which reads as "you bought nothing". `recordType=expense` is bare too, and filtering it server-side is what stops a refund being reported as a forgotten receipt. `limit` caps at 200; page on `nextOffset`, and stop when it fails to advance as well as when it is null.

*The payee is the only link back to a shop.* The push writes `wallet_payee ?: name`, so the reverse map unions both columns, lowercased, in two passes — an explicit `wallet_payee` has to beat another shop's incidental name. Neither column is unique, so a collision goes to the lowest shop id and is reported rather than swallowed.

*Receipts are counted, not merely looked for.* A receipt is a distinct `filename` (`histories` is one row per line item), and each day's receipts are spent one per expense — two trips to one shop on one day with a single receipt saved is one receipt missing, which a plain `exists()` cannot see. Amounts are deliberately never compared.

`/checkhistory` gates on the token alone and **ignores `BUDGETBAKERS_ENABLED`**: that flag stops *writing*, and a read is at its most useful precisely when pushing is off.

*Beware `histories.date`* — the migration says `->date()` but the column is a `timestamp` holding midnight, and the model has no cast, so it reads back `"2026-09-09 00:00:00"`. Use `whereDate()` and `substr($v, 0, 10)`; do not "optimise" the grouping into a raw `GROUP BY DATE(...)`, which is MySQL-only and breaks the sqlite suite.

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
| BudgetBakers Wallet REST API | Push receipts to Wallet | `config/budgetbakers.php` |

## Known Issues / Tech Debt

- `Product::$fillable` lists `shop_id` but the `products` table has no such column — passing it throws
- `MetricsService::getTotalSumByCategory()` only assigns `$dateStart`/`$dateEnd` inside `if (!$date)`, so passing a date errors
- The Telegraph webhook has no secret and `allow_messages_from_unknown_chats` is `true`
- `tests/Feature/ExampleTest.php` and `ProfileTest.php` are stock Breeze tests for routes this app no longer has; they fail
- The tests need `pdo_sqlite`. Without it (`php -m | grep sqlite`) the whole suite errors with "could not find driver" — run them in Docker, or install the extension