@extends('layouts.admin')
@section('content')
    @include('admin.biosecurity-rules._index', compact('biosecurity_rules'))
@endsection
