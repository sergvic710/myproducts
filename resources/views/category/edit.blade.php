@extends('layouts.main')
@section('title')
    {{ $title  }}
@endsection
@section('content')
    @include('category._form', ['category' => $category, 'route' => route('category.update', $category->id), 'method' => 'PUT'])
@endsection
