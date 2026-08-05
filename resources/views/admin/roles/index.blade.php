@extends('layouts.admin')
@section('content')
    @include('admin.roles._index', compact('roles'))
@endsection
