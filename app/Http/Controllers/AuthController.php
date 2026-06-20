<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    //
    public function register(Request $request){
        // 1. Validation: ត្រួតពិនិត្យទិន្នន័យដែលផ្ញើមកពី Frontend (React) ថាត្រឹមត្រូវតាមលក្ខខណ្ឌឬអត់
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string|max:20',
            'password' => 'required|string|min:8|confirmed',
        ]);
        try {
            // 2. Eloquent Create: បញ្ជាឱ្យ Model User បង្កើតទិន្នន័យថ្មីទៅក្នុង Table 'users'
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password), /// បំប្លែងលេខសម្ងាត់ឱ្យទៅជាកូដសម្ងាត់ (Encryption) ដើម្បីសុវត្ថិភាព
                'role' => 'customer', // កំណត់តួនាទីជា 'customer' (អតិថិជន) ដោយស្វ័យប្រវត្តិ
            ]);
            // 3. Create Token: បង្កើត "សោរសម្ងាត់" (API Token) សម្រាប់ឱ្យ User ប្រើដើម្បីចូលទៅកាន់ API ផ្សេងៗ
            $token = $user->createToken('auth_token')->plainTextToken;
            // 4. Response: ត្រឡប់តម្លៃទៅកាន់ Frontend (React) ជាទ្រង់ទ្រាយ JSON ដែលមានទាំងព័ត៌មានអ្នកប្រើ និងសោរសម្ងាត់
            return response()->json([
                'status' => 'success',
                'message' => 'User registered successfully',
                'user' => $user, // ផ្ញើព័ត៌មាន User ដែលទើបបង្កើតទៅឱ្យ React
                'access_token' => $token, // ផ្ញើ Token ទៅឱ្យ React រក្សាទុក
                'token_type' => 'Bearer', // ប្រភេទនៃ Token (សម្រាប់ដាក់ក្នុង Header ពេលបាញ់ API ក្រោយៗ)
                
                ], 201); // // លេខកូដ HTTP 201 មានន័យថា 'Created' (ត្រូវបានបង្កើត)
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json([
                'status' => 'error',
                'message' => 'Registration failed: ' . $th->getMessage(), // បង្ហាញសារ error ប្រសិនបើមានបញ្ហា
            ], 500); // លេខកូដ HTTP 500 មានន័យថា 'Internal Server Error' (កំហុសនៅក្នុងម៉ាស៊ីនមេ)
        }
    }
   /**
 * មុខងារចូលប្រើប្រាស់ប្រព័ន្ធ (Login Function)
 */
    public function login(Request $request) {
        
        // ១. Validation: ត្រួតពិនិត្យថា តើ User បានបញ្ចូល Email និង Password ហើយឬនៅ
        $request->validate([
            'email'    => 'required|email',    
            'password' => 'required',          
        ]);

        try {
            // ២. Attempt Login: ប្រើ Auth::attempt ដើម្បីផ្ទៀងផ្ទាត់ Email និង Password ជាមួយ Database
            // វាជួយ Hash password ដែលផ្ញើមក រួចទៅធៀបជាមួយ Hash ក្នុង DB ដោយស្វ័យប្រវត្តិ
            if (!Auth::attempt($request->only('email', 'password'))) {
                
                // បើផ្ទៀងផ្ទាត់មិនជោគជ័យ (ខុស Email ឬ ខុស Password)
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Invalid email or password'
                ], 401); // 401 មានន័យថា Unauthorized (គ្មានសិទ្ធិចូល)
            }

            // ៣. Get User Data: បើត្រឹមត្រូវ យើងទាញយកព័ត៌មានរបស់ User នោះចេញពី Database
            $user = User::where('email', $request->email)->firstOrFail();

            // ៤. Create New Token: បង្កើត "សោរសម្ងាត់" ថ្មីមួយសម្រាប់រដូវកាល (Session) នេះ
            $token = $user->createToken('auth_token')->plainTextToken;

            // ៥. Success Response: បញ្ជូនទិន្នន័យ User និង Token ទៅឱ្យ React
            return response()->json([
                'status'       => 'success',
                'message'      => 'you have successfully logged in',
                'user'           => $user,                   // ផ្ញើព័ត៌មាន User (រួមទាំង Role គាត់)
                'access_token' => $token,                  // ផ្ញើ Token ទៅឱ្យ React រក្សាទុកក្នុង LocalStorage
                'token_type'   => 'Bearer',
            ], 200); // 200 មានន័យថា OK (ជោគជ័យ)

        } catch (\Exception $e) {
            
            // ៦. Error Handling: បើមានបញ្ហាបច្ចេកទេសអ្វីមួយកើតឡើង
            return response()->json([
                'status'  => 'error',
                'message' => 'There is a technical issue: ' . $e->getMessage(),
            ], 500);
        }
    }
}
