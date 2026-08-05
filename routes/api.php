<?php

use Illuminate\Support\Facades\Route;

Route::middleware('api.key')->prefix('v1')->group(function () {
    Route::post('/visitor/sync', [\App\Http\Controllers\Api\VisitorSyncController::class, 'store']);
});
