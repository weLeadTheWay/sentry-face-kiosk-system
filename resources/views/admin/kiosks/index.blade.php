@extends('layouts.admin')
@section('content')
    @include('admin.kiosks._index', compact('kiosks'))
@endsection
