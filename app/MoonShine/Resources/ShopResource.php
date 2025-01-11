<?php

declare(strict_types=1);

namespace App\MoonShine\Resources;

use Illuminate\Database\Eloquent\Model;
use App\Models\Shop;
use App\MoonShine\Pages\Shop\ShopIndexPage;
use App\MoonShine\Pages\Shop\ShopFormPage;
use App\MoonShine\Pages\Shop\ShopDetailPage;

use MoonShine\Laravel\Resources\ModelResource;
use MoonShine\Laravel\Pages\Page;
use MoonShine\UI\Fields\Text;

/**
 * @extends ModelResource<Shop, ShopIndexPage, ShopFormPage, ShopDetailPage>
 */
class ShopResource extends ModelResource
{
    protected string $model = Shop::class;

    protected string $title = 'Shops';

    /**
     * @return list<Page>
     */
    protected function pages(): array
    {
        return [
            ShopIndexPage::class,
            ShopFormPage::class,
            ShopDetailPage::class,
        ];
    }
    protected function indexFields(): iterable
    {
        return [
            Text::make('Name'),
        ];
    }

    protected function formFields(): iterable
    {
        return [
            Text::make('Name'),
        ];
    }
    /**
     * @param Shop $item
     *
     * @return array<string, string[]|string>
     * @see https://laravel.com/docs/validation#available-validation-rules
     */
    protected function rules(mixed $item): array
    {
        return [];
    }
}
