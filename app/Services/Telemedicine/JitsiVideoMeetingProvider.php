<?php

namespace App\Services\Telemedicine;

use App\Contracts\VideoMeetingProvider;
use App\Enums\TeleconsultationStatus;
use App\Models\Teleconsultation;

final class JitsiVideoMeetingProvider implements VideoMeetingProvider
{
    public function configured(): bool
    {
        return filled(config('integrations.telemedicine.base_url'))
            && filled(config('integrations.telemedicine.room_secret'));
    }

    public function status(): array
    {
        return [
            'status' => $this->configured() ? 'configured' : 'not_configured',
            'provider' => 'jitsi',
            'message' => $this->configured()
                ? 'Proveedor de videollamada configurado.'
                : 'Falta configurar la URL y el secreto de salas de telemedicina.',
        ];
    }

    public function connection(Teleconsultation $session): array
    {
        if (! $this->configured()) {
            return [
                ...$this->status(),
                'joinable' => false,
                'join_url' => null,
            ];
        }

        $closed = in_array($session->status, [
            TeleconsultationStatus::Completed,
            TeleconsultationStatus::Canceled,
        ], true);
        $prefix = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) config('integrations.telemedicine.room_prefix', 'hipocratiq'));
        $signature = substr(hash_hmac(
            'sha256',
            'teleconsultation:'.$session->getKey(),
            (string) config('integrations.telemedicine.room_secret'),
        ), 0, 20);
        $room = trim($prefix, '-').'-'.$session->getKey().'-'.$signature;

        return [
            ...$this->status(),
            'joinable' => ! $closed,
            'join_url' => ! $closed
                ? rtrim((string) config('integrations.telemedicine.base_url'), '/').'/'.$room
                : null,
        ];
    }
}
