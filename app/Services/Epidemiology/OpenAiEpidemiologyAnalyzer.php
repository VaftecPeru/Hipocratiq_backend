<?php

namespace App\Services\Epidemiology;

use App\Contracts\EpidemiologyAnalyzer;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAiEpidemiologyAnalyzer implements EpidemiologyAnalyzer
{
    public function configured(): bool
    {
        return filled(config('integrations.ai.api_key'))
            && filled(config('integrations.ai.model'))
            && filled(config('integrations.ai.base_url'));
    }

    public function provider(): string
    {
        return 'openai';
    }

    public function analyze(array $snapshot): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('El motor epidemiológico OpenAI no está configurado.');
        }

        $response = Http::withToken((string) config('integrations.ai.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('integrations.ai.timeout_seconds', 20))
            ->post(rtrim((string) config('integrations.ai.base_url'), '/').'/responses', [
                'model' => (string) config('integrations.ai.model'),
                'instructions' => implode(' ', [
                    'Eres un motor de apoyo epidemiológico para datos AGREGADOS de una clínica.',
                    'No diagnostiques personas, no infieras identidades y no solicites datos personales.',
                    'Devuelve únicamente JSON válido, sin markdown, con: trend (stable|rising|falling|mixed),',
                    'forecasts (lista de objetos title, detail, horizon_days), alerts (title, detail, severity info|warning|critical),',
                    'recommendations (lista de textos) y summary (texto breve).',
                    'Las alertas son señales operativas para revisión humana, no diagnósticos.',
                ]),
                'input' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'max_output_tokens' => (int) config('integrations.ai.max_output_tokens', 1200),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('El proveedor epidemiológico respondió con estado '.$response->status().'.');
        }

        $payload = $response->json();
        $text = is_string($payload['output_text'] ?? null) ? $payload['output_text'] : $this->extractText($payload);
        $decoded = is_string($text) ? json_decode(trim($text), true) : null;

        if (! is_array($decoded)) {
            throw new RuntimeException('El proveedor epidemiológico devolvió una respuesta no estructurada.');
        }

        return [
            'trend' => in_array($decoded['trend'] ?? null, ['stable', 'rising', 'falling', 'mixed'], true)
                ? $decoded['trend']
                : 'stable',
            'forecasts' => $this->arrayList($decoded['forecasts'] ?? []),
            'alerts' => $this->arrayList($decoded['alerts'] ?? []),
            'recommendations' => $this->stringList($decoded['recommendations'] ?? []),
            'summary' => is_string($decoded['summary'] ?? null) ? trim($decoded['summary']) : null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function extractText(array $payload): ?string
    {
        foreach (($payload['output'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach (($item['content'] ?? []) as $content) {
                if (is_array($content) && is_string($content['text'] ?? null)) {
                    return $content['text'];
                }
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function arrayList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn (mixed $item): bool => is_array($item)));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
            $value,
        )));
    }
}
