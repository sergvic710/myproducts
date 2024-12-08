@extends('layouts.main')
@section('content')
    @include('shop._form', ['route' => route('shop.store')], ['method' => 'POST'])
@endsection
