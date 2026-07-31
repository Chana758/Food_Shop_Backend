<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
                                                                                                                                                                                                                                                                                                                                                         
    public function register(Request $request){
        // Validate registration request
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string|max:20',
            'password' => 'required|string|min:8|confirmed',
        ]); 
        try {
            // Create new user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),// Encryption password
                'role' => 'customer', //default role customer
            ]);
            // Generate access token
            $token = $user->createToken('auth_token')->plainTextToken;

            // Return successful response
            return response()->json([
                'status' => 'success',
                'message' => 'User registered successfully',
                'user' => $user,
                'access_token' => $token, 
                'token_type' => 'Bearer', 
                
                ], 201); 
        } catch (\Throwable $th) {
            //throw $th;
            return response()->json([
                'status' => 'error',
                'message' => 'Registration failed: ' . $th->getMessage(), 
            ], 500); 
        }
    }
   /**
 * Login function
 */
    public function login(Request $request) {
        
        // Validate login request
        $request->validate([
            'email'    => 'required|email',    
            'password' => 'required',          
        ]);

        try {
            // Authenticate user credentials against the database
            // Automatically compares the provided password with the hashed password
            if (!Auth::attempt($request->only('email', 'password'))) {
                
                // Return error if authentication fails
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Invalid email or password'
                ], 401);// 401 Unauthorized
            }

            // Retrieve authenticated user
            $user = User::where('email', $request->email)->firstOrFail();

            // Generate new access token
            $token = $user->createToken('auth_token')->plainTextToken;

            // Return user data and access token
            return response()->json([
                'status'       => 'success',
                'message'      => 'you have successfully logged in',
                'user'           => $user,                   // Return user information (including role)
                'access_token' => $token,                  // Send token to React for localStorage storage
                'token_type'   => 'Bearer',
            ], 200);

        } catch (\Exception $e) {
            
            // Handle server errors
            return response()->json([
                'status'  => 'error',
                'message' => 'There is a technical issue: ' . $e->getMessage(),
            ], 500);
        }
    }
}
