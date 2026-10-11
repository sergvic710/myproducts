<?php

declare(strict_types=1);

namespace Tests\Feature\Receipt;

use App\DataObjects\ParsedReceipt;
use App\Exceptions\ReceiptParseException;
use App\Services\Parser\AiReceiptParser;
use Carbon\CarbonImmutable;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Reading the date off a receipt.
 *
 * A Lidl photo printed "11.09.26" and was saved as 2025-09-11: the model was
 * never told what year it is, and every date example in the prompt said 2025.
 * These pin both halves of the fix — the prompt now carries today's date, and
 * a date in the future is refused outright.
 */
class ReceiptDateTest extends TestCase
{
    public function test_the_prompt_tells_the_model_todays_date(): void
    {
        $prompt = $this->systemPrompt();

        $this->assertStringContainsString('Today is '.CarbonImmutable::now()->toDateString(), $prompt);
    }

    public function test_the_prompt_does_not_anchor_the_year_with_examples(): void
    {
        // The old examples were "21.9.2025", "24/10/2025", "24.10.25", and the
        // model copied that year instead of reading the paper.
        $prompt = $this->systemPrompt();
        $rules = substr($prompt, (int) strpos($prompt, '- The date can be'), 900);

        $this->assertStringNotContainsString('21.9.2025', $rules);
        // The worked example: "11.09.26" -> 2026-09-11, wrapped across lines.
        $this->assertStringContainsString('2026-09-11', $rules);
        $this->assertStringContainsString('day-first', $rules);
    }

    public function test_a_two_digit_year_is_expanded_literally(): void
    {
        $receipt = $this->receipt('2026-09-11');

        $this->assertSame('2026-09-11', $receipt->date->toDateString());
    }

    public function test_a_date_in_the_future_is_refused(): void
    {
        $this->expectException(ReceiptParseException::class);
        $this->expectExceptionMessage('is in the future');

        $this->receipt(CarbonImmutable::now()->addDay()->toDateString());
    }

    public function test_todays_date_is_accepted(): void
    {
        $today = CarbonImmutable::now()->toDateString();

        $this->assertSame($today, $this->receipt($today)->date->toDateString());
    }

    public function test_an_old_receipt_is_still_accepted(): void
    {
        // The opposite mistake — a year read one too low — is indistinguishable
        // from a genuinely late upload, so it must not be rejected here.
        $receipt = $this->receipt('2025-09-11');

        $this->assertSame('2025-09-11', $receipt->date->toDateString());
    }

    public function test_a_missing_date_is_refused(): void
    {
        $this->expectException(ReceiptParseException::class);

        $this->receipt('');
    }

    private function receipt(string $date): ParsedReceipt
    {
        return ParsedReceipt::fromArray([
            'shop' => 'Lidl',
            'date' => $date,
            'total' => 24.90,
            'products' => [],
        ]);
    }

    private function systemPrompt(): string
    {
        $method = new ReflectionMethod(AiReceiptParser::class, 'systemPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke(app(AiReceiptParser::class));
    }
}
