<?php

declare(strict_types=1);

namespace App\MoonShine\Resources;

use App\Models\History;
use App\MoonShine\Pages\History\HistoryImportPage;
use App\MoonShine\Pages\History\HistoryIndexPage;
use App\MoonShine\Pages\History\HistoryFormPage;
use App\MoonShine\Pages\History\HistoryDetailPage;

use MoonShine\Laravel\Fields\Relationships\BelongsTo;
use MoonShine\Laravel\Resources\ModelResource;
use MoonShine\Laravel\Pages\Page;
use MoonShine\Support\ListOf;
use MoonShine\UI\Components\ActionButton;
use MoonShine\UI\Fields\Date;
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
            HistoryImportPage::class
        ];
    }

    protected function topButtons(): ListOf
    {
        return parent::topButtons()->add(
            ActionButton::make('Import', $this->getPageUrl(HistoryImportPage::class))
//                ->dispatchEvent(AlpineJs::event(JsEvent::TABLE_UPDATED, $this->getListComponentName()))
        );
    }

    protected function filters(): iterable
    {
        return [
            Date::make('Date', 'Date'),
            BelongsTo::make(
                'Shop',
                'shop',
                resource: ShopResource::class
            )->nullable(),
            BelongsTo::make(
                'Product',
                'product',
                resource: ProductResource::class
            )->nullable(),
        ];
    }

    protected function indexFields(): iterable
    {
        return [
        ];
    }

    protected function formFields(): iterable
    {
        return [
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
