<?php

declare(strict_types=1);

namespace App\MoonShine\Pages\History;

use App\Models\History;
use App\MoonShine\Resources\ShopResource;
use MoonShine\Laravel\Fields\Relationships\BelongsTo;
use MoonShine\Laravel\Pages\Page;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Laravel\TypeCasts\ModelCaster;
use MoonShine\UI\Components\FormBuilder;
use MoonShine\UI\Fields\File;

class HistoryImportPage extends Page
{
    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            '#' => $this->getTitle()
        ];
    }

    public function getTitle(): string
    {
        return $this->title ?: 'HistoryImportPage';
    }

    /**
     * @return list<ComponentContract>
     */
    protected function components(): iterable
    {
        return [
            FormBuilder::make(
                action: route('history.import-action'),
                fields: [
                    File::make('File')->required(),
                    BelongsTo::make(
                        'Shop',
                        'shop',
                        resource: ShopResource::class
                    ),
                ],
            )
                ->cast(new ModelCaster(History::class)) // приводим к типу History Model
                ->errorsAbove(true)
                ->submit(label: 'Import', attributes: ['class' => 'btn-primary'])
        ];
    }
}
