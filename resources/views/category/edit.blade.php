@extends('layouts.main')

@section('content')
    @include('category._form', ['category' => $category, 'route' => route('category.update', $category->id), 'method' => 'PUT'])
@endsection
