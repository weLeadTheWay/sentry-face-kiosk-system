@extends('layouts.admin')
@section('content')
    @include('admin.downtime-matrix._index', compact('facilities'))
@endsection
