<?php

namespace Database\Seeders;

use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Role::query()->firstOrCreate(
            ['key' => RoleKey::SuperAdmin->value],
            [
                'name' => 'Super Administrador',
                'scope' => RoleScope::Global,
                'is_system' => true,
            ],
        );
    }
}
