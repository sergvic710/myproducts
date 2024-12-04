<?php

use Illuminate\Support\Facades\Route;

Route::Resource('products', \App\Http\Controllers\Product\ProductController::class);
Route::Resource('category', \App\Http\Controllers\Category\CategoryController::class);
Route::get('/', function () {
    return view('welcome');
});
