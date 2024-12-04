@extends('layouts.main')
@section('title')
    {{ $title  }}
@endsection
@section('content')
    @include('category._form', ['route' => route('category.store')], ['method' => 'POST'])
@endsection
