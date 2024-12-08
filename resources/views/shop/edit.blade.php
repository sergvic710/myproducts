@extends('layouts.main')
@section('content')
    @include('shop._form', ['shop' => $shop, 'route' => route('shop.update', $shop->id), 'method' => 'PUT'])
@endsection
