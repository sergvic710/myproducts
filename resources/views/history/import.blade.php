@extends('layouts.main')
@section('content')
<form class="max-w-md mx-auto" method="post" action="{{ route('history.import-action') }}" enctype="multipart/form-data">
    @csrf
    <div class="relative z-0 w-full mb-5 group">

        <div class="mb-6 relative">
            <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white" for="file">File</label>
            <input class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 dark:text-gray-400 focus:outline-none dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400" id="file" type="file" name="file">
        </div>

        <div class="mb-6 relative">
            <label for="shop_id" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Select shop </label>
            <select id="shop_id" name="shop_id" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                @foreach($shops as $item)
                    @if( isset($history)  )
                        <option value="{{ $item->id }}" {{ ($history->shop_id == $item->id) ? 'selected' : '' }}> {{ $item->name  }}</option>
                    @else
                        <option value="{{ $item->id }}"> {{ $item->name  }}</option>
                    @endif
                @endforeach
            </select>
        </div>

        <button type="submit" class="mt-3 text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 me-2 mb-2 dark:bg-blue-600 dark:hover:bg-blue-700 focus:outline-none dark:focus:ring-blue-800">Save</button>
    </div>
</form>
@if ($errors->any())
    <div class="alert alert-danger">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
@endsection
