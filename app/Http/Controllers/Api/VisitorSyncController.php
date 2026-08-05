<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VisitorSyncRequest;
use App\Services\VisitorSyncService;
use App\Models\ApiLog;

class VisitorSyncController extends Controller
{
    public function __construct(private VisitorSyncService $syncService)
    {
    }

    public function store(VisitorSyncRequest $request)
    {
        $payload = $request->validated();
        $result = $this->syncService->syncApprovedRequest($payload);

        ApiLog::create([
            'user_id' => null,
            'method' => 'POST',
            'endpoint' => '/api/v1/visitor/sync',
            'request_payload' => $payload,
            'response' => $result,
            'status_code' => $result['success'] ? 200 : 400,
            'created_at' => now(),
        ]);

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
