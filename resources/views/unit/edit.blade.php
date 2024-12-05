@extends('layouts.main')
@section('content')
    @include('unit._form', ['unit' => $unit, 'route' => route('unit.update', $unit->id), 'method' => 'PUT'])
@endsection
