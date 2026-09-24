<?php

namespace App\Services\Epidemiology;

use App\Contracts\EpidemiologyAnalyzer;

final class UnavailableEpidemiologyAnalyzer implements EpidemiologyAnalyzer
{
    public function configured(): bool
    {
        return false;
    }

    public function provider(): string
    {
        return 'unavailable';
    }

    public function analyze(array $snapshot): array
    {
        return [
            'trend' => 'not_configured',
            'forecasts' => [],
            'alerts' => [],
            'recommendations' => [],
            'summary' => null,
        ];
    }
}
