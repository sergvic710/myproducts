<?php

declare(strict_types=1);

namespace App\MoonShine\Pages\History;

use App\MoonShine\Resources\ProductResource;
use App\MoonShine\Resources\ShopResource;
use MoonShine\Laravel\Fields\Relationships\BelongsTo;
use MoonShine\Laravel\Pages\Crud\FormPage;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Contracts\UI\FieldContract;
use MoonShine\UI\Fields\Date;
use MoonShine\UI\Fields\Text;
use Throwable;

class HistoryFormPage extends FormPage
{
    /**
     * @return list<ComponentContract|FieldContract>
     */
    protected function fields(): iterable
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
            Text::make('Total'),
        ];
    }

    /**
     * @return list<ComponentContract>
     * @throws Throwable
     */
    protected function topLayer(): array
    {
        return [
            ...parent::topLayer()
        ];
    }

    /**
     * @return list<ComponentContract>
     * @throws Throwable
     */
    protected function mainLayer(): array
    {
        return [
            ...parent::mainLayer()
        ];
    }

    /**
     * @return list<ComponentContract>
     * @throws Throwable
     */
    protected function bottomLayer(): array
    {
        return [
            ...parent::bottomLayer()
        ];
    }
}
