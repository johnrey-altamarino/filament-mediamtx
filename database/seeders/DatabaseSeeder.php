<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create roles (safe if already exists)
        foreach (['super-admin', 'moderator', 'monitoring'] as $role) {
            Role::findOrCreate($role);
        }

        // Create default admin
        $user = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
            ]
        );

        $user->syncRoles(['super-admin']);
    }
}
