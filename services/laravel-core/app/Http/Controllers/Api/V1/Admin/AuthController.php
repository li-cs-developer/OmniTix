<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizerResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/v1/admin/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'sometimes|string|max:120',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($user->is_banned) {
            return response()->json([
                'error' => 'banned',
                'message' => 'This account has been suspended.',
            ], 403);
        }

        // Only staff members can log in to the admin API
        if (! $user->isStaff()) {
            return response()->json([
                'error' => 'forbidden',
                'message' => 'This account does not have admin access.',
            ], 403);
        }

        $token = $user->createToken(
            $validated['device_name'] ?? 'admin-api',
        )->plainTextToken;

        $user->update(['last_login_at' => now()]);

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user->load('memberships.organizer')),
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * GET /api/v1/admin/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => new UserResource(
                $user->load('organizer', 'memberships.organizer'),
            ),
        ]);
    }
}