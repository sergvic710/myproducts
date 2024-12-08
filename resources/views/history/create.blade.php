@extends('layouts.main')
@section('content')
    @include('history._form', [
    'route' => route('history.store'),
    'shops' => $shops,
    'method' => 'POST'
    ])
@endsection
