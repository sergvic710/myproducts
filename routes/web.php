<?php

use Illuminate\Support\Facades\Route;

Route::Resource('product', \App\Http\Controllers\Product\ProductController::class);
Route::Resource('category', \App\Http\Controllers\Category\CategoryController::class);
Route::Resource('unit', \App\Http\Controllers\Unit\UnitController::class);
Route::Resource('shop', \App\Http\Controllers\Shop\ShopController::class);
Route::get('/', function () {
    return view('welcome');
});
