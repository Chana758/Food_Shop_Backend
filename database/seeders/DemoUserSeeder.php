<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'demo-admin@example.com'],
            [
                'name' => 'Demo Admin',
                'phone' => '010000001',
                'password' => Hash::make('DemoAdmin@2026'),
                'role' => 'admin',
                'status' => 'active',
            ]
        );

        User::updateOrCreate(
            ['email' => 'demo-staff@example.com'],
            [
                'name' => 'Demo Staff',
                'phone' => '010000002',
                'password' => Hash::make('DemoStaff@2026'),
                'role' => 'staff',
                'status' => 'active',
            ]
        );

        User::updateOrCreate(
            ['email' => 'demo-user@example.com'],
            [
                'name' => 'Demo User',
                'phone' => '010000003',
                'password' => Hash::make('DemoUser@2026'),
                'role' => 'customer',
                'status' => 'active',
            ]
        );
    }
}