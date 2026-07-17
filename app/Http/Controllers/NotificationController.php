<?php

namespace App\Http\Controllers;

use App\Models\TaskAssignment;
use App\Models\TaskNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function read(Request $request, TaskNotification $notification): RedirectResponse
    {
        abort_unless((int) $notification->recipient_id === (int) $request->user()->id, 404);

        if (! $notification->is_read) {
            $notification->update(['is_read' => true]);
        }

        if (! $notification->related_task_id) {
            return redirect()->route('home');
        }

        if (! $request->user()->is_manager) {
            $assignment = TaskAssignment::query()
                ->where('task_id', $notification->related_task_id)
                ->where(function ($query) use ($request): void {
                    $query->where('assignee_id', $request->user()->id)
                        ->orWhereHas(
                            'assigneeDepartment',
                            fn ($department) => $department->where('leader_id', $request->user()->id)
                        );
                })
                ->first();

            return $assignment
                ? redirect()->route('staff_task_detail', $assignment)
                : redirect()->route('home');
        }

        $task = $notification->relatedTask;
        if ($task && ! $task->is_subtask) {
            return redirect()->route('manager_task_detail', $task);
        }

        $pending = TaskAssignment::query()
            ->where('task_id', $notification->related_task_id)
            ->where('status', TaskAssignment::STATUS_PENDING)
            ->first();

        return $pending
            ? redirect()->route('manager_review_task', $pending)
            : redirect()->route('manager_review_queue');
    }
}
