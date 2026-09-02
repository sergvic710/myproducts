<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\ProductRepository;
use App\Services\Parser\AiReceiptParser;
use Illuminate\Console\Command;
use Throwable;

class ParseReceiptCommand extends Command
{
    protected $signature = 'receipt:parse
                            {path : Path to a receipt PDF or image}
                            {--save : Also write the result to the database}';

    protected $description = 'Read a receipt file with Claude and show what was found';

    public function handle(AiReceiptParser $parser, ProductRepository $repository): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $this->info('Reading '.basename($path).' with '.config('receipts.model').' …');

        try {
            $receipt = $parser->parse($path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Shop:  {$receipt->shop}");
        $this->line('Date:  '.$receipt->date->toDateString());
        $this->line(sprintf('Total: %.2f EUR (lines add up to %.2f)', $receipt->total, $receipt->sum()));
        $this->newLine();

        $this->table(
            ['Name', 'Category', 'Amount', 'Unit', 'Unit price', 'Line total', 'Discount'],
            array_map(fn ($item): array => [
                $item->name,
                $item->category,
                $item->amount,
                $item->unit,
                number_format($item->unitPrice, 2),
                number_format($item->price, 2),
                $item->isDiscount ? number_format($item->discountPrice, 2) : '-',
            ], $receipt->items)
        );

        if (abs($receipt->sum() - $receipt->total) > 0.05) {
            $this->warn('The line totals do not match the receipt total. Check the result.');
        }

        if (! $this->option('save')) {
            $this->comment('Nothing was saved. Add --save to write to the database.');

            return self::SUCCESS;
        }

        try {
            $saved = $repository->saveReceipt($receipt, basename($path));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Saved {$saved} items.");

        return self::SUCCESS;
    }
}
