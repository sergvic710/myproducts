@extends('layouts.main')


@section('content')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <form class="max-w-md mx-auto" method="get" action="{{ route('history.chart') }}">
        @csrf
        <div class="mb-6 relative">
            <label for="product_id" class="block mb-2 text-sm font-medium text-gray-900">Select product </label>
            <select id="product_id" name="product_id" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">
                @foreach($products as $item)
{{--                    @if( isset($history)  )--}}
{{--                        <option value="{{ $item->id }}" {{ ($history->product_id == $item->id) ? 'selected' : '' }}> {{ $item->name }} {{ $item->product->unit->name }}</option>--}}
{{--                    @else--}}
                        <option value="{{ $item->id }}"> {{ $item->name }} &nbsp; for {{ $item->count }} &nbsp;{{ $item->unit->name }}</option>
{{--                    @endif--}}
                @endforeach
            </select>
        </div>
        <button type="submit" class="mt-3 text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 me-2 mb-2 focus:outline-none">Send</button>
    </form>
    @if( isset($dates))
    <div style="height: 400px;">
        <canvas id="priceChart"></canvas>
    </div>

    <script>
        let dates = {!! json_encode($dates) !!};
        let priceValues = {!! json_encode($priceValues) !!};
    </script>
    @endif
@endsection
@push('scripts')
    @vite(['resources/js/chart.js'])
@endpush

