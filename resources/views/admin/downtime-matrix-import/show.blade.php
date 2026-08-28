@extends('layouts.admin')
@section('content')
    @include('admin.downtime-matrix-import._show', compact('downtime_matrix_import', 'categorySummary', 'farmToFarmOrigins', 'farmToFarmDestinations', 'stationaryDestinations'))
@endsection
