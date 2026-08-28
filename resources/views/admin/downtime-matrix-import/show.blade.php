@extends('layouts.admin')
@section('content')
    @include('admin.downtime-matrix-import._show', compact('downtime_matrix_import', 'farmToFarmRows', 'stationaryRows', 'groupMembers'))
@endsection
