<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\User;
use App\Support\Vietnamese;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->isMethod('post')) {
            $action = $request->input('action');
            if ($action === 'delete') {
                Department::findOrFail($request->integer('dept_id'))->delete();

                return back()->with('success', 'Đã xóa Tổ/Nhóm.');
            }
            $department = $action === 'edit'
                ? Department::findOrFail($request->integer('dept_id'))
                : new Department;
            $data = $request->validate([
                'name' => ['required', 'string', 'max:150', Rule::unique('departments')->ignore($department->id)],
                'description' => ['nullable', 'string'],
                'leader_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->where('is_active', true))],
                'badge_color' => ['required', Rule::in(array_keys(Department::BADGE_COLORS))],
            ]);
            $department->fill($data)->save();
            $department->ensureLeaderIsMember();

            return redirect()->route('manager_departments')->with('success', $action === 'edit' ? 'Đã cập nhật Tổ/Nhóm.' : 'Đã thêm Tổ/Nhóm.');
        }

        $departments = Department::with(['leader', 'members'])->orderBy('name')->get();
        $editDepartment = $request->query('action') === 'edit' ? $departments->firstWhere('id', $request->integer('dept_id')) : null;

        return view('manager.departments', [
            'departments' => $departments,
            'staff' => User::where('is_active', true)->where('is_manager', false)->get(),
            'editDepartment' => $editDepartment,
            'badgeColors' => Department::BADGE_COLORS,
        ]);
    }

    public function members(Department $department): JsonResponse
    {
        $department->load('members');
        $members = Vietnamese::sortUsers($department->members)
            ->map(fn (User $user) => $this->memberPayload($department, $user))
            ->values();

        return response()->json([
            'department' => ['id' => $department->id, 'name' => $department->name],
            'members' => $members,
        ]);
    }

    public function searchMemberCandidates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ]);
        $term = trim($data['q'] ?? '');
        if (mb_strlen($term) < 2) {
            return response()->json(['users' => []]);
        }

        $query = User::where('is_active', true)
            ->where('is_manager', false)
            ->whereDoesntHave('departments', fn ($q) => $q->where('departments.id', $data['department_id']))
            ->where(fn ($q) => $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('username', 'like', "%{$term}%")
                ->orWhere('position', 'like', "%{$term}%"));

        $users = Vietnamese::sortUsers($query->get())->take(10)
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->full_name_vn,
                'position' => $user->position ?: 'Chưa có chức danh',
                'avatar' => $user->avatar_url,
            ])->values();

        return response()->json(['users' => $users]);
    }

    public function addMember(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', Rule::exists('users', 'id')->where(
            fn ($q) => $q->where('is_active', true)->where('is_manager', false)
        )]]);
        if ($department->members()->where('users.id', $data['user_id'])->exists()) {
            return response()->json(['ok' => false, 'error' => 'Người này đã là thành viên của tổ.'], 422);
        }
        $department->members()->attach($data['user_id']);
        $user = User::findOrFail($data['user_id']);

        return response()->json([
            'ok' => true,
            'message' => 'Đã thêm thành viên.',
            'member' => $this->memberPayload($department, $user),
            'member_count' => $department->member_count,
        ]);
    }

    public function removeMember(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        if ((int) $department->leader_id === (int) $data['user_id']) {
            return response()->json(['ok' => false, 'error' => 'Không thể gỡ Trưởng tổ. Hãy chỉ định Trưởng tổ khác trước.'], 422);
        }
        if (! $department->members()->where('users.id', $data['user_id'])->exists()) {
            return response()->json(['ok' => false, 'error' => 'Người này không thuộc tổ.'], 422);
        }
        $department->members()->detach($data['user_id']);

        return response()->json(['ok' => true, 'user_id' => (int) $data['user_id'], 'member_count' => $department->member_count]);
    }

    public function destroyMember(Department $department, User $user): JsonResponse
    {
        if ((int) $department->leader_id === (int) $user->id) {
            return response()->json(['message' => 'Không thể xóa Trưởng tổ. Hãy chỉ định Trưởng tổ khác trước.'], 422);
        }
        if (! $department->members()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'Người này không thuộc tổ.'], 404);
        }

        $department->members()->detach($user->id);

        return response()->json([
            'message' => 'Đã xóa thành viên khỏi Tổ/Nhóm.',
            'user_id' => $user->id,
            'member_count' => $department->member_count,
        ]);
    }

    private function memberPayload(Department $department, User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->full_name_vn,
            'username' => $user->username,
            'position' => $user->position ?: 'Chưa có chức danh',
            'avatar' => $user->avatar_url,
            'is_leader' => (int) $department->leader_id === (int) $user->id,
        ];
    }
}
