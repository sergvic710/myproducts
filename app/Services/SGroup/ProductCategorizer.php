<?php

declare(strict_types=1);

namespace App\Services\SGroup;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Throwable;

/**
 * Picks a category for each product name on a receipt read without the LLM.
 *
 * A product already in the database keeps its category — that is also what
 * ProductRepository does, since it matches products by name and never touches
 * an existing one. Only names never seen before go to the model, in one small
 * text-only request, so the cost falls towards nothing as the catalogue fills.
 *
 * A failed request is not worth failing the receipt for: the new products get
 * the fallback category and can be fixed in the admin panel.
 */
class ProductCategorizer
{
    /**
     * @param  array<int, string>  $names
     * @return array<string, string> name => category name
     */
    public function categorize(array $names): array
    {
        $names = array_values(array_unique($names));
        $fallback = (string) config('receipts.fallback_category');

        $known = Product::with('category')
            ->whereIn('name', $names)
            ->get()
            ->mapWithKeys(fn (Product $p): array => [$p->name => $p->category?->name ?? $fallback])
            ->all();

        $unknown = array_values(array_diff($names, array_keys($known)));

        return $known + ($unknown === [] ? [] : $this->ask($unknown, $fallback));
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, string>
     */
    private function ask(array $names, string $fallback): array
    {
        $answer = array_fill_keys($names, $fallback);
        $categories = Category::orderBy('name')->pluck('name')->all();

        if ($categories === []) {
            return $answer;
        }

        try {
            $provider = Provider::from((string) config('receipts.provider'));

            $request = Prism::structured()
                ->using($provider, (string) config('receipts.model'))
                ->withSchema(new ObjectSchema(
                    name: 'categories',
                    description: 'A category for each grocery product.',
                    properties: [new ArraySchema('products', 'One entry per product name given.', new ObjectSchema(
                        name: 'product',
                        description: 'A product and its category.',
                        properties: [
                            new StringSchema('name', 'The product name exactly as given.'),
                            new EnumSchema('category', 'The closest category from the list.', $categories),
                        ],
                        requiredFields: ['name', 'category'],
                    ))],
                    requiredFields: ['products'],
                ))
                ->withPrompt(sprintf(
                    "These are product names from a Finnish grocery receipt (Prisma, S-market).\n"
                    ."Give each one the closest category. Use only these categories: %s.\n"
                    ."If nothing fits, use \"%s\".\n\nProducts:\n- %s",
                    implode(', ', $categories),
                    $fallback,
                    implode("\n- ", $names),
                ))
                ->withMaxTokens(4000);

            if ($provider === Provider::Anthropic) {
                $request->withProviderOptions(['use_tool_calling' => true]);
            }

            foreach ($request->asStructured()->structured['products'] ?? [] as $row) {
                $name = is_array($row) ? (string) ($row['name'] ?? '') : '';

                if (array_key_exists($name, $answer) && in_array($row['category'] ?? null, $categories, true)) {
                    $answer[$name] = $row['category'];
                }
            }
        } catch (Throwable $e) {
            Log::warning('Could not categorise new S-Etukortti products; using the fallback.', [
                'error' => $e->getMessage(),
                'products' => $names,
            ]);
        }

        return $answer;
    }
}
