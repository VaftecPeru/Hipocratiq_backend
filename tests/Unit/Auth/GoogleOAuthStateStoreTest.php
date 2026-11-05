<?php

namespace Tests\Unit\Auth;

use App\Services\Auth\GoogleOAuthStateStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GoogleOAuthStateStoreTest extends TestCase
{
    public function test_authorization_state_can_only_be_consumed_once(): void
    {
        Cache::clear();
        $store = app(GoogleOAuthStateStore::class);
        $state = $store->createAuthorization(['requested_role' => 'patient']);

        $this->assertSame(['requested_role' => 'patient'], $store->consumeAuthorization($state));
        $this->expectException(ValidationException::class);
        $store->consumeAuthorization($state);
    }

    public function test_expired_authorization_state_is_rejected(): void
    {
        Cache::clear();
        $store = app(GoogleOAuthStateStore::class);
        $state = $store->createAuthorization(['requested_role' => 'patient']);
        $this->travel(601)->seconds();

        $this->expectException(ValidationException::class);
        $store->consumeAuthorization($state);
    }

    public function test_invalid_authorization_state_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(GoogleOAuthStateStore::class)->consumeAuthorization('invalid');
    }
}
