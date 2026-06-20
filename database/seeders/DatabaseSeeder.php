<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run()
    {
        \App\Models\User::create([
            'name'     => 'Sam Channa',
            'email'    => 'csam26176@gmail.com',
            'password' => bcrypt('125476'), 
            'role'     => 'admin', 
            'phone'    => '0972325094', 
        ]);

        \App\Models\User::create([
            'name'     => 'Seng Sarina',
            'email'    => 'sarina@gmail.com',
            'password' => bcrypt('271534'),
            'role'     => 'staff',
            'phone'    => '0972324523',
        ]);
 
        \App\Models\User::create([
            'name'     => 'Vany Vyza',
            'email'    => 'vyza@gmail.com',
            'password' => bcrypt('254726'), 
            'role'     => 'staff',
            'phone'    => '0972324526',
        ]);
        \App\Models\User::create([
            'name'     => 'Staff Name 1',
            'email'    => 'staff1@gmail.com',
            'password' => bcrypt('password123'),
            'role'     => 'staff',
            'phone'    => '097xxxxxxx',
        ]);

        \App\Models\User::create([
            'name'     => 'Staff Name 2', 
            'email'    => 'staff2@gmail.com',
            'password' => bcrypt('password123'),
            'role'     => 'staff',
            'phone'    => '097xxxxxxx',
        ]);
    }
}