<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class GenerateDeviceId
{
    public function handle(Request $request, Closure $next)
    {
        // Generate or retrieve device ID from request header
        $deviceId = $request->header('X-Device-ID');

        if (!$deviceId) {
            // Generate from User-Agent and Accept-Language
            $deviceId = $this->generateDeviceId($request);
        }

        // Store in request for use in controllers
        $request->merge(['device_id' => $deviceId]);

        return $next($request);
    }

    private function generateDeviceId(Request $request): string
    {
        $userAgent = $request->userAgent() ?? 'unknown';
        $language = $request->header('Accept-Language') ?? 'unknown';

        return hash('sha256', "{$userAgent}|{$language}");
    }
}
