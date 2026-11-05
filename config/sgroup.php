<?php

return [
    /*
    |--------------------------------------------------------------------------
    | S-Etukortti receipt sync (S-ryhmä: Prisma, S-market, Alepa, Sale, …)
    |--------------------------------------------------------------------------
    | Receipts are read from the API behind www.s-etukortti.fi. It is not a
    | documented public API: the app replays the session of a browser login,
    | which is sent to the bot with /sgroup. See CLAUDE.md.
    */
    'enabled' => env('SGROUP_ENABLED', true),

    'base_url' => env('SGROUP_API_URL', 'https://api.s-etukortti.fi/api/v1'),

    /*
    | Prefix for histories.filename, the dedupe key: "s-etukortti:<receiptId>".
    */
    'filename_prefix' => 's-etukortti:',

    /*
    | The first sync, before any S-Etukortti receipt is saved, looks this far back.
    | Later syncs start a few days before the newest saved receipt, because a
    | receipt can reach the archive after a later one.
    */
    'initial_days' => (int) env('SGROUP_INITIAL_DAYS', 60),
    'overlap_days' => 3,

    'timeout' => 20,
];
