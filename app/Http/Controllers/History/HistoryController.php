<?php

namespace App\Http\Controllers\History;

use App\Http\Controllers\Controller;
use App\Http\Requests\History\StoreHistoryRequest;
use App\Http\Requests\History\UpdateHistoryRequest;
use App\Models\History;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Facades\Date;

class HistoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $histories = History::all();
        return view('history.index',[
            'histories' => $histories,
            'title' => 'Histories'
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $shops = Shop::all();
        $products = Product::all();
        return view('history.create',[
            'shops' => $shops,
            'products' => $products,
            'title' => 'Buy the product'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreHistoryRequest $request)
    {
        $data = $request->all();
        if( !empty($data['date']) ) {
            $data['date'] = Date::parse($data['date'])->format('Y-m-d');
        }

        History::create($data);

        return redirect()->route('history.index')->with('success', 'Buy created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(History $history)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(History $history)
    {
        $shops = Shop::all();
        $products = Product::all();
        $date = Date::parse($history->date)->format('m/d/Y');
        return view('history.edit', [
            'history' => $history,
            'shops' => $shops,
            'title' => 'Edit buy',
            'products' => $products
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateHistoryRequest $request, History $history)
    {
        $data = $request->all();
        if( !empty($data['date']) ) {
            $data['date'] = Date::parse($data['date'])->format('Y-m-d');
        }
        $history->update($data);
        return redirect()->route('history.index')->with('success', 'Buy created successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(History $history)
    {
        $history->delete();
        return redirect()->route('history.index')->with('success', 'Buy created successfully.');
    }
}
