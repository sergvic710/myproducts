<?php

namespace App\Services;

use App\Models\History;
use App\Models\Shop;
use App\Repositories\ProductRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Media\Document;
use Smalot\PdfParser\Encoding\PDFDocEncoding;
use Smalot\PdfParser\Parser;
use Yaza\LaravelGoogleDriveStorage\Gdrive;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\Exceptions\PrismRateLimitedException;

class AiService
{

    public static function getDataFile(string $fileUrl, string $fileName ): array
    {
        $data = [];
        $fileUrl = env('APP_URL') . $fileUrl;
        Log::debug($fileUrl);
        /** @var Mistral $provider */
        $provider = Prism::provider(\Prism\Prism\Enums\Provider::Mistral);

        $ocrResponse = $provider->ocr(
            'mistral-ocr-latest',
//            Document::fromUrl('http://coretest.harvey-rus.ru/upload/222985_68653436.pdf')
            Document::fromUrl($fileUrl)
        );

        if( str_contains($fileUrl, 'png')) {
            Log::debug('>>>> LIDL');
            try {
                if (!empty($ocrResponse->toText())) {
//                sleep(5);
                    $prompt = $ocrResponse->toText();
                    $prompt .= 'Task: '
                        . 'This is a receipt from a LIDL store.
                    It is in Finnish. Analyze it and identify the products. Also determine which product category each item belongs to.
                    Output the result in JSON format. The JSON format is as follows: The "date" field contains the date of the receipt. The "totalPrice" field contains the total price of the receipt.
                    The "products" field is an array of products from the receipt. Each product consists of the following fields: "name" - the name of the product, "category" - the name of the category,
"amount" - the quantity or weight, "unitPrice" - the price per unit of product or weight, "price" - the total price, "unit" - the unit of measurement.
                    The receipt also includes a discount on the product, which may start with the words "Lidl Plus" or "Alennus"; if possible, this should also be taken into account.
                    Product categories should be in English. Do not output any extra information, only the JSON.';

                    $response = Prism::text()
                        ->using(Provider::Mistral, 'mistral-small-latest')
                        ->withPrompt($prompt)
                        ->asText();

                    if (!empty($response->text)) {
                        $text = str_replace(['```json', '```'], '', $response->text);
                        $data = json_decode($text, true);
                        Log::debug($data);
                        if (!empty($data)) {
                            $data['shop_id'] = 2;
                            if (!History::where('filename', $fileName)->exists()) {
//                                ProductRepository::productsSave($data, $fileName);
                            }
                        }
                    }
                }
            } catch (PrismRateLimitedException $e) {
                /** @var ProviderRateLimit $rate_limit */
                foreach ($e->rateLimits as $rate_limit) {
                    Log::debug($rate_limit->limit);
                    Log::debug($rate_limit->remaining);
                }
            }
        } else {
            try {
                if (!empty($ocrResponse->toText())) {
//                sleep(5);
                    $prompt = $ocrResponse->toText();
                    $prompt .= 'Задача: '
                        . 'Это  чек из магазина Prisma.
            Он на финском языке. Проанализируй его и выдели в нем продукты. Так же определи к какой категории продуктов относится товар.
            Результат выдай в json. Формат json такой. Поле date содержит дату чека. Поле totalPrice содержит общую цену чека.
            Поле products это массив продуктов из чека. Каждый product состоит из полей. Поле name - название товара, category - название категории,
            amount - это количество или вес, unitPrice - цена за единицу товара или веса, price - итоговая цена, unit - единица измерения.
            В чеке есть так же скидка на товар по возможности ее тоже нужно учесть.
            Категории продуктов на английском языке. Не выводи лишней информации , только json.';

                    $response = Prism::text()
                        ->using(Provider::Mistral, 'mistral-small-latest')
                        ->withPrompt($prompt)
                        ->asText();

                    if (!empty($response->text)) {
                        $text = str_replace(['```json', '```'], '', $response->text);
                        $data = json_decode($text, true);
                        Log::debug($data);
                        if (!empty($data)) {
                            $data['shop_id'] = 1;
                            if (!History::where('filename', $fileName)->exists()) {
//                                ProductRepository::productsSave($data, $fileName);
                            }
                        }
                    }
                }
            } catch (PrismRateLimitedException $e) {
                /** @var ProviderRateLimit $rate_limit */
                foreach ($e->rateLimits as $rate_limit) {
                    Log::debug($rate_limit->limit);
                    Log::debug($rate_limit->remaining);
                }
                // Log, fail gracefully, etc.
            }
        }
        return $data;
    }

}
