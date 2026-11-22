<?php

namespace App\Services;

use Illuminate\Http\Request;

class DeviceService
{
    public function getDeviceId(Request $request): string
    {
        // Try to get from request (set by middleware)
        if ($deviceId = $request->input('device_id')) {
            return $deviceId;
        }

        // Try to get from header
        if ($deviceId = $request->header('X-Device-ID')) {
            return $deviceId;
        }

        // Generate new one
        return $this->generateDeviceId($request);
    }

    private function generateDeviceId(Request $request): string
    {
        $userAgent = $request->userAgent() ?? 'unknown';
        $language = $request->header('Accept-Language') ?? 'unknown';

        // Using SHA256 provides sufficient anonymization
        // while being deterministic for same device
        return hash('sha256', "{$userAgent}|{$language}");
    }
}
