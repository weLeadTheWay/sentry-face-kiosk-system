@extends('layouts.admin')
@section('content')
    @include('admin.farm-aliases._index', compact('farm_aliases'))
@endsection
