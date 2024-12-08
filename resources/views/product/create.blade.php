@extends('layouts.main')
@section('content')
    @include('product._form', [
    'route' => route('product.store'),
    'categories' => $categories,
    'units' => $units,
    'method' => 'POST'
    ])
@endsection
