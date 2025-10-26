<?php

namespace App\Bot;

use App\Services\AiService;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Models\TelegraphBot;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;
use App\Services\Parser\Prisma;

class Handle extends WebhookHandler
{

    public function start()
    {
        $this->reply('Hello.');
    }

    protected function handleUnknownCommand(Stringable $message): void
    {
        $this->reply('Unknown command.');
    }

    protected function handleChatMessage(Stringable $message): void
    {
        if ($this->message->document()) {
//            Log::debug($this->message->document()->filename());
            $doc = $this->message->document();
            if ($doc) {
                /* @var \DefStudio\Telegraph\Models\TelegraphBot $bot */
                $bot = TelegraphBot::fromId(1);

                /** @var DefStudio\Telegraph\DTO\Photo $photo */
//                $file = $bot->store($doc, Storage::disk('public')->path('bot/docs'), $doc->filename());
                $file = $bot->store($doc, Storage::path('bot/docs'), $doc->filename());
//                Log::debug($file);
                if( !empty( $file) ) {
//                    Log::debug(Storage::setVisibility( 'bot/docs/' .$doc->filename(), 'public'));
                    $fileUrl = Storage::url( 'bot/docs/' . $doc->filename());
                    $filePath = Storage::path( 'bot/docs/' . $doc->filename());
                    Log::debug($filePath);

                    $data = Prisma::parse($filePath);
                    Log::debug($data);


//                    $data = AiService::getDataFile($fileUrl, $doc->filename());
//                    if( !empty($data) && is_array($data) ) {
//                        foreach ($data['products'] as $item) {
//                            $this->reply('Product :.' . $item['name']);
//                        }
//                    }
                }
            }
        }
    }
}
