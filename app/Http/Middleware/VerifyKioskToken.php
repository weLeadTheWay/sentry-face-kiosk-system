<?php

namespace App\Http\Middleware;

use App\Models\KioskDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyKioskToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-KIOSK-TOKEN');

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Missing X-KIOSK-TOKEN header',
            ], 401);
        }

        $kiosk = KioskDevice::where('kiosk_token', $token)->first();

        if (!$kiosk) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid kiosk token',
            ], 401);
        }

        $request->merge(['verified_kiosk' => $kiosk]);
        $request->attributes->add(['kiosk' => $kiosk]);

        return $next($request);
    }
}
