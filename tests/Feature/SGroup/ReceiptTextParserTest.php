<?php

declare(strict_types=1);

namespace Tests\Feature\SGroup;

use App\Services\SGroup\ReceiptTextParser;
use Tests\TestCase;

class ReceiptTextParserTest extends TestCase
{
    public function test_it_reads_every_line_of_a_real_receipt(): void
    {
        $result = (new ReceiptTextParser)->parse($this->fixtureText());

        $this->assertTrue($result->certain);
        $this->assertSame(27.95, $result->total);
        $this->assertCount(11, $result->items);

        $this->assertSame([
            'name' => 'COOP MANGORAHKA 150G',
            'unit' => 'kpl',
            'amount' => 2.0,
            'unit_price' => 0.55,
            'price' => 1.10,
        ], $result->items[0]);

        $this->assertSame([
            'name' => 'BANAANI CHIQUITA',
            'unit' => 'kg',
            'amount' => 0.761,
            'unit_price' => 1.88,
            'price' => 1.43,
        ], $result->items[6]);

        // A name with digits and a percent sign is still one name.
        $this->assertSame('100 % KAURA 6KPL', $result->items[10]['name']);
        $this->assertSame(1.95, $result->items[10]['price']);
    }

    public function test_nothing_below_the_items_is_taken_as_a_product(): void
    {
        $names = array_column((new ReceiptTextParser)->parse($this->fixtureText())->items, 'name');

        $this->assertNotContains('YHTEENSÄ', $names);
        $this->assertNotContains('ARK/VARD 6-23 LA/LÖ 6-23 SU/SÖ 9-23', $names);
    }

    public function test_a_discount_line_makes_it_uncertain(): void
    {
        $text = $this->receipt([
            '*MAITO 1L                             1,29 *',
            '*ALENNUS                             -0,30 *',
        ], '0,99');

        $this->assertFalse((new ReceiptTextParser)->parse($text)->certain);
    }

    public function test_an_unknown_line_among_the_items_makes_it_uncertain(): void
    {
        $text = $this->receipt([
            '*MAITO 1L                             1,29 *',
            '  JOTAIN OUTOA',
        ], '1,29');

        $this->assertFalse((new ReceiptTextParser)->parse($text)->certain);
    }

    public function test_lines_that_do_not_add_up_to_the_total_are_uncertain(): void
    {
        $text = $this->receipt(['*MAITO 1L                             1,29 *'], '2,00');

        $this->assertFalse((new ReceiptTextParser)->parse($text)->certain);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function receipt(array $lines, string $total): string
    {
        return implode("\n", [
            ' *S-MARKET TEST*',
            str_repeat('-', 42),
            ...$lines,
            str_repeat('-', 42),
            '*YHTEENSÄ                            '.$total.' *',
        ]);
    }

    private function fixtureText(): string
    {
        $json = json_decode((string) file_get_contents(base_path('tests/Fixtures/sgroup_receipt.json')), true);

        return $json['data']['receipt']['receiptData'];
    }
}
