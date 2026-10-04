<?php

namespace Tests\Unit;

use App\Services\Epidemiology\OpenAiEpidemiologyAnalyzer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiEpidemiologyAnalyzerTest extends TestCase
{
    public function test_it_requests_strict_structured_output_and_maps_response(): void
    {
        config()->set('integrations.ai.enabled', true);
        config()->set('integrations.ai.api_key', 'test-key');
        config()->set('integrations.ai.model', 'test-model');
        config()->set('integrations.ai.base_url', 'https://api.example.test/v1');
        config()->set('integrations.ai.structured_output', true);

        Http::fake([
            'https://api.example.test/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'trend' => 'rising',
                    'forecasts' => [[
                        'title' => 'Mayor demanda',
                        'detail' => 'Revisar capacidad operativa.',
                        'horizon_days' => 14,
                    ]],
                    'alerts' => [[
                        'title' => 'Señal agregada',
                        'detail' => 'Validar con revisión clínica.',
                        'severity' => 'warning',
                    ]],
                    'recommendations' => ['Revisar disponibilidad de atención.'],
                    'summary' => 'Tendencia agregada en aumento.',
                ], JSON_THROW_ON_ERROR),
            ], 200),
        ]);

        $result = app(OpenAiEpidemiologyAnalyzer::class)->analyze([
            'period_days' => 90,
            'metrics' => ['monitored_patients' => 20],
        ]);

        $this->assertSame('rising', $result['trend']);
        $this->assertSame('Tendencia agregada en aumento.', $result['summary']);

        Http::assertSent(function ($request): bool {
            $format = $request->data()['text']['format'] ?? null;

            return $request->url() === 'https://api.example.test/v1/responses'
                && is_array($format)
                && ($format['type'] ?? null) === 'json_schema'
                && ($format['strict'] ?? null) === true
                && ($format['name'] ?? null) === 'epidemiology_analysis';
        });
    }
}
