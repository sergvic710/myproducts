@extends('layouts.main')
@section('content')
    @include('unit._form', ['route' => route('unit.store')], ['method' => 'POST'])
@endsection
