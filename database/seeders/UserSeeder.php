<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
        public function run()
    {
        \App\Models\User::create([
            'name' => 'Sam Channa',
            'email' => 'csam26176@gmail.com',
            'password' => bcrypt('125476'),
            'role' => 'admin', // បន្ថែម Column role នេះឱ្យត្រូវនឹង Database របស់បង
        ]);
    }
}
