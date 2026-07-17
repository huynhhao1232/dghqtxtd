<?php

namespace App\Http\Controllers;

use App\Models\TaskAssignment;
use App\Support\EvaluationResult;
use App\Support\TaskStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ManagerReviewController extends Controller
{
    public function queue(): View
    {
        $pending = TaskAssignment::with([
            'task.primaryDepartment.leader', 'task.coordinatingDepartments',
            'assignee', 'assigneeDepartment.leader',
        ])->where('status', TaskStatus::PENDING)
            ->whereHas('task', fn ($q) => $q->where('is_subtask', false))
            ->orderBy('submitted_at')->get();

        $queue = $pending->filter(fn ($a) => ! $a->task->isTeamTask()
            || $a->task->getCanonicalAssignment()?->id === $a->id);

        return view('manager.review_queue', compact('queue'));
    }

    public function review(Request $request, TaskAssignment $assignment): View|RedirectResponse
    {
        abort_unless($assignment->status === TaskStatus::PENDING && ! $assignment->task->is_subtask, 404);
        $assignment->load(['task.primaryDepartment.leader', 'task.coordinatingDepartments', 'task.participations.user',
            'assignee', 'assigneeDepartment.leader']);
        if ($assignment->task->isTeamTask()) {
            abort_unless($assignment->task->getCanonicalAssignment()?->id === $assignment->id, 403);
        }

        if ($request->isMethod('post')) {
            $data = $request->validate([
                'evaluation_result' => ['required', Rule::in(array_keys(EvaluationResult::CHOICES))],
                'manager_comment' => ['nullable', 'string', 'max:3000'],
            ]);
            (new ManagerTaskController)->applyReview($assignment, $data['evaluation_result'], $data['manager_comment'] ?? '');

            return redirect()->route('manager_review_queue')->with('success', 'Đã lưu kết quả nghiệm thu.');
        }

        return view('manager.review_task', [
            'assignment' => $assignment,
            'task' => $assignment->task,
            'participations' => $assignment->task->isTeamTask() ? $assignment->task->participations : collect(),
            'evaluationChoices' => EvaluationResult::radioChoices(),
        ]);
    }
}
