<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * POST /auth/login
     * Login for all roles. Security clients register their device here.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_uuid' => ['nullable', 'string', 'max:150'],
            'device_name' => ['nullable', 'string', 'max:150'],
            'platform' => ['nullable', 'string', 'max:30'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ]);

        $user = User::query()
            ->with('role')
            ->where('username', $validated['username'])
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Username atau password salah'],
            ]);
        }

        if ($user->status !== 'ACTIVE') {
            return ApiResponse::error('Akun anda tidak aktif', 'USER_INACTIVE', 403);
        }

        // Security users must present a device; supervisors/admins may skip.
        $isSecurity = $user->role?->name === 'security';

        if ($isSecurity && blank($validated['device_uuid'] ?? null)) {
            return ApiResponse::error('device_uuid wajib diisi untuk perangkat Android', 'DEVICE_REQUIRED', 422);
        }

        $device = null;
        if (! blank($validated['device_uuid'] ?? null)) {
            $device = Device::where('device_uuid', $validated['device_uuid'])->first();

            if ($device && $device->user_id !== $user->id) {
                return ApiResponse::error('Perangkat sudah terdaftar untuk pengguna lain', 'DEVICE_OWNED', 403);
            }
            if ($device && $device->status === 'BLOCKED') {
                return ApiResponse::error('Perangkat diblokir', 'DEVICE_BLOCKED', 403);
            }

            $device = Device::updateOrCreate(
                ['device_uuid' => $validated['device_uuid']],
                [
                    'user_id' => $user->id,
                    'device_name' => $validated['device_name'] ?? $device?->device_name,
                    'platform' => $validated['platform'] ?? 'android',
                    'app_version' => $validated['app_version'] ?? null,
                    'last_seen_at' => now(),
                    'status' => 'ACTIVE',
                ],
            );
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('auth-token', $this->tokenAbilities($user))->plainTextToken;

        $this->audit->log('LOGIN', User::class, $user->id, null, [
            'role' => $user->role?->name,
            'device_uuid' => $validated['device_uuid'] ?? null,
        ], $user);

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'employee_code' => $user->employee_code,
                'phone' => $user->phone,
                'photo' => $user->photo,
                'role' => $user->role?->name,
            ],
            'device' => $device ? [
                'id' => $device->id,
                'device_uuid' => $device->device_uuid,
                'status' => $device->status,
            ] : null,
        ], 'Login berhasil');
    }

    /**
     * POST /auth/logout
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        // revoke the current token
        $request->user()->currentAccessToken()->delete();

        $this->audit->log('LOGOUT', User::class, $user->id, null, null, $user);

        return ApiResponse::success(null, 'Logout berhasil');
    }

    /**
     * GET /me
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('role');

        return ApiResponse::success([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'employee_code' => $user->employee_code,
            'phone' => $user->phone,
            'photo' => $user->photo,
            'role' => $user->role?->name,
            'status' => $user->status,
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
        ]);
    }

    private function tokenAbilities(User $user): array
    {
        $role = $user->role?->name;

        return match ($role) {
            'super_admin' => ['*'],
            'supervisor' => ['dashboard', 'patrol:read', 'report:read'],
            default => ['patrol:write', 'patrol:read'],
        };
    }
}
