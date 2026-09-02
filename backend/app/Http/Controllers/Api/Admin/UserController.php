<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'role' => ['nullable', 'string', 'exists:roles,name'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE,SUSPENDED'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()->with('role');

        if (! empty($validated['role'])) {
            $query->whereHas('role', fn ($q) => $q->where('name', $validated['role']));
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('name', 'like', '%' . $validated['search'] . '%')
                    ->orWhere('username', 'like', '%' . $validated['search'] . '%')
                    ->orWhere('employee_code', 'like', '%' . $validated['search'] . '%');
            });
        }

        $paginated = $query->orderBy('name')->paginate($validated['per_page'] ?? 15);

        $items = $paginated->map(fn (User $user) => [
            'id' => $user->id,
            'employee_code' => $user->employee_code,
            'name' => $user->name,
            'username' => $user->username,
            'phone' => $user->phone,
            'photo' => $user->photo,
            'status' => $user->status,
            'role' => $user->role?->name,
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ]);

        return ApiResponse::success($items->all(), 'Sukses', 200, [
            'current_page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'last_page' => $paginated->lastPage(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'employee_code' => ['nullable', 'string', 'max:50', 'unique:users,employee_code'],
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:30'],
            'photo' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE,SUSPENDED'],
        ]);

        $user = User::create([
            'role_id' => $validated['role_id'],
            'employee_code' => $validated['employee_code'] ?? null,
            'name' => $validated['name'],
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'photo' => $validated['photo'] ?? null,
            'status' => $validated['status'] ?? 'ACTIVE',
        ]);

        $this->audit->created('User', $user->id, $user->only([
            'id', 'role_id', 'employee_code', 'name', 'username', 'status',
        ]));

        return ApiResponse::created($this->userPayload($user), 'Pengguna berhasil dibuat');
    }

    public function show(Request $request, int $id)
    {
        $user = User::with('role')->findOrFail($id);

        return ApiResponse::success($this->userPayload($user));
    }

    public function update(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
            'employee_code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('users', 'employee_code')->ignore($user->id)],
            'name' => ['sometimes', 'string', 'max:150'],
            'username' => ['sometimes', 'string', 'max:100', Rule::unique('users', 'username')->ignore($user->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'photo' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,INACTIVE,SUSPENDED'],
        ]);

        $old = $user->only(['role_id', 'employee_code', 'name', 'username', 'phone', 'photo', 'status']);

        $user->fill($validated);

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        $this->audit->updated('User', $user->id, $old, $user->only([
            'role_id', 'employee_code', 'name', 'username', 'phone', 'photo', 'status',
        ]));

        return ApiResponse::success($this->userPayload($user), 'Pengguna berhasil diperbarui');
    }

    public function destroy(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return ApiResponse::error('Tidak dapat menghapus akun sendiri', 'SELF_DELETE', 422);
        }

        $old = $user->only(['id', 'name', 'username', 'employee_code']);
        $user->delete();

        $this->audit->deleted('User', $id, $old);

        return ApiResponse::success(null, 'Pengguna berhasil dihapus');
    }

    public function roles()
    {
        $roles = Role::orderBy('id')->get(['id', 'name', 'description']);

        return ApiResponse::success($roles);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'role_id' => $user->role_id,
            'role' => $user->role?->name,
            'employee_code' => $user->employee_code,
            'name' => $user->name,
            'username' => $user->username,
            'phone' => $user->phone,
            'photo' => $user->photo,
            'status' => $user->status,
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
