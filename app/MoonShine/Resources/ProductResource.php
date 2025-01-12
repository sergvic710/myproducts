<?php

declare(strict_types=1);

namespace App\MoonShine\Resources;

use App\Models\Product;
use App\MoonShine\Pages\Product\ProductIndexPage;
use App\MoonShine\Pages\Product\ProductFormPage;
use App\MoonShine\Pages\Product\ProductDetailPage;


use MoonShine\Laravel\Fields\Relationships\BelongsTo;
use MoonShine\Laravel\Resources\ModelResource;
use MoonShine\Laravel\Pages\Page;
use MoonShine\UI\Fields\Image;
use MoonShine\UI\Fields\Text;

/**
 * @extends ModelResource<Product, ProductIndexPage, ProductFormPage, ProductDetailPage>
 */
class ProductResource extends ModelResource
{
    protected string $model = Product::class;

    protected string $title = 'Products';

    protected string $column = 'name';

    /**
     * @return list<Page>
     */
    protected function pages(): array
    {
        return [
            ProductIndexPage::class,
            ProductFormPage::class,
            ProductDetailPage::class,
        ];
    }

    protected function indexFields(): iterable
    {
        return [
            Image::make('image'),
            BelongsTo::make(
                'Category',
                'category',
                resource: CategoryResource::class
            ),
            Text::make('Name'),
            Text::make('Count'),
            BelongsTo::make(
                'Unit',
                'unit',
                resource: UnitResource::class
            ),
        ];
    }

    protected function formFields(): iterable
    {
        return [
            Image::make('image')
                ->dir('products'),
            BelongsTo::make(
                'Category',
                'category',
                resource: CategoryResource::class
            )
            ->searchable(),
            Text::make('Name'),
            Text::make('Count'),
            BelongsTo::make(
                'Unit',
                'unit',
                resource: UnitResource::class
            ),
        ];
    }

    /**
     * @param Product $item
     *
     * @return array<string, string[]|string>
     * @see https://laravel.com/docs/validation#available-validation-rules
     */
    protected function rules(mixed $item): array
    {
        return [];
    }
}
