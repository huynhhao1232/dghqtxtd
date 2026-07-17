<?php

namespace App\Http\Controllers;

use App\Exports\TaskEvaluationExport;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Notifications\RealtimeTaskNotification;
use App\Support\EvaluationResult;
use App\Support\TaskStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ManagerTaskController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->isMethod('get')) {
            return $this->createView();
        }

        $mode = $request->input('assign_mode', 'individual');
        $rules = [
            'assign_mode' => ['required', Rule::in(['individual', 'department', 'batch_department'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'deadline' => ['required', 'date'],
            'cycle' => ['required', Rule::in(array_keys(Task::CYCLE_CHOICES))],
            'attachments' => ['nullable', 'array', 'max:'.TaskAttachment::MAX_COUNT],
            'attachments.*' => ['file', 'max:'.(TaskAttachment::MAX_SIZE_MB * 1024)],
        ];
        if ($mode === 'individual') {
            $rules['assignees'] = ['required', 'array', 'min:1'];
            $rules['assignees.*'] = ['integer', Rule::exists('users', 'id')->where(
                fn ($q) => $q->where('is_active', true)->where('is_manager', false)
            )];
        } elseif ($mode === 'department') {
            $rules['primary_department_id'] = ['required', 'integer', 'exists:departments,id'];
            $rules['coordinating_departments'] = ['nullable', 'array'];
            $rules['coordinating_departments.*'] = ['integer', 'distinct', 'exists:departments,id'];
        } else {
            $rules['batch_departments'] = ['required', 'array', 'min:1'];
            $rules['batch_departments.*'] = ['integer', 'distinct', 'exists:departments,id'];
        }
        $data = $request->validate($rules);

        $task = DB::transaction(function () use ($request, $data, $mode) {
            $task = Task::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? '',
                'deadline' => $data['deadline'],
                'cycle' => $data['cycle'],
                'created_by_id' => $request->user()->id,
                'primary_department_id' => $mode === 'department' ? $data['primary_department_id'] : null,
            ]);

            if ($mode === 'department') {
                $ids = array_values(array_diff($data['coordinating_departments'] ?? [], [$data['primary_department_id']]));
                $task->coordinatingDepartments()->sync($ids);
                $departments = Department::with('leader')->whereIn('id', array_merge([$data['primary_department_id']], $ids))->get();
                abort_if($departments->contains(fn ($d) => ! $d->leader_id), 422, 'Mỗi Tổ/Nhóm được giao phải có Trưởng tổ.');
                foreach ($departments as $department) {
                    TaskAssignment::firstOrCreate(['task_id' => $task->id, 'assignee_id' => $department->leader_id]);
                }
            } elseif ($mode === 'batch_department') {
                $departments = Department::with('leader')->whereIn('id', $data['batch_departments'])->get();
                abort_if($departments->contains(fn ($d) => ! $d->leader_id), 422, 'Mỗi Tổ/Nhóm được giao phải có Trưởng tổ.');
                foreach ($departments as $department) {
                    $assignment = new TaskAssignment([
                        'task_id' => $task->id,
                        'assignee_department_id' => $department->id,
                    ]);
                    $assignment->isBatchDepartmentAssignment = true;
                    $assignment->save();
                }
            } else {
                foreach (array_unique($data['assignees']) as $userId) {
                    TaskAssignment::firstOrCreate(['task_id' => $task->id, 'assignee_id' => $userId]);
                }
            }

            foreach ($request->file('attachments', []) as $file) {
                $path = $file->store('task_attachments', 'public');
                TaskAttachment::create([
                    'task_id' => $task->id,
                    'file' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                ]);
            }

            return $task;
        });

        return redirect()->route('manager_task_detail', $task)->with('success', 'Đã giao nhiệm vụ thành công.');
    }

    public function manage(Request $request): View
    {
        $base = Task::with([
            'primaryDepartment', 'coordinatingDepartments', 'participations',
            'assignments.assignee', 'assignments.assigneeDepartment.leader',
        ])->whereNull('parent_task_id');

        $all = (clone $base)->get();
        $stats = [
            'total' => $all->count(),
            'in_progress' => $all->filter(fn ($t) => in_array($t->getEffectiveStatus(), [TaskStatus::IN_PROGRESS, TaskStatus::REDO], true))->count(),
            'pending' => $all->filter(fn ($t) => $t->getEffectiveStatus() === TaskStatus::PENDING)->count(),
            'overdue' => $all->filter(fn ($t) => $t->deadline->lt(today()) && ! $t->isEffectivelyCompleted())->count(),
        ];

        $query = $base;
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($x) => $x->where('title', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%"));
        }
        if ($dept = $request->integer('dept')) {
            $query->where(fn ($x) => $x->where('primary_department_id', $dept)
                ->orWhereHas('coordinatingDepartments', fn ($d) => $d->where('departments.id', $dept))
                ->orWhereHas('assignments', fn ($a) => $a->where('assignee_department_id', $dept)));
        }

        $tasks = $query->latest()->get();
        $status = (string) $request->query('status');
        if ($status === 'overdue') {
            $tasks = $tasks->filter(fn ($t) => $t->deadline->lt(today()) && ! $t->isEffectivelyCompleted());
        } elseif (array_key_exists($status, TaskStatus::CHOICES)) {
            $tasks = $tasks->filter(fn ($t) => $t->getEffectiveStatus() === $status);
        }

        $page = max(1, $request->integer('page', 1));
        $tasks = new LengthAwarePaginator(
            $tasks->forPage($page, 10)->map(fn ($t) => $this->enrich($t)),
            $tasks->count(), 10, $page, ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('manager.manage_tasks', compact('tasks', 'stats') + [
            'departments' => Department::orderBy('name')->get(),
            'statusChoices' => TaskStatus::CHOICES,
        ]);
    }

    public function show(Task $task): View
    {
        abort_if($task->parent_task_id, 404);
        $task->load(['createdBy', 'primaryDepartment.leader', 'coordinatingDepartments', 'attachments',
            'participations.user', 'participations.department', 'assignments.assignee', 'assignments.assigneeDepartment.leader']);
        $all = $task->assignments->sortBy('id');
        $canonical = $task->isTeamTask() ? $task->getCanonicalAssignment() : null;
        $assignments = $canonical ? collect([$canonical->loadMissing('assignee', 'assigneeDepartment.leader')]) : $all;
        $submitted = $assignments->whereIn('status', [TaskStatus::PENDING, TaskStatus::COMPLETED])->count();

        return view('manager.task_detail', [
            'task' => $task, 'assignments' => $assignments, 'participations' => $task->participations,
            'submittedCount' => $submitted, 'totalCount' => $assignments->count(),
            'progressPct' => $assignments->count() ? (int) round($submitted / $assignments->count() * 100) : 0,
            'evaluationChoices' => EvaluationResult::radioChoices(), 'statusLabels' => TaskStatus::CHOICES,
        ]);
    }

    public function exportExcel(Task $task): BinaryFileResponse
    {
        abort_if($task->parent_task_id, 404);

        return (new TaskEvaluationExport($task))->download();
    }

    public function assignmentReview(Request $request, Task $task, TaskAssignment $assignment): RedirectResponse|JsonResponse
    {
        abort_unless(! $task->parent_task_id && $assignment->task_id === $task->id && $assignment->status === TaskStatus::PENDING, 404);
        $this->assertCanonical($task, $assignment);
        $data = $request->validate([
            'evaluation_result' => ['required', Rule::in(array_keys(EvaluationResult::CHOICES))],
            'manager_comment' => ['nullable', 'string', 'max:3000'],
        ]);
        $this->applyReview($assignment, $data['evaluation_result'], $data['manager_comment'] ?? '');

        $message = 'Đã đánh giá '.$assignment->target_display_name.': '.EvaluationResult::label($data['evaluation_result']).'.';
        if ($request->expectsJson()) {
            $assignment->refresh();

            return response()->json([
                'message' => $message,
                'assignment' => [
                    'id' => $assignment->id,
                    'status' => $assignment->status,
                    'status_label' => TaskStatus::label($assignment->status),
                    'status_pill' => TaskStatus::PILL[$assignment->status] ?? TaskStatus::PILL[TaskStatus::TODO],
                    'evaluation_result' => $assignment->evaluation_result,
                    'evaluation_label' => $assignment->evaluation_result_label,
                    'penalty_score' => $assignment->penalty_score,
                    'comment' => $assignment->manager_comment ?? '',
                    'can_grade' => false,
                ],
            ]);
        }

        return redirect()
            ->route('manager_task_detail', $task)
            ->with('success', $message);
    }

    public function edit(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'],
            'deadline' => ['required', 'date'], 'cycle' => ['required', Rule::in(array_keys(Task::CYCLE_CHOICES))],
        ]);
        $task->update($data);

        return back()->with('success', 'Đã cập nhật công việc.');
    }

    public function extend(Request $request, Task $task): RedirectResponse|JsonResponse
    {
        $days = in_array((int) $request->input('days', 3), [3, 5], true) ? (int) $request->input('days', 3) : 3;
        $task->deadline = $task->deadline->addDays($days);
        $task->save();
        $payload = ['ok' => true, 'deadline' => $task->deadline->toDateString(), 'deadline_display' => $task->deadline->format('d/m/Y'), 'days' => $days];

        return $request->wantsJson() ? response()->json($payload) : back()->with('success', "Đã gia hạn thêm {$days} ngày.");
    }

    public function destroy(Task $task): RedirectResponse
    {
        foreach ($task->attachments as $attachment) {
            Storage::disk('public')->delete($attachment->file);
        }
        $task->delete();

        return redirect()->route('manager_manage_tasks')->with('success', 'Đã xóa công việc.');
    }

    private function createView(): View
    {
        return view('tasks.create', [
            'staff' => User::with('departments')->where('is_manager', false)->where('is_active', true)->get(),
            'departments' => Department::with('leader')->orderBy('name')->get(),
            'cycles' => Task::CYCLE_CHOICES,
            'maxAttachmentMb' => TaskAttachment::MAX_SIZE_MB,
            'maxAttachmentCount' => TaskAttachment::MAX_COUNT,
        ]);
    }

    private function enrich(Task $task): Task
    {
        $status = $task->getEffectiveStatus();
        $assignments = $task->assignments;
        $parts = $task->participations;
        if ($task->isTeamTask()) {
            $pct = TaskStatus::PROGRESS[$status] ?? 0;
            $label = 'Toàn nhiệm vụ · '.TaskStatus::label($status);
        } elseif ($parts->isNotEmpty()) {
            $passed = $parts->where('evaluation', EvaluationResult::DAT)->count();
            $pct = (int) round($passed / $parts->count() * 100);
            $label = "{$passed}/{$parts->count()} thành viên đạt";
        } elseif ($assignments->count() > 1 || $assignments->whereNotNull('assignee_department_id')->isNotEmpty()) {
            $submitted = $assignments->whereIn('status', [TaskStatus::PENDING, TaskStatus::COMPLETED])->count();
            $pct = $assignments->count() ? (int) round($submitted / $assignments->count() * 100) : 0;
            $label = "Đã nộp {$submitted}/{$assignments->count()}";
        } else {
            $pct = TaskStatus::PROGRESS[$status] ?? 0;
            $label = TaskStatus::label($status);
        }
        $task->setAttribute('display_status', $status);
        $task->setAttribute('display_status_label', TaskStatus::label($status));
        $task->setAttribute('display_status_pill', TaskStatus::PILL[$status] ?? TaskStatus::PILL[TaskStatus::TODO]);
        $task->setAttribute('progress_pct', $pct);
        $task->setAttribute('progress_label', $label);
        $task->setAttribute('row_is_overdue', $task->deadline->lt(today()) && $status !== TaskStatus::COMPLETED);

        return $task;
    }

    private function assertCanonical(Task $task, TaskAssignment $assignment): void
    {
        if ($task->isTeamTask()) {
            abort_unless($task->getCanonicalAssignment()?->id === $assignment->id, 403, 'Nhiệm vụ Chủ trì–Phối hợp chỉ được đánh giá một lần.');
        }
    }

    public function applyReview(TaskAssignment $assignment, string $result, string $comment): void
    {
        $assignment->applyReview($result, $comment);
        $assignment->save();
        if ($assignment->task->isTeamTask()) {
            $assignment->task->syncTeamAssignmentStatus($assignment);
            if ($result === EvaluationResult::DAT && ($leaderId = $assignment->task->primaryDepartment?->leader_id)) {
                $leader = User::find($leaderId);
                $leader?->notify(new RealtimeTaskNotification(
                    'Lãnh đạo đã nghiệm thu "'.$assignment->task->title.'". Hãy đánh giá từng cá nhân tham gia nhiệm vụ.',
                    $assignment->task,
                    'success',
                    'Nghiệm thu thành công',
                ));
            }
        }
    }
}
