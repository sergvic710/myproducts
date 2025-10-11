<?php

namespace App\Console\Commands;

use DefStudio\Telegraph\Models\TelegraphBot;
use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Console\Command;

class botCommand extends Command
{
    protected $signature = 'bot';

    protected $description = 'Command description';

    public function handle(): void
    {
        /* @var \DefStudio\Telegraph\Models\TelegraphBot $bot */
        $bot = TelegraphBot::fromId(1);

        $chat = $bot->chats()->first();

        $chat->message('Hello')->send();

    }
}
