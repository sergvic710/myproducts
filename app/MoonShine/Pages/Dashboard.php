<?php

declare(strict_types=1);

namespace App\MoonShine\Pages;

use App\Models\History;
use App\MoonShine\Resources\ProductResource;
use App\Services\MetricsService;
use MoonShine\Apexcharts\Components\DonutChartMetric;
use MoonShine\Laravel\Fields\Relationships\BelongsTo;
use MoonShine\Laravel\Pages\Page;
use MoonShine\Contracts\UI\ComponentContract;
use MoonShine\Laravel\TypeCasts\ModelCaster;
use MoonShine\UI\Components\Heading;
use MoonShine\UI\Components\Layout\Column;
use MoonShine\UI\Components\Layout\Grid;
use MoonShine\UI\Components\Table\TableBuilder;
use MoonShine\UI\Fields\Date;
use MoonShine\UI\Fields\ID;
use MoonShine\UI\Fields\Image;
use MoonShine\UI\Fields\Text;

class Dashboard extends Page
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
        return $this->title ?: '';
    }

    /**
     * @return list<ComponentContract>
     */
    protected function components(): iterable
	{
		return [
            Grid::make([
                Column::make(
                    [
                        Heading::make('Last buy product')->h(2),
                        TableBuilder::make()
                            ->castKeyName('id')
                            ->name('my-table')
                            ->fields([
                                Date::make('date')
                                    ->format('d.m.Y'),
//                                Image::make('Image'),
                                BelongsTo::make(
                                    'Product',
                                    'product',
                                    resource: ProductResource::class
                                ),
                            ])
                            ->items(History::limit(10)->orderBy('date', 'desc')->get())
                            ->buttons([])
                        ->cast(new ModelCaster(History::class)),
                    ],
                    colSpan: 6,
                    adaptiveColSpan: 6
                ),
                Column::make(
                    [
                        Heading::make('By category at '.\Illuminate\Support\Facades\Date::now()->format('F'))->h(2),
                        DonutChartMetric::make('11')
                            ->values(MetricsService::getTotalSumByCategory())
                    ],
                    colSpan: 6,
                    adaptiveColSpan: 6
                ),
            ])
        ];
	}
}
