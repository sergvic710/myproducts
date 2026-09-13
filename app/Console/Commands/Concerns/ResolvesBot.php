<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use DefStudio\Telegraph\Models\TelegraphBot;

/**
 * Finds the bot a console command should act on.
 *
 * The bot is looked up, not guessed: an older version used TelegraphBot::fromId(1)
 * and broke as soon as the row id was anything but 1. Expects the using command
 * to accept a `--bot=` option.
 */
trait ResolvesBot
{
    private function bot(): ?TelegraphBot
    {
        if ($id = $this->option('bot')) {
            $bot = TelegraphBot::find($id);

            if (! $bot) {
                $this->error("There is no bot with id {$id}.");
                $this->listBots();
            }

            return $bot;
        }

        $bots = TelegraphBot::all();

        if ($bots->isEmpty()) {
            $this->error('No bot is registered. Run: php artisan telegraph:new-bot');

            return null;
        }

        if ($bots->count() > 1) {
            $this->error('There is more than one bot. Choose one with --bot=<id>.');
            $this->listBots();

            return null;
        }

        return $bots->first();
    }

    private function listBots(): void
    {
        $this->table(
            ['id', 'name', 'chats'],
            TelegraphBot::withCount('chats')->get()
                ->map(fn (TelegraphBot $b): array => [$b->id, $b->name, $b->chats_count])
                ->all()
        );
    }
}
