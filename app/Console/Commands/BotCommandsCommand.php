<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesBot;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tells Telegram which commands the bot answers, so they autocomplete in the
 * client's menu.
 *
 * A command works the moment its method exists on App\Bot\Handle — this only
 * advertises it. Run once after adding or renaming one.
 */
class BotCommandsCommand extends Command
{
    use ResolvesBot;

    protected $signature = 'bot:commands
                            {--bot= : Bot id, when more than one bot is registered}';

    protected $description = 'Register the bot commands with Telegram, for the client command menu';

    /**
     * @var array<string, string>
     */
    private const COMMANDS = [
        'start' => 'How to use this bot',
        'shoplist' => 'What to buy next, from your purchase history',
        'receipt' => 'Send a receipt for me to read',
        'checkhistory' => 'Wallet expenses with no receipt saved',
    ];

    public function handle(): int
    {
        $bot = $this->bot();

        if (! $bot) {
            return self::FAILURE;
        }

        try {
            $bot->registerCommands(self::COMMANDS)->send();
        } catch (Throwable $e) {
            $this->error('Telegram refused the command list: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach (self::COMMANDS as $command => $description) {
            $this->line("/{$command} — {$description}");
        }

        $this->info(sprintf('Registered %d commands with bot %s.', count(self::COMMANDS), $bot->name));

        return self::SUCCESS;
    }
}
