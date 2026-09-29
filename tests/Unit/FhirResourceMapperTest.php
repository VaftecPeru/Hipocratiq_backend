<?php

namespace Tests\Unit;

use App\Support\Clinical\FhirResourceMapper;
use Tests\TestCase;

class FhirResourceMapperTest extends TestCase
{
    public function test_read_only_capability_does_not_advertise_update_versioning(): void
    {
        $statement = app(FhirResourceMapper::class)->capabilityStatement();

        $resources = $statement['rest'][0]['resource'] ?? [];

        $this->assertNotEmpty($resources);

        foreach ($resources as $resource) {
            $this->assertSame([['code' => 'read']], $resource['interaction']);
            $this->assertSame('no-version', $resource['versioning']);
        }
    }
}
