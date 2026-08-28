@extends('layouts.admin')

@section('content')
    @include('admin.facilities._index', compact('facility_types', 'facility_categories'))
@endsection
