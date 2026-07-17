<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Support\Vietnamese;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StaffAdminController extends Controller
{
    public function index(Request $request): View
    {
        $query = User::with('departments');
        if ($request->filled('department')) {
            $query->whereHas('departments', fn ($q) => $q->where('departments.id', $request->integer('department')));
        }
        if ($term = trim((string) $request->query('q'))) {
            $query->where(fn ($q) => $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")->orWhere('username', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")->orWhere('position', 'like', "%{$term}%"));
        }

        $sortedUsers = Vietnamese::sortUsers($query->get());
        $page = max(1, $request->integer('page', 1));
        $perPage = 10;
        $users = new LengthAwarePaginator(
            $sortedUsers->forPage($page, $perPage)->values(),
            $sortedUsers->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('manager.staff_list', [
            'users' => $users,
            'departments' => Department::orderBy('name')->get(),
            'editUser' => $request->query('action') === 'edit' ? User::with('departments')->find($request->integer('staff_id')) : null,
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        $data = $this->validateUser($request);
        $departmentIds = $data['departments'] ?? [];
        unset($data['departments'], $data['role'], $data['password_confirmation']);
        $data['is_manager'] = $request->input('role') === 'manager';
        $data['is_staff'] = true;
        $data['is_active'] = true;
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        $user->departments()->sync($departmentIds);

        return redirect()->route('manager_staff')->with('success', 'Đã tạo tài khoản.');
    }

    public function edit(Request $request, User $user): RedirectResponse
    {
        $data = $this->validateUser($request, $user);
        $departmentIds = $data['departments'] ?? [];
        unset($data['departments'], $data['role'], $data['password_confirmation']);
        $data['is_manager'] = $request->input('role') === 'manager';
        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }
        $user->update($data);
        $user->departments()->sync($departmentIds);

        return redirect()->route('manager_staff')->with('success', 'Đã cập nhật tài khoản.');
    }

    public function showApi(User $user): JsonResponse
    {
        $user->load('departments:id,name');

        return response()->json(['user' => $this->apiPayload($user)]);
    }

    public function updateApi(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:300'],
            'username' => ['required', 'string', 'max:150', Rule::unique('users')->ignore($user->id)],
            'role' => ['required', Rule::in(['staff', 'manager'])],
            'is_active' => ['required', 'boolean'],
            'departments' => ['present', 'array'],
            'departments.*' => ['integer', 'distinct', 'exists:departments,id'],
        ]);

        [$givenName, $middleName, $surname] = Vietnamese::parseVietnameseName($data['name']);

        DB::transaction(function () use ($data, $givenName, $middleName, $surname, $user): void {
            $user->update([
                'first_name' => trim($middleName.' '.$givenName),
                'last_name' => $surname,
                'username' => $data['username'],
                'is_manager' => $data['role'] === 'manager',
                'is_active' => $data['is_active'],
            ]);
            $user->departments()->sync($data['departments']);
        });

        $user->load('departments:id,name');

        return response()->json([
            'message' => 'Đã cập nhật thành công.',
            'user' => $this->apiPayload($user),
        ]);
    }

    public function toggleActive(User $user): RedirectResponse
    {
        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', 'Đã '.($user->is_active ? 'mở khóa' : 'khóa').' tài khoản.');
    }

    private function validateUser(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:150', Rule::unique('users')->ignore($user?->id)],
            'first_name' => ['required', 'string', 'max:150'],
            'last_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'position' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(['staff', 'manager'])],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', 'min:8'],
            'departments' => ['nullable', 'array'],
            'departments.*' => ['integer', 'exists:departments,id'],
        ]);

        // NOT NULL columns with defaults: empty input becomes null via middleware.
        $data['last_name'] = $data['last_name'] ?? '';
        $data['position'] = $data['position'] ?? '';
        $data['phone'] = $data['phone'] ?? '';

        return $data;
    }

    private function apiPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->full_name_vn,
            'username' => $user->username,
            'role' => $user->is_manager ? 'manager' : 'staff',
            'role_label' => $user->role_label,
            'is_active' => $user->is_active,
            'departments' => $user->departments->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
            ])->values(),
        ];
    }
}
