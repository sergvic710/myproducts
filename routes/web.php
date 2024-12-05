<?php

use Illuminate\Support\Facades\Route;

Route::Resource('products', \App\Http\Controllers\Product\ProductController::class);
Route::Resource('category', \App\Http\Controllers\Category\CategoryController::class);
Route::Resource('unit', \App\Http\Controllers\Unit\UnitController::class);
Route::get('/', function () {
    return view('welcome');
});
