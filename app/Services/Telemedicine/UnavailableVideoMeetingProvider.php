<?php

namespace App\Services\Telemedicine;

use App\Contracts\VideoMeetingProvider;
use App\Models\Teleconsultation;

final class UnavailableVideoMeetingProvider implements VideoMeetingProvider
{
    public function configured(): bool
    {
        return false;
    }

    public function status(): array
    {
        return [
            'status' => 'not_configured',
            'provider' => 'unavailable',
            'message' => 'No hay un proveedor de video configurado para esta práctica.',
        ];
    }

    public function connection(Teleconsultation $session): array
    {
        return [
            ...$this->status(),
            'joinable' => false,
            'join_url' => null,
        ];
    }
}
