<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\Wallet\BudgetBakersClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Wallet has no template API to browse, so this is how the names that go on a
 * shop row are found: it prints the accounts and categories the token can see,
 * then checks every shop's settings against them.
 */
class WalletAccountsCommand extends Command
{
    protected $signature = 'wallet:accounts
                            {--category= : Also look up one category by name}';

    protected $description = 'List the BudgetBakers Wallet accounts, and check each shop\'s Wallet settings';

    public function handle(BudgetBakersClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('BUDGETBAKERS_TOKEN is not set. Generate one at web.budgetbakers.com/settings/mcp-server');

            return self::FAILURE;
        }

        try {
            $accounts = $client->accounts();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($accounts === []) {
            $this->warn('This token can see no accounts.');

            return self::FAILURE;
        }

        $this->info('Wallet accounts');
        $this->table(
            ['Name', 'Currency', 'Type', 'Id'],
            array_map(fn (array $a): array => [
                $a['name'] ?? '',
                $a['currencyCode'] ?? '',
                $a['accountType'] ?? '',
                $a['id'] ?? '',
            ], $accounts)
        );

        if ($category = $this->option('category')) {
            $id = $client->findCategoryId((string) $category);

            $id === null
                ? $this->warn("No Wallet category named \"{$category}\".")
                : $this->line("Category \"{$category}\" → {$id}");
        }

        $this->newLine();
        $this->info('Shops');

        $names = array_map(fn (array $a): string => mb_strtolower((string) ($a['name'] ?? '')), $accounts);

        $rows = Shop::orderBy('name')->get()->map(function (Shop $shop) use ($names): array {
            $status = match (true) {
                ! $shop->hasWalletTemplate() => 'not pushed to Wallet',
                ! in_array(mb_strtolower($shop->wallet_account), $names, true) => 'unknown account',
                default => 'ok',
            };

            return [$shop->name, $shop->wallet_account ?? '', $shop->wallet_category ?? '', $status];
        })->all();

        $this->table(['Shop', 'Wallet account', 'Wallet category', 'Status'], $rows);

        $this->newLine();
        $this->line('Set these on a shop at /shop, or in the Filament panel.');

        return self::SUCCESS;
    }
}
