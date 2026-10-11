<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Checking Wallet spending against saved receipts
    |--------------------------------------------------------------------------
    | Money leaves the account in Wallet and receipts arrive through the bot.
    | This is the comparison between the two: a Groceries expense with no
    | receipt saved for that day and shop is a shop trip nobody photographed.
    */

    /*
    | The Wallet category to reconcile. Looked up by name and cached for a day
    | (BudgetBakersClient::findCategoryId), so this is the name as it appears
    | in Wallet, not an id.
    */
    'category' => env('CHECKHISTORY_CATEGORY', 'Groceries'),

    /*
    | The ranges offered as buttons — and the allowlist the callback checks
    | against, which is the point of keeping them in one place: a stale button
    | from last week cannot ask for 3650 days.
    */
    'ranges' => [7, 14, 30],

    /*
    | Lines in the "no receipt" section before it is truncated, and a hard
    | character stop under Telegram's 4096. Both are needed: the first counts
    | lines, Telegram counts characters, and payee names are arbitrary.
    */
    'max_lines' => 40,

    'char_limit' => 3900,
];
