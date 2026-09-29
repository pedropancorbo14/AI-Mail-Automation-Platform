<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
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
        $this->call(RolesAndPermissionsSeeder::class);

        // Empresa y usuario de prueba para desarrollo local.
        $tenant = Tenant::firstOrCreate(['slug' => 'empresa-demo'], ['name' => 'Empresa Demo']);

        User::factory()->for($tenant)->owner()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
