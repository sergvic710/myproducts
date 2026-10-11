<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Predicting when a product runs out
    |--------------------------------------------------------------------------
    | Every number here is a guess until it has met a year of real receipts.
    | They live in config rather than in the service so they can be tuned from
    | .env after a week of use, without a deploy.
    */

    /*
    | Distinct purchase dates a product needs before it is predictable at all.
    | Two dates give one gap, which is a coincidence, not a habit. Three give
    | two gaps, which is the least that can disagree with itself.
    */
    'min_purchases' => (int) env('SHOPLIST_MIN_PURCHASES', 3),

    /*
    | How many of the most recent gaps feed the median. Shopping habits change;
    | a median over the whole history lags a change by half the dataset.
    */
    'gap_window' => (int) env('SHOPLIST_GAP_WINDOW', 6),

    /*
    | daysSince / interval at which an item stops being "later" and becomes
    | "due soon". 0.75 is the last quarter of its usual cycle.
    */
    'due_soon_factor' => (float) env('SHOPLIST_DUE_SOON_FACTOR', 0.75),

    /*
    | An interval longer than this is seasonal, not routine — strawberries in
    | June are not something to be reminded about in July.
    */
    'max_interval_days' => (int) env('SHOPLIST_MAX_INTERVAL_DAYS', 120),

    /*
    | Past this much of its own interval a product was not forgotten, it was
    | dropped. Without it, a weekly item last bought a year ago sits at the top
    | of the list forever at a ratio of 50. The floor keeps a short-interval
    | product from being written off after a single holiday.
    */
    'stale_factor' => (float) env('SHOPLIST_STALE_FACTOR', 4.0),
    'stale_floor_days' => (int) env('SHOPLIST_STALE_FLOOR_DAYS', 90),

    /*
    | Caps the message and the keyboard together. Telegram allows 4096 chars
    | and around 100 buttons; 30 items keeps both comfortably out of reach.
    */
    'max_items' => (int) env('SHOPLIST_MAX_ITEMS', 30),

    /*
    | Button labels are truncated to this. The full name is on the message line
    | the button's number points at.
    */
    'name_limit' => (int) env('SHOPLIST_NAME_LIMIT', 28),
];
