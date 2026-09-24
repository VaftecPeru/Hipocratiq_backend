<?php

namespace App\Contracts;

interface EpidemiologyAnalyzer
{
    public function configured(): bool;

    public function provider(): string;

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function analyze(array $snapshot): array;
}
