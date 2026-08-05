@extends('layouts.admin')
@section('content')
    @include('admin.identity-types._index', compact('identity_types'))
@endsection
