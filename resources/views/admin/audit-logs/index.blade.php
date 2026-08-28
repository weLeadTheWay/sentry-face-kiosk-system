@extends('layouts.admin')
@section('content')
    @include('admin.audit-logs._index', compact('modules', 'actions', 'users'))
@endsection
