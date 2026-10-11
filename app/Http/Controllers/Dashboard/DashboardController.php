<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Services\MetricsService;
use App\Services\ShoppingListService;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    /** Past this many the "time to buy" block stops being something you glance at. */
    private const SHOPLIST_LIMIT = 8;

    private const RECEIPT_LIMIT = 5;

    public function index(DashboardService $dashboard, ShoppingListService $shoplist): View
    {
        $shoppingList = $shoplist->due();

        return view('dashboard.index', [
            'title' => 'Dashboard',
            'pipeline' => $dashboard->pipeline(),
            'spending' => $dashboard->spending(),
            'byCategory' => MetricsService::getTotalSumByCategory(),
            'shoppingList' => $shoppingList,
            'dueProducts' => array_slice($shoppingList->all(), 0, self::SHOPLIST_LIMIT),
            'receipts' => $dashboard->recentReceipts(self::RECEIPT_LIMIT),
        ]);
    }
}
