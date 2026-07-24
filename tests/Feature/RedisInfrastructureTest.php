<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiter;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class RedisInfrastructureTest extends TestCase
{
    private static ?bool $redisAvailable = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$redisAvailable === false) {
            $this->markTestSkipped('Redis integration is not available.');
        }

        try {
            foreach (['cache', 'session', 'limiter', 'locks'] as $connection) {
                Redis::connection($connection)->ping();
            }

            self::$redisAvailable = true;
        } catch (Throwable) {
            self::$redisAvailable = false;
            $this->markTestSkipped('Redis integration is not available.');
        }
    }

    public function test_redis_stores_are_isolated_and_locks_and_limiter_are_distributed(): void
    {
        $key = 'redis-infrastructure:'.Str::uuid();
        $lockKey = $key.':lock';
        $cache = Cache::store('redis');
        $session = Cache::store('redis_session');
        $limiter = Cache::store('redis_limiter');
        $firstLock = $cache->lock($lockKey, 10);
        $secondLock = $cache->lock($lockKey, 10);
        $firstLockAcquired = false;
        $rateLimitKey = $key.':rate-limit';

        try {
            $cache->put($key, 'cache', 30);
            $session->put($key, 'session', 30);
            $limiter->put($key, 'limiter', 30);

            $this->assertSame('cache', $cache->get($key));
            $this->assertSame('session', $session->get($key));
            $this->assertSame('limiter', $limiter->get($key));

            $firstLockAcquired = $firstLock->get();

            $this->assertTrue($firstLockAcquired);
            $this->assertFalse($secondLock->get());

            config(['cache.limiter' => 'redis_limiter']);
            $this->app->forgetInstance(RateLimiter::class);
            $rateLimiter = $this->app->make(RateLimiter::class);
            $rateLimiter->hit($rateLimitKey, 30);
            $this->app->forgetInstance(RateLimiter::class);

            $this->assertSame(1, (int) $this->app->make(RateLimiter::class)->attempts($rateLimitKey));
        } finally {
            if ($firstLockAcquired) {
                $firstLock->release();
            }

            $cache->forget($key);
            $session->forget($key);
            $limiter->forget($key);
            $this->app->make(RateLimiter::class)->clear($rateLimitKey);
        }
    }

    public function test_session_data_survives_a_fresh_session_driver_instance(): void
    {
        config([
            'session.driver' => 'redis',
            'session.store' => 'redis_session',
            'session.connection' => 'session',
        ]);

        $sessionId = Str::random(40);
        $manager = $this->app->make(SessionManager::class);
        $manager->forgetDrivers();
        $writer = $manager->driver();
        $writer->setId($sessionId);
        $writer->start();
        $writer->put('redis_probe', 'persisted');
        $writer->save();

        $manager->forgetDrivers();
        $reader = $manager->driver();
        $reader->setId($sessionId);
        $reader->start();

        try {
            $this->assertSame('persisted', $reader->get('redis_probe'));
        } finally {
            $reader->flush();
            $reader->save();
        }
    }

    public function test_health_endpoint_checks_every_required_redis_dependency(): void
    {
        config([
            'cache.default' => 'redis',
            'cache.limiter' => 'redis_limiter',
            'session.driver' => 'redis',
            'session.store' => 'redis_session',
            'session.connection' => 'session',
        ]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.dependencies.redis', 'ok');
    }
}
