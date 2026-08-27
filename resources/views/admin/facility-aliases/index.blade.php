@extends('layouts.admin')

@section('content')
    @include('admin.facility-aliases._index', compact('facility_aliases'))
@endsection
