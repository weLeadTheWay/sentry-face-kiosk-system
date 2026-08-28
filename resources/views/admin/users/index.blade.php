@extends('layouts.admin')

@section('content')
    @include('admin.users._index', compact('roles'))
@endsection
