<?php
use Illuminate\Support\Facades\Route;

//Route::post('history/import-action', [\App\Http\Controllers\History\HistoryController::class, 'import'])->name('history.import-action');

//use Illuminate\Support\Facades\Route;
//
Route::middleware('auth')->group(function () {
    Route::get('history/import', [\App\Http\Controllers\History\HistoryController::class, 'import'])->name('history.import');
//    Route::post('history/import-action', [\App\Http\Controllers\History\HistoryController::class, 'import'])->name('history.import-action');
    Route::post('history/search', [\App\Http\Controllers\History\HistoryController::class, 'search'])->name('history.search');
    Route::get('history/chart', [\App\Http\Controllers\History\HistoryController::class, 'chart'])->name('history.chart');

    Route::Resource('product', \App\Http\Controllers\Product\ProductController::class);
    Route::Resource('category', \App\Http\Controllers\Category\CategoryController::class);
    Route::Resource('unit', \App\Http\Controllers\Unit\UnitController::class);
    Route::Resource('shop', \App\Http\Controllers\Shop\ShopController::class);
    Route::Resource('history', \App\Http\Controllers\History\HistoryController::class);
    Route::get('/', [\App\Http\Controllers\Product\ProductController::class, 'index'])->name('dashboard');
});

require __DIR__.'/auth.php';
