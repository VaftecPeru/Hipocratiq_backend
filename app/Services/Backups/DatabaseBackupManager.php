<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DatabaseBackupManager
{
    public function configured(): bool
    {
        return (bool) config('admin_operations.backups.enabled')
            && config('admin_operations.backups.provider') === 'postgresql'
            && filled(config('admin_operations.backups.disk'));
    }

    /** @return array<string, mixed> */
    public function create(): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('El servicio de respaldos PostgreSQL no está configurado.');
        }

        $connection = config('database.connections.'.config('database.default'));

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('El respaldo automático requiere una conexión PostgreSQL.');
        }

        $command = sprintf(
            'pg_dump --host=%s --port=%s --username=%s --dbname=%s --format=custom --no-owner --no-privileges',
            escapeshellarg((string) ($connection['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($connection['port'] ?? '5432')),
            escapeshellarg((string) ($connection['username'] ?? '')),
            escapeshellarg((string) ($connection['database'] ?? '')),
        );
        $result = Process::env([
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
        ])->timeout((int) config('admin_operations.backups.timeout_seconds', 300))->run($command);

        if ($result->failed()) {
            throw new RuntimeException('pg_dump no pudo generar el respaldo: '.trim($result->errorOutput()));
        }

        $content = $result->output();

        if ($content === '') {
            throw new RuntimeException('pg_dump generó un respaldo vacío.');
        }

        $id = now()->utc()->format('Ymd_His').'_'.bin2hex(random_bytes(4));
        $prefix = trim((string) config('admin_operations.backups.prefix', 'hipocratiq'), '/');
        $dumpPath = $prefix.'/database/'.$id.'.dump';
        $manifestPath = $prefix.'/manifests/'.$id.'.json';
        $disk = Storage::disk((string) config('admin_operations.backups.disk'));
        $disk->put($dumpPath, $content, ['visibility' => 'private']);

        $manifest = [
            'id' => $id,
            'provider' => 'postgresql',
            'database' => (string) ($connection['database'] ?? ''),
            'disk' => (string) config('admin_operations.backups.disk'),
            'dump_path' => $dumpPath,
            'manifest_path' => $manifestPath,
            'sha256' => hash('sha256', $content),
            'size_bytes' => strlen($content),
            'created_at' => now()->utc()->toIso8601String(),
            'verified_at' => null,
        ];
        $disk->put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $this->prune();

        return $manifest;
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        if (! $this->configured()) {
            return [];
        }

        $disk = Storage::disk((string) config('admin_operations.backups.disk'));
        $prefix = trim((string) config('admin_operations.backups.prefix', 'hipocratiq'), '/').'/manifests';
        $items = [];

        foreach ($disk->files($prefix) as $path) {
            if (! str_ends_with($path, '.json')) {
                continue;
            }

            $manifest = json_decode((string) $disk->get($path), true);
            if (is_array($manifest)) {
                $items[] = $manifest;
            }
        }

        usort($items, fn (array $left, array $right): int => strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')));

        return array_slice($items, 0, 50);
    }

    /** @return array<string, mixed> */
    public function verify(string $id): array
    {
        $manifest = $this->manifest($id);
        $disk = Storage::disk((string) config('admin_operations.backups.disk'));
        $dumpPath = (string) ($manifest['dump_path'] ?? '');

        if ($dumpPath === '' || ! $disk->exists($dumpPath)) {
            throw new RuntimeException('El artefacto del respaldo no existe.');
        }

        $content = (string) $disk->get($dumpPath);
        $valid = hash_equals((string) ($manifest['sha256'] ?? ''), hash('sha256', $content))
            && strlen($content) === (int) ($manifest['size_bytes'] ?? -1);

        if (! $valid) {
            throw new RuntimeException('La verificación de integridad del respaldo falló.');
        }

        $manifest['verified_at'] = now()->utc()->toIso8601String();
        $disk->put((string) $manifest['manifest_path'], json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $manifest;
    }

    public function restore(string $id): void
    {
        $manifest = $this->verify($id);
        $disk = Storage::disk((string) config('admin_operations.backups.disk'));
        $content = (string) $disk->get((string) $manifest['dump_path']);
        $connection = config('database.connections.'.config('database.default'));

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('La restauración requiere una conexión PostgreSQL.');
        }

        $command = sprintf(
            'pg_restore --host=%s --port=%s --username=%s --dbname=%s --clean --if-exists --no-owner --no-privileges',
            escapeshellarg((string) ($connection['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($connection['port'] ?? '5432')),
            escapeshellarg((string) ($connection['username'] ?? '')),
            escapeshellarg((string) ($connection['database'] ?? '')),
        );
        $result = Process::input($content)
            ->env(['PGPASSWORD' => (string) ($connection['password'] ?? '')])
            ->timeout((int) config('admin_operations.backups.restore_timeout_seconds', 600))
            ->run($command);

        if ($result->failed()) {
            throw new RuntimeException('pg_restore no pudo completar la restauración: '.trim($result->errorOutput()));
        }
    }

    /** @return array<string, mixed> */
    private function manifest(string $id): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new RuntimeException('Identificador de respaldo inválido.');
        }

        $disk = Storage::disk((string) config('admin_operations.backups.disk'));
        $path = trim((string) config('admin_operations.backups.prefix', 'hipocratiq'), '/').'/manifests/'.$id.'.json';

        if (! $disk->exists($path)) {
            throw new RuntimeException('No se encontró el manifiesto del respaldo.');
        }

        $manifest = json_decode((string) $disk->get($path), true);

        if (! is_array($manifest)) {
            throw new RuntimeException('El manifiesto del respaldo no es válido.');
        }

        return $manifest;
    }

    private function prune(): void
    {
        $retentionDays = max(1, (int) config('admin_operations.backups.retention_days', 30));
        $threshold = now()->utc()->subDays($retentionDays);

        foreach ($this->history() as $manifest) {
            $createdAt = isset($manifest['created_at']) ? now()->parse((string) $manifest['created_at']) : null;

            if ($createdAt === null || $createdAt->greaterThanOrEqualTo($threshold)) {
                continue;
            }

            $disk = Storage::disk((string) config('admin_operations.backups.disk'));
            $disk->delete(array_filter([
                $manifest['dump_path'] ?? null,
                $manifest['manifest_path'] ?? null,
            ]));
        }
    }
}
