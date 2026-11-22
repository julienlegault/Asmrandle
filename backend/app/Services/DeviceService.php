<?php

namespace App\Services;

use Illuminate\Http\Request;

class DeviceService
{
    // Only a client-generated random ID is trusted. Fingerprints such as
    // User-Agent + Accept-Language collide across identically configured machines.
    public function getDeviceId(Request $request): ?string
    {
        $deviceId = $request->header('X-Device-ID');

        return is_string($deviceId) && preg_match('/^[0-9a-f-]{36}$/i', $deviceId) ? strtolower($deviceId) : null;
    }
}
