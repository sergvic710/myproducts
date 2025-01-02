<?php

namespace App\Services;

use App\Models\Shop;
use App\Repositories\ProductRepository;
use Smalot\PdfParser\Encoding\PDFDocEncoding;
use Smalot\PdfParser\Parser;

class ImportHistoryService
{

    public static function import(string $shopId,string $filePath)
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);

        $text = $pdf->getText();

        $data = $pdf->getPages()[0]->getDataTm();

        $cart = [
            'shop_id' => (int)$shopId
        ];
        $matches = [];
        preg_match('/\b\d{1,2}\.\d{1,2}\.\d{4}\b/m', $data[7][1], $matches);
        if( isset($matches[0]) ) {
            $cart['date'] = $matches[0];
        }
        $i = 11;
        do {
            $ext = false;
            if( isset($data[$i][1]) ) {
                $res = preg_match_all('/^(.+?)\s{2,}([\d,]+) $/m', $data[$i][1], $matches, PREG_SET_ORDER, 0);
                if( $res && isset($matches[0][1]) && isset($matches[0][2]) ) {
                    $name = $matches[0][1];
                    $price = (float)str_replace(',', '.', $matches[0][2]);
                }
                $amount = 1;
                $unit = 'kpl';
                $res = preg_match_all('/^\s*(\d+(?:\,\d+)?)\s*KG\s*(\d+(?:\,\d+)?)\s*€\/KG/', $data[$i+1][1], $matches, PREG_SET_ORDER, 0);
                if( $res && isset($matches[0][1]) && isset($matches[0][2])) {
                    $amount = (float)str_replace(',', '.', $matches[0][1]);
                    $priceUnit = (float)str_replace(',', '.', $matches[0][2]);
                    $unit = 'kg';
                    $ext = true;
                }
                $res = preg_match_all('/^\s*(\d+(?:\,\d+)?)\s*KPL\s*(\d+(?:\,\d+)?)\s*€\/KPL/', $data[$i+1][1], $matches, PREG_SET_ORDER, 0);
                if( $res && isset($matches[0][1]) && isset($matches[0][2])) {
                    $amount = (float)str_replace(',', '.', $matches[0][1]);
                    $priceUnit = (float)str_replace(',', '.', $matches[0][2]);
                    $unit = 'kpl';
                    $ext = true;
                }
                $item = [
                    'name' => $name,
                    'price' => $price,
                    'amount' => $amount ?? null,
                    'priceUnit' => $priceUnit ?? null,
                    'unit' => $unit
                ];
                $cart['products'][] = $item;
                if( $ext ) {
                    $i++;
                }
            }
            $i++;
            $aa=10;

        }while( !str_contains($data[$i][1], '-------'));

        if( !empty( $cart['products'])) {
            ProductRepository::productsSave($cart);
        }
    }
}
