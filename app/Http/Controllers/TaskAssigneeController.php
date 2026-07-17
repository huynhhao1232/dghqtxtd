<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskRemovedNotification;
use App\Support\TaskStatus;
use App\Support\Vietnamese;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaskAssigneeController extends Controller
{
    public function index(Task $task): JsonResponse
    {
        $this->assertRootTask($task);

        return response()->json([
            'mode' => $this->mode($task),
            'assignees' => $this->currentAssignees($task),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
        ]);
        $task = Task::findOrFail($data['task_id']);
        $this->assertRootTask($task);
        $term = trim($data['q'] ?? '');

        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        if ($this->mode($task) === 'user') {
            $excluded = $task->assignments()->whereNotNull('assignee_id')->pluck('assignee_id');
            $users = User::query()
                ->where('is_active', true)
                ->where('is_manager', false)
                ->whereNotIn('id', $excluded)
                ->where(fn ($query) => $query
                    ->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('username', 'like', "%{$term}%")
                    ->orWhere('position', 'like', "%{$term}%"))
                ->get();

            $results = Vietnamese::sortUsers($users)->take(10)
                ->map(fn (User $user) => $this->userPayload($user));
        } else {
            $excluded = $this->departmentIds($task);
            $results = Department::query()
                ->with('leader')
                ->whereNotNull('leader_id')
                ->whereNotIn('id', $excluded)
                ->where('name', 'like', "%{$term}%")
                ->orderBy('name')
                ->limit(10)
                ->get()
                ->map(fn (Department $department) => $this->departmentPayload($department));
        }

        return response()->json(['results' => $results->values()]);
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $this->assertRootTask($task);
        $this->assertModifiable($task);
        $data = $request->validate([
            'type' => ['required', Rule::in(['user', 'dept'])],
            'id' => ['required', 'integer'],
        ]);
        $mode = $this->mode($task);

        if ($data['type'] !== $mode) {
            throw ValidationException::withMessages([
                'type' => $mode === 'user'
                    ? 'Nhiệm vụ cá nhân chỉ có thể thêm cá nhân.'
                    : 'Nhiệm vụ theo Tổ/Nhóm chỉ có thể thêm Tổ/Nhóm.',
            ]);
        }

        $notificationUsers = $mode === 'user'
            ? collect([User::findOrFail($data['id'])])
            : $this->departmentNotificationUsers(Department::findOrFail($data['id']));
        $created = false;

        $assignee = DB::transaction(function () use ($data, $mode, $task, &$created): array {
            if ($mode === 'user') {
                $user = User::query()
                    ->where('is_active', true)
                    ->where('is_manager', false)
                    ->findOrFail($data['id']);
                $assignment = TaskAssignment::firstOrNew([
                    'task_id' => $task->id,
                    'assignee_id' => $user->id,
                ]);
                if (! $assignment->exists) {
                    $assignment->suppressCreatedNotification = true;
                    $assignment->save();
                    $created = true;
                }

                return $this->userPayload($user);
            }

            $department = Department::with('leader')
                ->whereNotNull('leader_id')
                ->findOrFail($data['id']);
            if (! $department->leader?->is_active) {
                throw ValidationException::withMessages([
                    'id' => 'Trưởng tổ không hoạt động nên không thể giao nhiệm vụ.',
                ]);
            }

            if ($task->isTeamTask()) {
                if ((int) $task->primary_department_id === (int) $department->id) {
                    throw ValidationException::withMessages(['id' => 'Tổ này đã là Tổ chủ trì.']);
                }
                $created = ! $task->coordinatingDepartments()->whereKey($department->id)->exists();
                $task->coordinatingDepartments()->syncWithoutDetaching([$department->id]);
                $assignment = TaskAssignment::firstOrNew([
                    'task_id' => $task->id,
                    'assignee_id' => $department->leader_id,
                ]);
                if (! $assignment->exists) {
                    $assignment->suppressCreatedNotification = true;
                    $assignment->save();
                }
            } else {
                $assignment = TaskAssignment::firstOrNew([
                    'task_id' => $task->id,
                    'assignee_department_id' => $department->id,
                ]);
                if (! $assignment->exists) {
                    $assignment->isBatchDepartmentAssignment = true;
                    $assignment->suppressCreatedNotification = true;
                    $assignment->save();
                    $created = true;
                }
            }

            return $this->departmentPayload($department);
        });

        if ($created) {
            Notification::send($notificationUsers, new TaskAssignedNotification($task));
        }

        return response()->json([
            'message' => 'Đã thêm người thực hiện.',
            'assignee' => $assignee,
        ], 201);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->assertRootTask($task);
        $this->assertModifiable($task);
        $data = $request->validate([
            'type' => ['required', Rule::in(['user', 'dept'])],
            'id' => ['required', 'integer'],
        ]);
        $mode = $this->mode($task);

        if ($data['type'] !== $mode) {
            throw ValidationException::withMessages(['type' => 'Đối tượng không phù hợp với hình thức giao việc.']);
        }
        if ($this->currentAssignees($task)->count() <= 1) {
            throw ValidationException::withMessages(['assignee' => 'Nhiệm vụ phải còn ít nhất một người thực hiện.']);
        }

        $notificationUsers = $mode === 'user'
            ? collect([User::findOrFail($data['id'])])
            : $this->departmentNotificationUsers(Department::findOrFail($data['id']));

        DB::transaction(function () use ($data, $mode, $task): void {
            if ($mode === 'user') {
                $task->assignments()
                    ->where('assignee_id', $data['id'])
                    ->firstOrFail()
                    ->delete();

                return;
            }

            $department = Department::with('leader')->findOrFail($data['id']);
            if ($task->isTeamTask()) {
                if ((int) $task->primary_department_id === (int) $department->id) {
                    throw ValidationException::withMessages(['assignee' => 'Không thể xóa Tổ chủ trì.']);
                }
                if (! $task->coordinatingDepartments()->whereKey($department->id)->exists()) {
                    abort(404);
                }

                $task->coordinatingDepartments()->detach($department->id);
                $task->participations()->where('department_id', $department->id)->delete();
                $task->coordinatingProofs()->where('department_id', $department->id)->delete();

                $leaderStillRepresentsTask = (int) $task->primaryDepartment?->leader_id === (int) $department->leader_id
                    || $task->coordinatingDepartments()->where('leader_id', $department->leader_id)->exists();
                if (! $leaderStillRepresentsTask) {
                    $task->assignments()->where('assignee_id', $department->leader_id)->delete();
                }
            } else {
                if ($task->subtasks()->where('scope_department_id', $department->id)->exists()) {
                    throw ValidationException::withMessages([
                        'assignee' => 'Không thể xóa Tổ/Nhóm đang có nhiệm vụ con.',
                    ]);
                }
                $task->assignments()
                    ->where('assignee_department_id', $department->id)
                    ->firstOrFail()
                    ->delete();
                $task->participations()->where('department_id', $department->id)->delete();
            }
        });

        Notification::send($notificationUsers, new TaskRemovedNotification($task));

        return response()->json(['message' => 'Đã xóa người thực hiện khỏi nhiệm vụ.']);
    }

    private function departmentNotificationUsers(Department $department)
    {
        $department->loadMissing(['leader', 'members']);

        return $department->members
            ->push($department->leader)
            ->filter(fn (?User $user): bool => $user?->is_active === true)
            ->unique('id')
            ->values();
    }

    private function currentAssignees(Task $task)
    {
        if ($task->isTeamTask()) {
            $task->loadMissing(['primaryDepartment.leader', 'coordinatingDepartments.leader']);
            $departments = collect([$task->primaryDepartment])
                ->merge($task->coordinatingDepartments)
                ->filter()
                ->unique('id');

            return $departments->map(function (Department $department) use ($task): array {
                return $this->departmentPayload(
                    $department,
                    (int) $task->primary_department_id !== (int) $department->id
                );
            })->values();
        }

        if ($task->isBatchDepartmentTask()) {
            return $task->assignments()
                ->with('assigneeDepartment.leader')
                ->whereNotNull('assignee_department_id')
                ->get()
                ->map(fn (TaskAssignment $assignment) => $this->departmentPayload($assignment->assigneeDepartment))
                ->values();
        }

        return $task->assignments()
            ->with('assignee')
            ->whereNotNull('assignee_id')
            ->get()
            ->map(fn (TaskAssignment $assignment) => $this->userPayload($assignment->assignee))
            ->values();
    }

    private function departmentIds(Task $task): array
    {
        if ($task->isTeamTask()) {
            return collect([$task->primary_department_id])
                ->merge($task->coordinatingDepartments()->pluck('departments.id'))
                ->filter()->map(fn ($id) => (int) $id)->all();
        }

        return $task->assignments()
            ->whereNotNull('assignee_department_id')
            ->pluck('assignee_department_id')
            ->map(fn ($id) => (int) $id)->all();
    }

    private function mode(Task $task): string
    {
        return $task->isTeamTask() || $task->isBatchDepartmentTask() ? 'dept' : 'user';
    }

    private function assertRootTask(Task $task): void
    {
        abort_if($task->parent_task_id, 404);
    }

    private function assertModifiable(Task $task): void
    {
        if ($task->assignments()->whereIn('status', [TaskStatus::PENDING, TaskStatus::COMPLETED])->exists()) {
            throw ValidationException::withMessages([
                'assignee' => 'Không thể đổi người thực hiện sau khi nhiệm vụ đã được nộp hoặc nghiệm thu.',
            ]);
        }
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'type' => 'user',
            'name' => $user->full_name_vn,
            'subtitle' => $user->position ?: $user->username,
            'avatar' => $user->avatar_url,
            'can_remove' => true,
        ];
    }

    private function departmentPayload(Department $department, bool $canRemove = true): array
    {
        $department->loadMissing('leader');
        $avatar = $department->leader?->avatar_url
            ?: 'https://ui-avatars.com/api/?name='.rawurlencode($department->name).'&background=7c3aed&color=fff';

        return [
            'id' => $department->id,
            'type' => 'dept',
            'name' => $department->name,
            'subtitle' => $department->leader ? 'Trưởng tổ: '.$department->leader->full_name_vn : 'Chưa có trưởng tổ',
            'avatar' => $avatar,
            'can_remove' => $canRemove,
        ];
    }
}
