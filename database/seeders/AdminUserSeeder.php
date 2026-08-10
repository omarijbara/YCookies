<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if admin user already exists
        $user = User::firstOrCreate(
            ['email' => 'admin@ycookies.local'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // Assign Filament Shield super_admin role 
        // This assumes the role exists or will be created dynamically
        if (class_exists(\BezhanSalleh\FilamentShield\Support\Utils::class)) {
            $roleClass = \BezhanSalleh\FilamentShield\Support\Utils::getRoleModel();
            $superAdminRoleName = \BezhanSalleh\FilamentShield\Support\Utils::getSuperAdminName();
            
            $role = $roleClass::firstOrCreate(['name' => $superAdminRoleName, 'guard_name' => 'web']);
            
            if (!$user->hasRole($superAdminRoleName)) {
                $user->assignRole($superAdminRoleName);
            }
        }

        // Ensure the Super Admin has at least one Tenant (Group) to avoid 404s
        $group = \App\Models\Group::firstOrCreate(
            ['name' => 'Default Agency'],
            ['description' => 'System default agency group']
        );

        // If a direct many-to-many relationship exists, attach it
        if (method_exists($user, 'groups')) {
            $user->groups()->syncWithoutDetaching([$group->id]);
        }
    }
}
