<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // បង្កើតទិន្នន័យអតិថិជនគំរូ ៥ នាក់
        $customers = [
            [
                'name' => 'Vy Za',
                'email' => 'vyza@gmail.com',
                'password' => Hash::make('password123'),
                'phone' => '096111222',
                'role' => 'customer',
            ],
            [
                'name' => 'Sokha Mean',
                'email' => 'sokha@gmail.com',
                'password' => Hash::make('password123'),
                'phone' => '085333444',
                'role' => 'customer',
            ],
            [
                'name' => 'Bona Chan',
                'email' => 'bona@gmail.com',
                'password' => Hash::make(' '),
                'phone' => '077555666',
                'role' => 'customer',
            ],
            [
                'name' => 'Nary Pich',
                'email' => 'nary@gmail.com',
                'password' => Hash::make('password123'),
                'phone' => '012777888',
                'role' => 'customer',
            ],
            [
                'name' => 'Seyha Long',
                'email' => 'seyha@.com',
                'password' => Hash::make('password123'),
                'phone' => '099999000',
                'role' => 'customer',
            ],
        ];

        //បានកែសម្រួល៖ រត់ Loop ដោយប្រើ firstOrCreate ដើម្បីការពារ Error ជាន់អ៊ីមែលគ្នា
        foreach ($customers as $customer) {
            User::firstOrCreate(
                ['email' => $customer['email']], // លក្ខខណ្ឌឆែកមើល (បើមាន Email នេះហើយ គឺមិនបញ្ចូលជាន់ឡើយ)
                $customer                        // បើអត់ទាន់មានទេ ទើបយកដុំទិន្នន័យនេះទៅបង្កើតថ្មី
            );
        }
    }
}