<?php

namespace App\Http\Controllers\History;

use App\Http\Controllers\Controller;
use App\Http\Requests\History\ChartHistoryRequest;
use App\Http\Requests\History\SearchHistoryRequest;
use App\Http\Requests\History\StoreHistoryRequest;
use App\Http\Requests\History\UpdateHistoryRequest;
use App\Models\History;
use App\Models\Product;
use App\Models\Shop;
use App\Repositories\ProductRepository;
use App\Services\ImportHistoryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

class HistoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Get sort parameters from request
        $sortField = request('sort_by', 'date');
        $sortDirection = request('sort_dir', 'desc');
        
        // Validate sort direction
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }
        
        // Define sortable fields and their corresponding table columns
        $sortableFields = [
            'date' => 'histories.date',
            'shop_id' => 'histories.shop_id',
            'product_name' => 'products.name',  // Add product name sorting
            'product_id' => 'histories.product_id',
            'amount' => 'histories.amount',
            'total' => 'histories.total'
        ];
        
        // Set default sort field if not valid
        if (!array_key_exists($sortField, $sortableFields)) {
            $sortField = 'date';
        }
        
        $histories = History::select('histories.*')
            ->join('products', 'histories.product_id', '=', 'products.id')
            ->with(['product', 'shop'])
            ->orderBy($sortableFields[$sortField], $sortDirection)
            ->paginate(15)
            ->appends([
                'sort_by' => $sortField,
                'sort_dir' => $sortDirection
            ]);
            
        $products = ProductRepository::getAllProducts();
        
        return view('history.index', [
            'histories' => $histories,
            'products' => $products,
            'date' => '',
            'title' => 'Histories',
            'sortField' => $sortField,
            'sortDirection' => $sortDirection
        ]);
    }

    public function search(SearchHistoryRequest $request)
    {
//        $date  = '';
//        $product_id = -1;
        if( $request->method() == 'POST') {
            $builder = History::query();
            if ((int)$request->product_id > 0 ) {
                $builder->where('product_id', $request->product_id);
//                ->orderBy('date', 'desc')
//                ->paginate(15);
            }
            if( $request->date) {
                $date = Carbon::parse($request->date)->format('Y-m-d');
                $builder->where('date', $date);
            }
            $histories = $builder->orderBy('date', 'desc')
                ->paginate(15);
        }
        $products = ProductRepository::getAllProducts();
        return view('history.index',[
            'histories' => $histories,
            'products' => $products,
            'product_id' => $request->product_id,
            'date' => $request->date,
            'title' => 'Histories'
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $shops = Shop::all();
        $products = ProductRepository::getAllProducts();
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

    public function importView()
    {
        $shops = Shop::all();
        return view('history.import',[
            'shops' => $shops
        ]);
    }

    public function import()
    {
        $queued = ImportHistoryService::sync();

        return redirect()->route('history.index')
            ->with('success', "Queued {$queued} receipt(s) for reading.");
    }

    public function chart(ChartHistoryRequest $request)
    {
        $products = ProductRepository::getAllProducts();
        if( $request->product_id) {
            $histories = History::where('product_id', $request->product_id)
                ->orderBy('date', 'desc')
                ->get();

            return view('history.chart',[
                'dates' => $histories->pluck('date'),
                'priceValues' => $histories->pluck('price'),
                'products' => $products
            ]);
        }
        return view('history.chart',[
            'products' => $products
        ]);
    }

}
