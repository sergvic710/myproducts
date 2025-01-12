<?php

namespace App\Services;

use App\Models\History;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

class MetricsService
{
    public static function getTotalSumByCategory( Carbon $date = null ) : array
    {
        if( !$date ) {
            $dateStart = Date::now()->startOfMonth();
            $dateEnd = Date::now()->endOfMonth();
        }
        $histories = History::whereBetween('date', [$dateStart, $dateEnd])->get();

        $totalByCategory = [];
        foreach ($histories as $history) {
            $category = $history->product->category;
            if (isset($totalByCategory[$category->name])) {
                $totalByCategory[$category->name] += $history->total;
            } else {
                $totalByCategory[$category->name] = $history->total;
            }
        }
        return $totalByCategory;
    }
}
