@extends('layouts.main')


@section('content')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <form class="max-w-md mx-auto" method="get" action="{{ route('history.chart') }}">
        @csrf
        <div class="mb-6 relative">
            <label for="product_id" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Select product </label>
            <select id="product_id" name="product_id" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                @foreach($products as $item)
{{--                    @if( isset($history)  )--}}
{{--                        <option value="{{ $item->id }}" {{ ($history->product_id == $item->id) ? 'selected' : '' }}> {{ $item->name }} {{ $item->product->unit->name }}</option>--}}
{{--                    @else--}}
                        <option value="{{ $item->id }}"> {{ $item->name }} &nbsp; for {{ $item->count }} &nbsp;{{ $item->unit->name }}</option>
{{--                    @endif--}}
                @endforeach
            </select>
        </div>
        <button type="submit" class="mt-3 text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 me-2 mb-2 dark:bg-blue-600 dark:hover:bg-blue-700 focus:outline-none dark:focus:ring-blue-800">Save</button>
    </form>
    @if( isset($dates))
    <div>
        <canvas id="priceChart" width="400" height="200"></canvas>
    </div>

    <script>
        const ctx = document.getElementById('priceChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: {!! json_encode($dates) !!},
                datasets: [{
                    label: 'Price',
                    data: {!! json_encode($priceValues) !!},
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
    @endif
@endsection
