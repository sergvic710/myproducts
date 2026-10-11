<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pushing receipts to BudgetBakers Wallet
    |--------------------------------------------------------------------------
    | After a receipt is saved, one expense transaction is created in Wallet.
    | Turn this off and the pipeline behaves exactly as it did before — the
    | receipt is still saved, nothing is sent anywhere.
    |
    | An empty token has the same effect, so tests and dev machines stay quiet
    | without setting anything.
    */
    'enabled' => env('BUDGETBAKERS_ENABLED', true),

    /*
    | Personal access token from web.budgetbakers.com/settings/mcp-server.
    |
    | Tokens are read-only by default: enable the `records.create` scope on the
    | same page, or every push comes back with
    | "create permission not granted for records".
    */
    'token' => env('BUDGETBAKERS_TOKEN'),

    /*
    | The REST API, not the MCP server. Both accept the same token and reach
    | the same data; REST needs no OAuth dance and no JSON-RPC envelope.
    | Paths are appended as /v1/api/...
    */
    'base_url' => env('BUDGETBAKERS_BASE_URL', 'https://rest.budgetbakers.com/wallet'),

    'timeout' => 30,

    /*
    | Account and category ids never change, so their name -> id lookups are
    | cached for a day rather than costing two requests per receipt.
    */
    'cache_ttl' => 86400,

    /*
    | How many pages one paged read will follow. 200 records a page, so ten
    | pages is 2000 records — far past any range worth asking for, and the
    | thing that stops a broken `nextOffset` turning a webhook into a loop.
    */
    'max_pages' => (int) env('BUDGETBAKERS_MAX_PAGES', 10),
];
