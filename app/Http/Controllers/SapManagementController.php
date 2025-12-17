<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\UserSap;
use Exception;

class SapManagementController extends Controller
{
    /**
     * Check if authenticated user is IT department
     */
    private function isItDepartment(Request $request)
    {
        $user = $request->user();
        return $user && strtoupper($user->department) === 'IT';
    }

    /**
     * Get all users (IT only)
     */
    public function index(Request $request)
    {
        if (!$this->isItDepartment($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. IT department only.'
            ], 403);
        }

        try {
            $users = UserSap::orderBy('user_id', 'asc')->get()->map(function($user) {
                return [
                    'user_id' => $user->user_id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'full_name' => $user->full_name,
                    'jabatan' => $user->jabatan,
                    'department' => $user->department,
                    'email' => $user->email,
                    'status' => $user->status,
                    'id_card' => $user->id_card ?? null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Users retrieved successfully',
                'data' => $users
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new user (IT only)
     */
    /**
 * Create new user (IT only)
 */
public function store(Request $request)
{
    if (!$this->isItDepartment($request)) {
        return response()->json([
            'success' => false,
            'message' => 'Access denied. IT department only.'
        ], 403);
    }

    $validator = Validator::make($request->all(), [
        'first_name' => 'required|string|max:50',
        'last_name' => 'required|string|max:50',
        'jabatan' => 'required|string|max:100',
        'department' => 'required|string|max:100',
        'email' => 'required|email|max:100|unique:pgsql_second.users,email',
        'password' => 'required|string|min:6',  // ✅ CHANGED: Manual password required
        'id_card' => 'nullable|string|max:50|unique:pgsql_second.users,id_card',
        'status' => 'nullable|in:active,inactive',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        // Get next user_id from sequence
        $nextId = \DB::connection('pgsql_second')
            ->select("SELECT nextval('users_user_id_seq') as id")[0]->id;
        $userId = 'OJSAIT' . str_pad($nextId, 3, '0', STR_PAD_LEFT);

        $user = UserSap::create([
            'user_id' => $userId,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'full_name' => $request->first_name . ' ' . $request->last_name,
            'jabatan' => $request->jabatan,
            'department' => $request->department,
            'email' => $request->email,
            'password' => Hash::make($request->password),  
            'id_card' => $request->id_card,
            'status' => $request->status ?? 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => [  // ✅ CHANGED: Simplified response (no nested 'user')
                'user_id' => $user->user_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'full_name' => $user->full_name,
                'jabatan' => $user->jabatan,
                'department' => $user->department,
                'email' => $user->email,
                'status' => $user->status,
                'id_card' => $user->id_card,
            ]
        ], 201);
    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to create user',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Update user (IT only)
     */
    public function update(Request $request, $userId)
    {
        if (!$this->isItDepartment($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. IT department only.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|required|string|max:50',
            'last_name' => 'sometimes|required|string|max:50',
            'jabatan' => 'sometimes|required|string|max:100',
            'department' => 'sometimes|required|string|max:100',
            'email' => 'sometimes|required|email|max:100|unique:pgsql_second.users,email,' . $userId . ',user_id',
            'id_card' => 'nullable|string|max:50|unique:pgsql_second.users,id_card,' . $userId . ',user_id',
            'status' => 'sometimes|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = UserSap::where('user_id', $userId)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $updateData = $request->only([
                'first_name', 'last_name', 'jabatan', 
                'department', 'email', 'id_card', 'status'
            ]);

            // Update full_name if first_name or last_name changed
            if (isset($updateData['first_name']) || isset($updateData['last_name'])) {
                $firstName = $updateData['first_name'] ?? $user->first_name;
                $lastName = $updateData['last_name'] ?? $user->last_name;
                $updateData['full_name'] = $firstName . ' ' . $lastName;
            }

            $user->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => [
                    'user_id' => $user->user_id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'full_name' => $user->full_name,
                    'jabatan' => $user->jabatan,
                    'department' => $user->department,
                    'email' => $user->email,
                    'status' => $user->status,
                    'id_card' => $user->id_card,
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset user password (IT only)
     */
   /**
 * Change user password (IT only)
 */
public function changePassword(Request $request, $userId)
{
    if (!$this->isItDepartment($request)) {
        return response()->json([
            'success' => false,
            'message' => 'Access denied. IT department only.'
        ], 403);
    }

    $validator = Validator::make($request->all(), [
        'new_password' => 'required|string|min:6',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        $user = UserSap::where('user_id', $userId)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully',
            'data' => [
                'user_id' => $user->user_id,
                'full_name' => $user->full_name,
            ]
        ]);
    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to change password',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Deactivate user (soft delete by setting status to inactive)
     */
    public function deactivate(Request $request, $userId)
    {
        if (!$this->isItDepartment($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. IT department only.'
            ], 403);
        }

        try {
            $user = UserSap::where('user_id', $userId)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $user->update(['status' => 'inactive']);

            return response()->json([
                'success' => true,
                'message' => 'User deactivated successfully',
                'data' => [
                    'user_id' => $user->user_id,
                    'full_name' => $user->full_name,
                    'status' => $user->status
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate random secure password
     */
    private function generatePassword($length = 12)
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '!@#$%^&*';
        
        $password = '';
        $password .= $uppercase[rand(0, strlen($uppercase) - 1)];
        $password .= $lowercase[rand(0, strlen($lowercase) - 1)];
        $password .= $numbers[rand(0, strlen($numbers) - 1)];
        $password .= $special[rand(0, strlen($special) - 1)];
        
        $all = $uppercase . $lowercase . $numbers . $special;
        for ($i = 4; $i < $length; $i++) {
            $password .= $all[rand(0, strlen($all) - 1)];
        }
        
        return str_shuffle($password);
    }
}