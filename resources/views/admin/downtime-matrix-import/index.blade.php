@extends('layouts.admin')
@section('content')
    @include('admin.downtime-matrix-import._index', compact('downtime_matrix_imports'))
@endsection
