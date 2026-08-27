@extends('layouts.admin')

@section('content')
    @include('admin.facilities._index', compact('facilities'))
@endsection
