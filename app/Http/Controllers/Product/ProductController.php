<?php

namespace App\Http\Controllers\Product;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Repositories\ProductRepository;
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

        /** @var Mistral $provider */
        $provider = Prism::provider(\Prism\Prism\Enums\Provider::Mistral);

        $ocrResponse = $provider->ocr(
            'mistral-ocr-latest',
            Document::fromUrl('http://coretest.harvey-rus.ru/upload/222985_68653436.pdf')
        );

        if( !empty( $ocrResponse->toText())) {
            $prompt = $ocrResponse->toText();
            $prompt .= 'Задача: '
                . 'Это  чек из магазина Prisma.
            Он на финском языке. Проанализируй его и выдели в нем продукты. Так же определи к какой категории продуктов относится товар.
            Результат выдай в json  в котором есть столбцы Товар количество или вес Цена за штуку или килограм Сумма Единица измерения.
            В чеке есть так же скидка на товар по возможности ее тоже нужно учесть.
            Зафиксируй дату чека.  Категории продуктов на английском языке. Не выводи лишней информации , только json.';

            $response = Prism::text()
            ->using(Provider::Mistral, 'mistral-small-latest')
            ->withPrompt( $prompt )
            ->asText();

            if( !empty($response->text) ) {
                $text = str_replace(['```json','```'], '', $response->text);
                $data = json_decode($text);
            }
        }


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
