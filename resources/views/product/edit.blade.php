@extends('layouts.main')
@section('content')
    @include('product._form', ['product' => $product, 'route' => route('product.update', $product->id), 'method' => 'PUT'])
@endsection
