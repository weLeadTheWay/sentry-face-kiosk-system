@extends('layouts.admin')
@section('content')
    @include('admin.downtime-matrix._index', compact('downtime_matrix_rules'))
@endsection
