<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', RoleName::SUPER_ADMIN->value)->firstOrFail();
        $supervisorRole = Role::where('name', RoleName::SUPERVISOR->value)->firstOrFail();
        $securityRole = Role::where('name', RoleName::SECURITY->value)->firstOrFail();

        $users = [
            // [role, employee_code, name, username, password, phone]
            [$adminRole->id, 'ADM001', 'Administrator', 'admin', 'password', '0812000001'],
            [$supervisorRole->id, 'SPV001', 'Supervisor Malam', 'supervisor', 'password', '0812000002'],
            [$securityRole->id, 'SEC001', 'Budi Santoso', 'budi', 'password', '0812000003'],
            [$securityRole->id, 'SEC002', 'Andi Wijaya', 'andi', 'password', '0812000004'],
            [$securityRole->id, 'SEC003', 'Citra Lestari', 'citra', 'password', '0812000005'],
        ];

        foreach ($users as [$roleId, $code, $name, $username, $password, $phone]) {
            User::updateOrCreate(
                ['username' => $username],
                [
                    'role_id' => $roleId,
                    'employee_code' => $code,
                    'name' => $name,
                    'password' => Hash::make($password),
                    'phone' => $phone,
                    'status' => 'ACTIVE',
                ],
            );
        }
    }
}
