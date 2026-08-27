@extends('layouts.admin')
@section('content')
    @include('admin.downtime-stationary._index', compact('downtime_stationary_rules'))
@endsection
