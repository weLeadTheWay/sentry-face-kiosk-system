@extends('layouts.admin')

@section('content')
    @include('admin.farms._index', compact('farms'))
@endsection
