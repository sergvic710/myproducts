<?php

namespace App\Services;

use App\Models\History;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

class MetricsService
{
    /**
     * What was spent per category in the month containing $date.
     *
     * Aggregated in SQL rather than by walking the rows: the old version read
     * every History of the month and then touched `product->category` on each
     * one, which is two extra queries per line item.
     *
     * Sums `total`, the line total actually charged, not `price * amount` —
     * `price` is the unit price, so a weighed or discounted line disagrees.
     *
     * @return array<string, float> category name => amount, largest first
     */
    public static function getTotalSumByCategory(?Carbon $date = null): array
    {
        $date = $date ? $date->copy() : Date::now();

        $dateStart = $date->copy()->startOfMonth();
        $dateEnd = $date->copy()->endOfMonth();

        $fallback = (string) config('receipts.fallback_category', 'default');

        $rows = History::query()
            ->join('products', 'products.id', '=', 'histories.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereBetween('histories.date', [$dateStart, $dateEnd])
            ->groupBy('categories.name')
            ->selectRaw('categories.name as name, SUM(histories.total) as total')
            ->get();

        $totalByCategory = [];

        foreach ($rows as $row) {
            // A product with no category still spent money; it is reported
            // under the same name the parser falls back to.
            $name = $row->name ?? $fallback;
            $totalByCategory[$name] = ($totalByCategory[$name] ?? 0) + (float) $row->total;
        }

        arsort($totalByCategory);

        return $totalByCategory;
    }
}
