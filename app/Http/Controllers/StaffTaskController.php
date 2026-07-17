<?php

namespace App\Http\Controllers;

use App\Models\CoordinatingProof;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskParticipation;
use App\Models\User;
use App\Notifications\RealtimeTaskNotification;
use App\Support\EvaluationResult;
use App\Support\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StaffTaskController extends Controller
{
    public function myTasks(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectManager($request)) {
            return $redirect;
        }

        $status = $request->query('status');
        $query = $this->accessibleAssignments($request->user()->id)
            ->orderBy(
                Task::query()->select('deadline')->whereColumn('tasks.id', 'task_assignments.task_id')
            );

        if ($status !== null && array_key_exists($status, TaskStatus::CHOICES)) {
            $query->where('status', $status);
        } else {
            $status = null;
        }

        return view('staff.my_tasks', [
            'assignments' => $query->get(),
            'statusFilter' => $status,
            'statusChoices' => TaskStatus::CHOICES,
        ]);
    }

    public function detail(Request $request, int $pk): View|RedirectResponse
    {
        if ($redirect = $this->redirectManager($request)) {
            return $redirect;
        }

        $assignment = $this->staffAssignmentOrFail($request, $pk);
        $task = $assignment->task;
        $user = $request->user();
        $isPrimaryLeader = $task->isPrimaryLeader($user);
        $isCoordLeader = $task->isCoordinatingLeader($user);
        $coordDepartment = $isCoordLeader ? $task->getCoordinatingDepartmentFor($user) : null;
        $batchDepartment = $this->batchDepartmentForAssignment($assignment, $user);
        $isBatchLeader = $batchDepartment !== null;
        $canUpdate = $assignment->status !== TaskStatus::COMPLETED && $task->canUpdateProgress($user);
        $canSubmitCoordProof = $task->isTeamTask()
            && $isCoordLeader
            && ! in_array($assignment->status, [TaskStatus::COMPLETED, TaskStatus::PENDING], true);

        $workAssignment = $assignment;
        if ($task->isTeamTask() && $canUpdate) {
            $workAssignment = $task->getCanonicalAssignment() ?? $assignment;
        }

        if ($request->isMethod('post')) {
            return $this->handleDetailAction(
                $request,
                $assignment,
                $workAssignment,
                $coordDepartment,
                $isPrimaryLeader,
                $canUpdate,
                $canSubmitCoordProof,
            );
        }

        $displayAssignment = $task->isTeamTask()
            ? ($task->getCanonicalAssignment() ?? $assignment)
            : $assignment;

        $leadParticipations = $task->isTeamTask()
            ? $task->participations()->with(['user', 'department'])
                ->where('role', TaskParticipation::ROLE_LEAD)->get()
            : collect();
        $coordParticipations = $task->isTeamTask()
            ? $task->participations()->with(['user', 'department'])
                ->where('role', TaskParticipation::ROLE_COORD)->get()
            : collect();
        $myParticipations = $task->participationsForLeader($user)->get();
        $evaluationParticipations = $task->participationsForInternalEvaluation($user)->get();
        $coordProofs = $task->isTeamTask()
            ? $task->coordinatingProofs()->with(['department', 'uploadedBy'])->get()
            : collect();
        $myCoordProof = $coordDepartment
            ? $coordProofs->firstWhere('department_id', $coordDepartment->id)
            : null;

        $canManageSubtasks = $task->canManageSubtasks($user);
        [$subtaskRows, $subtaskDone, $subtaskTotal, $subtaskPercent] = $this->subtaskData(
            $task,
            $isPrimaryLeader,
            $isBatchLeader,
            $batchDepartment,
        );

        $managedDepartment = $this->managedDepartment($assignment, $user);
        $availableMembers = collect();
        if (
            $managedDepartment
            && ! in_array($displayAssignment->status, [TaskStatus::PENDING, TaskStatus::COMPLETED], true)
        ) {
            $excluded = $task->participations()->pluck('user_id')
                ->merge($task->assignments()->whereNotNull('assignee_id')->pluck('assignee_id'))
                ->push($user->id)
                ->unique();
            $availableMembers = User::query()
                ->where('is_active', true)
                ->where('is_manager', false)
                ->whereHas(
                    'departments',
                    fn (Builder $query) => $query->where('departments.id', $managedDepartment->id)
                )
                ->whereNotIn('id', $excluded)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get();
        }

        $subtaskAssigneeOptions = collect();
        if ($canManageSubtasks || ($task->isTeamTask() && $isPrimaryLeader)) {
            $participants = $task->participations()->with('user');
            if ($isBatchLeader) {
                $participants->where('department_id', $batchDepartment->id);
            }
            $subtaskAssigneeOptions = $participants->get()
                ->pluck('user')
                ->filter(fn (User $member) => $member->is_active && ! $member->is_manager)
                ->unique('id')
                ->values();
        }

        return view('staff.task_detail', [
            'assignment' => $displayAssignment,
            'myAssignment' => $assignment,
            'task' => $task,
            'canUpdate' => $canUpdate,
            'canSubmitCoordProof' => $canSubmitCoordProof,
            'isPrimaryLeader' => $isPrimaryLeader,
            'isCoordLeader' => $isCoordLeader,
            'isTeamLeader' => $isPrimaryLeader || $isCoordLeader,
            'canManageMembers' => $isPrimaryLeader || $isCoordLeader || $isBatchLeader,
            'isTeamTask' => $task->isTeamTask(),
            'isDepartmentAssignment' => $assignment->is_department_target,
            'isBatchDepartmentLeader' => $isBatchLeader,
            'isSubtask' => $task->is_subtask,
            'isDelegatedUpdater' => (int) $task->delegated_updater_id === (int) $user->id,
            'coordDepartment' => $coordDepartment,
            'batchDepartment' => $batchDepartment,
            'managedDepartment' => $managedDepartment,
            'leadParticipations' => $leadParticipations,
            'coordParticipations' => $coordParticipations,
            'myParticipations' => $myParticipations,
            'evaluationParticipations' => $evaluationParticipations,
            'availableMembers' => $availableMembers,
            'coordProofs' => $coordProofs,
            'myCoordProof' => $myCoordProof,
            'needsEvaluation' => $task->needsInternalEvaluation($user),
            'openEvaluateModal' => $request->boolean('evaluate') || $task->needsInternalEvaluation($user),
            'evaluationChoices' => EvaluationResult::CHOICES,
            'canManageSubtasks' => $canManageSubtasks,
            'subtaskRows' => $subtaskRows,
            'subtaskDone' => $subtaskDone,
            'subtaskTotal' => $subtaskTotal,
            'subtaskPercent' => $subtaskPercent,
            'subtaskAssigneeOptions' => $subtaskAssigneeOptions,
            'openSubtaskModal' => $request->boolean('subtask'),
            'statusChoices' => [
                TaskStatus::TODO => TaskStatus::CHOICES[TaskStatus::TODO],
                TaskStatus::IN_PROGRESS => TaskStatus::CHOICES[TaskStatus::IN_PROGRESS],
                TaskStatus::PENDING => $task->is_subtask
                    ? 'Gửi Trưởng tổ nghiệm thu'
                    : 'Chốt tiến độ — gửi Lãnh đạo',
            ],
            'proofMaxMb' => TaskAssignment::PROOF_MAX_SIZE_MB,
        ]);
    }

    public function createSubtask(Request $request, int $pk): RedirectResponse
    {
        if ($redirect = $this->redirectManager($request)) {
            return $redirect;
        }

        $assignment = $this->staffAssignmentOrFail($request, $pk);
        $parent = $assignment->task;
        abort_unless($parent->canManageSubtasks($request->user()), 403);
        $scopeDepartment = $this->batchDepartmentForAssignment($assignment, $request->user());

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'deadline' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.$parent->deadline->format('Y-m-d')],
            'assignees' => ['required', 'array', 'min:1'],
            'assignees.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $allowed = $parent->participations()
            ->when($scopeDepartment, fn (Builder $query) => $query->where('department_id', $scopeDepartment->id))
            ->whereIn('user_id', $data['assignees'])
            ->pluck('user_id');
        abort_unless($allowed->count() === count($data['assignees']), 422, 'Người thực hiện không thuộc phạm vi được quản lý.');

        DB::transaction(function () use ($data, $parent, $scopeDepartment, $request) {
            $subtask = Task::create([
                'title' => trim($data['title']),
                'description' => '',
                'created_by_id' => $request->user()->id,
                'deadline' => $data['deadline'],
                'cycle' => $parent->cycle,
                'parent_task_id' => $parent->id,
                'scope_department_id' => $scopeDepartment?->id,
                'is_subtask' => true,
            ]);
            foreach ($data['assignees'] as $userId) {
                TaskAssignment::firstOrCreate(
                    ['task_id' => $subtask->id, 'assignee_id' => $userId],
                    ['status' => TaskStatus::TODO]
                );
            }
        });

        return redirect()->route('staff_task_detail', $pk)->with('success', 'Đã tạo và giao nhiệm vụ con.');
    }

    public function editSubtask(Request $request, int $pk): RedirectResponse
    {
        if ($redirect = $this->redirectManager($request)) {
            return $redirect;
        }

        $subtask = Task::with(['parentTask.primaryDepartment'])->where('is_subtask', true)->findOrFail($pk);
        $parent = $subtask->parentTask;
        abort_unless($parent && $this->canManageScopedSubtask($parent, $subtask, $request->user()), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'deadline' => ['required', 'date', 'before_or_equal:'.$parent->deadline->format('Y-m-d')],
        ]);
        $subtask->update(['title' => trim($data['title']), 'deadline' => $data['deadline']]);

        return $this->redirectToParent($parent, $request->user())
            ->with('success', 'Đã cập nhật nhiệm vụ con.');
    }

    public function deleteSubtask(Request $request, int $pk): RedirectResponse
    {
        if ($redirect = $this->redirectManager($request)) {
            return $redirect;
        }

        $subtask = Task::with(['parentTask.primaryDepartment'])->where('is_subtask', true)->findOrFail($pk);
        $parent = $subtask->parentTask;
        abort_unless($parent && $this->canManageScopedSubtask($parent, $subtask, $request->user()), 403);
        $subtask->delete();

        return $this->redirectToParent($parent, $request->user())
            ->with('success', 'Đã xóa nhiệm vụ con.');
    }

    public function reviewSubtask(Request $request, int $pk): RedirectResponse
    {
        if ($redirect = $this->redirectManager($request)) {
            return $redirect;
        }

        $assignment = TaskAssignment::with(['task.parentTask.primaryDepartment', 'assignee'])
            ->where('status', TaskStatus::PENDING)
            ->findOrFail($pk);
        $subtask = $assignment->task;
        $parent = $subtask->parentTask;
        abort_unless($subtask->is_subtask && $parent, 403);
        abort_unless($this->canManageScopedSubtask($parent, $subtask, $request->user()), 403);
        $data = $request->validate([
            'evaluation_result' => ['required', Rule::in(array_keys(EvaluationResult::CHOICES))],
            'manager_comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $assignment->applyReview($data['evaluation_result'], $data['manager_comment'] ?? '');
        $assignment->save();

        return $this->redirectToParent($parent, $request->user())
            ->with('success', 'Đã nghiệm thu nhiệm vụ con: '.EvaluationResult::label($data['evaluation_result']).'.');
    }

    public function addMembers(Request $request, int $pk): RedirectResponse
    {
        if ($redirect = $this->redirectManager($request)) {
            return $redirect;
        }

        $assignment = $this->staffAssignmentOrFail($request, $pk);
        $task = $assignment->task;
        abort_unless($this->isAnyTeamLeader($assignment, $request->user()), 403);
        $batchDepartment = $this->batchDepartmentForAssignment($assignment, $request->user());
        $coordDepartment = $task->getCoordinatingDepartmentFor($request->user());
        $managedDepartment = $this->managedDepartment($assignment, $request->user());
        abort_unless($managedDepartment, 403);
        $role = $coordDepartment && ! $task->isPrimaryLeader($request->user()) && ! $batchDepartment
            ? TaskParticipation::ROLE_COORD
            : TaskParticipation::ROLE_LEAD;

        $data = $request->validate([
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);
        $members = User::query()
            ->whereIn('id', $data['member_ids'])
            ->where('is_active', true)
            ->where('is_manager', false)
            ->whereHas('departments', fn (Builder $query) => $query->where('departments.id', $managedDepartment->id))
            ->get();
        abort_unless($members->count() === count($data['member_ids']), 422);

        DB::transaction(function () use ($members, $task, $managedDepartment, $role, $batchDepartment) {
            foreach ($members as $member) {
                $participation = TaskParticipation::firstOrNew([
                    'task_id' => $task->id,
                    'user_id' => $member->id,
                ]);
                if (! $participation->exists || ! $participation->department_id) {
                    $participation->department_id = $managedDepartment->id;
                }
                if (! $participation->exists || $role === TaskParticipation::ROLE_LEAD) {
                    $participation->role = $role;
                }
                $participation->save();

                if (! $batchDepartment) {
                    TaskAssignment::firstOrCreate(
                        ['task_id' => $task->id, 'assignee_id' => $member->id],
                        ['status' => TaskStatus::TODO]
                    );
                }
            }
        });

        return redirect()->route('staff_task_detail', $pk)->with('success', 'Đã thêm thành viên vào công việc.');
    }

    public function removeMember(Request $request, int $pk): RedirectResponse
    {
        if ($redirect = $this->redirectManager($request)) {
            return $redirect;
        }

        $assignment = $this->staffAssignmentOrFail($request, $pk);
        $task = $assignment->task;
        abort_unless($this->isAnyTeamLeader($assignment, $request->user()), 403);
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $managedDepartment = $this->managedDepartment($assignment, $request->user());
        abort_unless($managedDepartment, 403);
        $participation = $task->participations()
            ->where('user_id', $data['user_id'])
            ->where('department_id', $managedDepartment->id)
            ->firstOrFail();
        $batchDepartment = $this->batchDepartmentForAssignment($assignment, $request->user());

        if ($batchDepartment) {
            abort_unless((int) $participation->department_id === (int) $batchDepartment->id, 403);
        } elseif ($task->isPrimaryLeader($request->user())) {
            abort_unless($participation->role === TaskParticipation::ROLE_LEAD, 403);
        } else {
            abort_unless($participation->role === TaskParticipation::ROLE_COORD, 403);
        }

        DB::transaction(function () use ($task, $participation, $batchDepartment) {
            if ((int) $task->delegated_updater_id === (int) $participation->user_id) {
                $task->update(['delegated_updater_id' => null]);
            }
            $memberAssignment = $task->assignments()->where('assignee_id', $participation->user_id)->first();
            if (
                $memberAssignment
                && ! in_array($memberAssignment->status, [TaskStatus::PENDING, TaskStatus::COMPLETED], true)
                && ! $batchDepartment
                && ! $task->isDepartmentLeader($memberAssignment->assignee)
            ) {
                $memberAssignment->delete();
            }
            $participation->delete();
        });

        return redirect()->route('staff_task_detail', $pk)->with('success', 'Đã gỡ thành viên khỏi công việc.');
    }

    public function setDelegate(Request $request, int $pk): RedirectResponse
    {
        if ($redirect = $this->redirectManager($request)) {
            return $redirect;
        }

        $assignment = $this->staffAssignmentOrFail($request, $pk);
        $task = $assignment->task;
        abort_unless($task->isPrimaryLeader($request->user()), 403);

        if ($request->boolean('clear')) {
            $task->update(['delegated_updater_id' => null]);

            return redirect()->route('staff_task_detail', $pk)->with('success', 'Đã hủy ủy quyền báo cáo.');
        }

        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $participation = $task->participations()
            ->with('user')
            ->where('user_id', $data['user_id'])
            ->where('role', TaskParticipation::ROLE_LEAD)
            ->firstOrFail();
        $task->update(['delegated_updater_id' => $participation->user_id]);
        $participation->user->notify(new RealtimeTaskNotification(
            'Bạn được ủy quyền cập nhật tiến độ cho việc: '.$task->title,
            $task,
            'info',
            'Ủy quyền báo cáo',
        ));

        return redirect()->route('staff_task_detail', $pk)->with('success', 'Đã ủy quyền báo cáo.');
    }

    private function handleDetailAction(
        Request $request,
        TaskAssignment $assignment,
        TaskAssignment $workAssignment,
        ?Department $coordDepartment,
        bool $isPrimaryLeader,
        bool $canUpdate,
        bool $canSubmitCoordProof,
    ): RedirectResponse {
        $task = $assignment->task;
        $action = $request->input('action', 'update_progress');

        if ($action === 'update_progress') {
            abort_unless($canUpdate && $workAssignment->status !== TaskStatus::COMPLETED, 403);
            $data = $request->validate([
                'status' => ['required', Rule::in([TaskStatus::TODO, TaskStatus::IN_PROGRESS, TaskStatus::PENDING])],
                'notes' => ['nullable', 'string', 'max:5000'],
                'proof_file' => ['nullable', 'file', 'max:'.(TaskAssignment::PROOF_MAX_SIZE_MB * 1024)],
            ]);
            if ($request->hasFile('proof_file')) {
                if ($workAssignment->proof_file) {
                    Storage::disk('public')->delete($workAssignment->proof_file);
                }
                $data['proof_file'] = $request->file('proof_file')->store('task-proofs', 'public');
            } else {
                unset($data['proof_file']);
            }
            $data['submitted_at'] = $data['status'] === TaskStatus::PENDING ? now() : null;
            $workAssignment->update($data);
            if ($task->isTeamTask()) {
                $task->syncTeamAssignmentStatus($workAssignment);
            }

            return redirect()->route('staff_task_detail', $assignment->id)->with('success', 'Đã cập nhật công việc.');
        }

        if ($action === 'submit_coord_proof') {
            abort_unless($canSubmitCoordProof && $coordDepartment, 403);
            $existing = $task->coordinatingProofs()->where('department_id', $coordDepartment->id)->first();
            $data = $request->validate([
                'coord_notes' => ['nullable', 'string', 'max:5000'],
                'coord_proof_file' => [$existing ? 'nullable' : 'required', 'file', 'max:'.(CoordinatingProof::MAX_SIZE_MB * 1024)],
            ]);
            $values = [
                'uploaded_by_id' => $request->user()->id,
                'notes' => $data['coord_notes'] ?? null,
            ];
            if ($request->hasFile('coord_proof_file')) {
                if ($existing?->proof_file) {
                    Storage::disk('public')->delete($existing->proof_file);
                }
                $values['proof_file'] = $request->file('coord_proof_file')->store('coordinating-proofs', 'public');
            }
            $task->coordinatingProofs()->updateOrCreate(
                ['department_id' => $coordDepartment->id],
                $values
            );
            if ($task->primaryDepartment?->leader_id) {
                $leader = User::find($task->primaryDepartment->leader_id);
                $leader?->notify(new RealtimeTaskNotification(
                    "Tổ {$coordDepartment->name} đã nộp minh chứng phối hợp cho việc: {$task->title}",
                    $task,
                    'info',
                    'Minh chứng phối hợp',
                ));
            }

            return redirect()->route('staff_task_detail', $assignment->id)->with('success', 'Đã nộp minh chứng phối hợp.');
        }

        if ($action === 'internal_evaluate') {
            abort_unless($isPrimaryLeader, 403);
            $canonical = $task->getCanonicalAssignment();
            abort_unless($canonical && $canonical->status === TaskStatus::COMPLETED, 403);
            $participations = $task->participationsForInternalEvaluation($request->user())->get();
            $rules = [];
            foreach ($participations as $participation) {
                $rules['eval_'.$participation->id] = ['required', Rule::in(array_keys(EvaluationResult::CHOICES))];
            }
            $data = $request->validate($rules);
            DB::transaction(function () use ($participations, $data) {
                foreach ($participations as $participation) {
                    $participation->applyEvaluation($data['eval_'.$participation->id]);
                    $participation->save();
                }
            });

            return redirect()->route('staff_task_detail', $assignment->id)->with('success', 'Đã lưu đánh giá từng cá nhân.');
        }

        abort(422, 'Hành động không hợp lệ.');
    }

    private function accessibleAssignments(int $userId): Builder
    {
        return TaskAssignment::query()
            ->with([
                'task.createdBy',
                'task.primaryDepartment.leader',
                'task.coordinatingDepartments.leader',
                'task.delegatedUpdater',
                'task.parentTask',
                'assignee',
                'assigneeDepartment.leader',
            ])
            ->where(function (Builder $query) use ($userId) {
                $query->where('assignee_id', $userId)
                    ->orWhereHas(
                        'assigneeDepartment',
                        fn (Builder $department) => $department->where('leader_id', $userId)
                    );
            })
            ->distinct();
    }

    private function staffAssignmentOrFail(Request $request, int $pk): TaskAssignment
    {
        return $this->accessibleAssignments($request->user()->id)->findOrFail($pk);
    }

    private function batchDepartmentForAssignment(TaskAssignment $assignment, User $user): ?Department
    {
        $department = $assignment->assigneeDepartment;

        return $department && (int) $department->leader_id === (int) $user->id
            ? $department
            : null;
    }

    private function managedDepartment(TaskAssignment $assignment, User $user): ?Department
    {
        return $this->batchDepartmentForAssignment($assignment, $user)
            ?? ($assignment->task->isPrimaryLeader($user)
                ? $assignment->task->primaryDepartment
                : $assignment->task->getCoordinatingDepartmentFor($user));
    }

    private function isAnyTeamLeader(TaskAssignment $assignment, User $user): bool
    {
        return $assignment->task->isDepartmentLeader($user)
            || $this->batchDepartmentForAssignment($assignment, $user) !== null;
    }

    private function canManageScopedSubtask(Task $parent, Task $subtask, User $user): bool
    {
        if (! $parent->canManageSubtasks($user)) {
            return false;
        }
        if ($subtask->scope_department_id) {
            return (int) $parent->getBatchDepartmentFor($user)?->id === (int) $subtask->scope_department_id;
        }

        return $parent->isPrimaryLeader($user);
    }

    private function parentAssignmentForUser(Task $parent, User $user): ?TaskAssignment
    {
        return $parent->assignments()
            ->where(function (Builder $query) use ($user) {
                $query->where('assignee_id', $user->id)
                    ->orWhereHas(
                        'assigneeDepartment',
                        fn (Builder $department) => $department->where('leader_id', $user->id)
                    );
            })
            ->first();
    }

    private function redirectToParent(Task $parent, User $user): RedirectResponse
    {
        $assignment = $this->parentAssignmentForUser($parent, $user);

        return $assignment
            ? redirect()->route('staff_task_detail', $assignment->id)
            : redirect()->route('staff_my_tasks');
    }

    private function redirectManager(Request $request): ?RedirectResponse
    {
        return $request->user()->is_manager ? redirect()->route('manager_dashboard') : null;
    }

    private function subtaskData(
        Task $task,
        bool $isPrimaryLeader,
        bool $isBatchLeader,
        ?Department $batchDepartment,
    ): array {
        if (! $task->canManageSubtasks(auth()->user()) && ! ($task->isTeamTask() && $isPrimaryLeader)) {
            return [collect(), 0, 0, 0];
        }

        $query = $task->subtasks()->with(['assignments.assignee', 'scopeDepartment']);
        if ($isBatchLeader) {
            $query->where('scope_department_id', $batchDepartment->id);
        } elseif ($task->isTeamTask()) {
            $query->whereNull('scope_department_id');
        }
        $rows = $query->orderBy('deadline')->orderBy('id')->get()->map(function (Task $subtask) {
            $status = $subtask->getEffectiveStatus();

            return [
                'task' => $subtask,
                'effectiveStatus' => $status,
                'statusLabel' => TaskStatus::label($status),
                'statusPill' => TaskStatus::PILL[$status] ?? TaskStatus::PILL[TaskStatus::TODO],
                'assignments' => $subtask->assignments,
                'isOverdue' => $subtask->deadline->lt(today()) && $status !== TaskStatus::COMPLETED,
            ];
        });
        $total = $rows->count();
        $done = $rows->where('effectiveStatus', TaskStatus::COMPLETED)->count();

        return [$rows, $done, $total, $total ? (int) round($done / $total * 100) : 0];
    }
}
