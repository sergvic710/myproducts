<?php

namespace App\Bot;

use DefStudio\Telegraph\Handlers\WebhookHandler;
use Illuminate\Support\Stringable;

class Handle extends WebhookHandler
{

    public function start()
    {
        $this->reply('Hello.');
    }

    protected function handleUnknownCommand( Stringable $message) : void
    {
        $this->reply('Unknown command.');

    }

    protected function handleChatMessage(Stringable $message) : void
    {
        $this->reply('Hello.');
    }
}
