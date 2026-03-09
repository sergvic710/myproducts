<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Repositories\ProductRepository;
use App\Services\Parser\Lidl;
use App\Services\Parser\Prisma;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $filePath = Storage::path('bot/docs/222985_68653436.pdf');
        $data = Prisma::parse($filePath);
        Log::debug($data);

        $filePath = Storage::path('bot/docs/2025.10.24_11000300622025102425813.jpg.png');
        $data = Lidl::parse($filePath);
        Log::debug($data);



        $products = ProductRepository::getAllProducts();
        return view('product.index', [
            'products' => $products,
            'title' => 'Products'
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $units = Unit::all();
        $categories = Category::all();
        return view('product.create', [
            'units' => $units,
            'categories' => $categories,
            'title' => 'Create Product'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProductRequest $request)
    {
        $product = Product::create($request->all());
        $product->clearMediaCollection('image');
        if ($request->image) {
            $product
                ->addMedia($request->image)
                ->toMediaCollection('image');
        }

        return redirect()->route('product.index')->with('success', 'Product created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Product $product)
    {
        $units = Unit::all();
        $categories = Category::all();
        return view('product.edit', [
            'product' => $product,
            'units' => $units,
            'categories' => $categories,
            'title' => 'Edit Product'
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProductRequest $request, Product $product)
    {
        $product->update($request->all());
        $product->clearMediaCollection('image');
        if ($request->image) {
            $product
                ->addMedia($request->image)
                ->toMediaCollection('image');
        }
        return redirect()->route('product.index')->with('success', 'Product update successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        $product->delete();
        return redirect()->route('product.index')->with('success', 'Product destroy successfully.');
    }
}
