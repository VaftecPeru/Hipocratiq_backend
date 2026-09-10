<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;

final class MobileDeviceProof
{
    public const string ClientTypeHeader = 'X-Client-Type';

    public const string DeviceNameHeader = 'X-Device-Name';

    public const string DeviceIdHeader = 'X-Device-ID';

    public static function hashId(string $deviceId): string
    {
        return hash('sha256', $deviceId);
    }

    public static function normalizedName(mixed $deviceName): ?string
    {
        if (! is_string($deviceName)) {
            return null;
        }

        $name = trim($deviceName);

        return $name === '' ? null : $name;
    }

    public static function matches(
        ?string $expectedName,
        ?string $expectedIdHash,
        mixed $deviceName,
        mixed $deviceId,
    ): bool {
        $actualName = self::normalizedName($deviceName);

        if ($actualName === null || $expectedName === null || $expectedIdHash === null || ! is_string($deviceId)) {
            return false;
        }

        return hash_equals($expectedName, $actualName)
            && hash_equals($expectedIdHash, self::hashId($deviceId));
    }

    public static function requestMatches(
        Request $request,
        ?string $expectedName,
        ?string $expectedIdHash,
    ): bool {
        return self::matches(
            $expectedName,
            $expectedIdHash,
            $request->header(self::DeviceNameHeader),
            $request->header(self::DeviceIdHeader),
        );
    }
}
