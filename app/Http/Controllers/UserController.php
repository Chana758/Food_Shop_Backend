<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Retrieve all users with the 'staff' role.
     */
    public function getStaff() 
    {
        try {
            $staff = User::where('role', 'staff')->get();
            return response()->json([
                'status' => 'success',
                'data'   => $staff
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new staff member.
     * Password is required here.
     */
    public function store(Request $request) 
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'phone'    => 'nullable|string|max:20',
            'password' => 'required|string|min:8',
        ]);

        try {
            $staff = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'phone'    => $request->phone,
                'password' => Hash::make($request->password),
                'role'     => 'staff',
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Staff added successfully',
                'data'    => $staff
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Update existing staff information.
     * Password is excluded to avoid errors.
     */
    public function updatestaff(Request $request, $id) 
    {
        // Validate inputs, allowing the email to remain the same for the current user ID
        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $id,
            'phone' => 'nullable|string|max:20',
        ]);

        try {
            $staff = User::findOrFail($id);
            // Only update specific fields
            $staff->update($request->only(['name', 'email', 'phone']));
            
            return response()->json([
                'status'  => 'success',
                'message' => 'Staff updated successfully',
                'data'    => $staff
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a staff member.
     */
    public function destroystaff($id) 
    {
        try {
            $staff = User::findOrFail($id);
            $staff->delete();
            return response()->json([
                'status'  => 'success',
                'message' => 'Staff removed successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Retrieve all users with the 'customer' role.
     */
    public function getCustomers() 
    {
        try {
            $customers = User::where('role', 'customer')->get();
            
            return response()->json([
                'status' => 'success',
                'data'   => $customers
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }
    /**
 * Create a new customer.
 */
public function storeCustomer(Request $request) 
{
    $request->validate([
        'name'     => 'required|string|max:255',
        'email'    => 'required|email|unique:users,email',
        'phone'    => 'nullable|string|max:20',
        'password' => 'required|string|min:8', 
    ]);

    try {
        $customer = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'    => $request->phone,
            'password' => Hash::make($request->password), 
            'role'     => 'customer',
            'status'   => 'active', 
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Customer created successfully',
            'data'    => $customer
        ], 201);
    } catch (\Throwable $th) {
        return response()->json([
            'status'  => 'error',
            'message' => $th->getMessage()
        ], 500);
    }
}
    /**
     * Update specific customer information.
     */
    public function updateCustomer(Request $request, $id) 
    {
        // Validate incoming request data
        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $id,
            'phone' => 'nullable|string|max:20',
        ]);

        try {
            $user = User::findOrFail($id);
            
            // Update only authorized fields
            $user->update($request->only(['name', 'email', 'phone']));
            
            return response()->json([
                'status'  => 'success',
                'message' => 'Customer updated successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update customer: ' . $th->getMessage()
            ], 500);
        }
    }
    // delete customer 
    public function destroyCustomer($id) 
{
    try {
        $customer = User::findOrFail($id);
        
        // ធានាថាលុបតែ User ដែលមាន role ជា customer ប៉ុណ្ណោះ
        if ($customer->role !== 'customer') {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized action'], 403);
        }

        $customer->delete();
        
        return response()->json([
            'status'  => 'success',
            'message' => 'Customer removed successfully'
        ], 200);
    } catch (\Throwable $th) {
        return response()->json([
            'status'  => 'error',
            'message' => $th->getMessage()
        ], 500);
        }
    }
    /**
     * Toggle status between 'active' and 'blocked'.
     */
    public function toggleBlockStatus(Request $request, $id) 
    {
        try {
            $customer = User::findOrFail($id);
            if ($customer->role !== 'customer') {
                return response()->json([
                    'status' => 'error', 
                    'message' => 'Unauthorized action'
                ], 403);
            }

            // change status: if blocked to active, if active to blocked
            $newStatus = ($customer->status === 'blocked') ? 'active' : 'blocked';
            $customer->update(['status' => $newStatus]);
            
            return response()->json([
                'status'  => 'success',
                'message' => 'Customer has been ' . $newStatus . ' successfully'
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status'  => 'error',
                'message' => $th->getMessage()
            ], 500);
        }
    }
}