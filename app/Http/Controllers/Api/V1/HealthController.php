<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'ok',
                'checked_at' => now()->toJSON(),
            ],
        ]);
    }

    public function ready(): JsonResponse
    {
        $dependencies = [
            'database' => 'ok',
            'redis' => 'not_required',
            'storage' => 'ok',
        ];
        $ready = true;

        try {
            DB::select('select 1');
        } catch (Throwable) {
            $dependencies['database'] = 'unavailable';
            $ready = false;
        }

        $redisConnections = $this->requiredRedisConnections();
        if ($redisConnections !== []) {
            $dependencies['redis'] = 'ok';

            try {
                foreach ($redisConnections as $connection) {
                    Redis::connection($connection)->ping();
                }
            } catch (Throwable) {
                $dependencies['redis'] = 'unavailable';
                $ready = false;
            }
        }

        $storageDisk = (bool) config('admin_operations.backups.enabled')
            ? (string) config('admin_operations.backups.disk')
            : (string) config('filesystems.default', 'local');

        try {
            Storage::disk($storageDisk)->exists('__hipocratiq_readiness_probe__');
        } catch (Throwable) {
            $dependencies['storage'] = 'unavailable';
            $ready = false;
        }

        return response()->json([
            'success' => $ready,
            'data' => [
                'status' => $ready ? 'ok' : 'unavailable',
                'dependencies' => $dependencies,
                'checked_at' => now()->toJSON(),
            ],
        ], $ready ? 200 : 503);
    }

    /** @return list<string> */
    private function requiredRedisConnections(): array
    {
        $redisConnections = [];
        $cacheStores = [config('cache.default'), config('cache.limiter')];

        if (config('session.driver') === 'redis') {
            $cacheStores[] = config('session.store', 'redis_session');
            $redisConnections[] = config('session.connection', 'session');
        }

        if (config('queue.default') === 'redis') {
            $redisConnections[] = config('queue.connections.redis.connection', 'default');
        }

        foreach (array_unique(array_filter($cacheStores, 'is_string')) as $storeName) {
            $store = config('cache.stores.'.$storeName, []);

            if (($store['driver'] ?? null) === 'redis') {
                $redisConnections[] = $store['connection'] ?? 'default';
                $redisConnections[] = $store['lock_connection'] ?? $store['connection'] ?? 'default';
            }
        }

        return array_values(array_unique(array_filter($redisConnections, 'is_string')));
    }
}
