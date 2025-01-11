<?php

declare(strict_types=1);

namespace App\MoonShine\Resources;

use Illuminate\Database\Eloquent\Model;
use App\Models\Unit;
use App\MoonShine\Pages\Unit\UnitIndexPage;
use App\MoonShine\Pages\Unit\UnitFormPage;
use App\MoonShine\Pages\Unit\UnitDetailPage;

use MoonShine\Laravel\Resources\ModelResource;
use MoonShine\Laravel\Pages\Page;
use MoonShine\UI\Fields\Image;
use MoonShine\UI\Fields\Text;

/**
 * @extends ModelResource<Unit, UnitIndexPage, UnitFormPage, UnitDetailPage>
 */
class UnitResource extends ModelResource
{
    protected string $model = Unit::class;

    protected string $title = 'Units';
    protected string $column = 'name';

    /**
     * @return list<Page>
     */
    protected function pages(): array
    {
        return [
            UnitIndexPage::class,
            UnitFormPage::class,
            UnitDetailPage::class,
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
     * @param Unit $item
     *
     * @return array<string, string[]|string>
     * @see https://laravel.com/docs/validation#available-validation-rules
     */
    protected function rules(mixed $item): array
    {
        return [];
    }
}
