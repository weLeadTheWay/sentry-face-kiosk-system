@extends('layouts.admin')
@section('content')
    @include('admin.employee-types._index', compact('employee_types'))
@endsection
