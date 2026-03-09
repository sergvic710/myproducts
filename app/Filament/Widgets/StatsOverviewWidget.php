<?php

namespace App\Filament\Widgets;

use App\Models\History;
use App\Models\Product;
use App\Services\MetricsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $totalByCategory = MetricsService::getTotalSumByCategory();
        $monthSpending = array_sum($totalByCategory);

        return [
            Stat::make('Products', Product::count())
                ->icon('heroicon-o-shopping-cart'),
            Stat::make('Purchase records', History::count())
                ->icon('heroicon-o-clock'),
            Stat::make('Spent this month', '€' . number_format($monthSpending, 2))
                ->icon('heroicon-o-banknotes'),
        ];
    }
}
