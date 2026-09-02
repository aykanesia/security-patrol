<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'super_admin', 'description' => 'Administrator dengan akses penuh ke seluruh sistem'],
            ['name' => 'supervisor', 'description' => 'Supervisor: monitoring patroli, laporan, jadwal'],
            ['name' => 'security', 'description' => 'Petugas keamanan (aplikasi Android)'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['name' => $role['name']], $role);
        }
    }
}
