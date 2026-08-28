@extends('layouts.admin')

@section('content')
    @include('admin.facility-aliases._index', compact('facilities'))
@endsection
