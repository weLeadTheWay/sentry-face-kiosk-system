@extends('layouts.admin')
@section('content')
    @include('admin.downtime-stationary._index', compact('facilities'))
@endsection
