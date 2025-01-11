<?php

declare(strict_types=1);

namespace App\MoonShine\Resources;

use App\Models\History;
use App\MoonShine\Pages\History\HistoryIndexPage;
use App\MoonShine\Pages\History\HistoryFormPage;
use App\MoonShine\Pages\History\HistoryDetailPage;

use MoonShine\Laravel\Fields\Relationships\BelongsTo;
use MoonShine\Laravel\Resources\ModelResource;
use MoonShine\Laravel\Pages\Page;
use MoonShine\UI\Fields\Date;
use MoonShine\UI\Fields\Image;
use MoonShine\UI\Fields\Text;

/**
 * @extends ModelResource<History, HistoryIndexPage, HistoryFormPage, HistoryDetailPage>
 */
class HistoryResource extends ModelResource
{
    protected string $model = History::class;

    protected string $title = 'Histories';

    /**
     * @return list<Page>
     */
    protected function pages(): array
    {
        return [
            HistoryIndexPage::class,
            HistoryFormPage::class,
            HistoryDetailPage::class,
        ];
    }

    protected function indexFields(): iterable
    {
        return [
            Date::make('date')
            ->format('d.m.Y'),
            BelongsTo::make(
                'Shop',
                'shop',
                resource: ShopResource::class
            ),
            BelongsTo::make(
                'Product',
                'product',
                resource: ProductResource::class
            ),
            Text::make('Price'),
            Text::make('Amount'),
//            BelongsTo::make(
//                'Unit',
//                'product',
//                fn($item) => "$item->unit",
//                resource: ProductResource::class
//            ),
            Text::make('Total'),
//            BelongsTo::make(
//                'Unit',
//                'unit',
//                resource: UnitResource::class
//            ),
        ];
    }

    protected function formFields(): iterable
    {
        return [
            Image::make('image')
                ->dir('products'),
            Text::make('Name'),
        ];
    }

    /**
     * @param History $item
     *
     * @return array<string, string[]|string>
     * @see https://laravel.com/docs/validation#available-validation-rules
     */
    protected function rules(mixed $item): array
    {
        return [];
    }
}
