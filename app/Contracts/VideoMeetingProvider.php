<?php

namespace App\Contracts;

use App\Models\Teleconsultation;

interface VideoMeetingProvider
{
    public function configured(): bool;

    /** @return array<string, mixed> */
    public function status(): array;

    /** @return array<string, mixed> */
    public function connection(Teleconsultation $session): array;
}
