@extends('layouts.main')

@section('content')
    @include('category._form', ['route' => route('category.store')], ['method' => 'POST'])
@endsection
