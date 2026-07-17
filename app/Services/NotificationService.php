<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Notifications\RealtimeTaskNotification;
use App\Support\EvaluationResult;
use App\Support\TaskStatus;
use Illuminate\Support\Facades\DB;

/**
 * Port of Django tasks/signals.py notification logic.
 */
class NotificationService
{
    protected function assignmentRecipient(TaskAssignment $assignment): ?User
    {
        return $assignment->acting_user;
    }

    protected function sendTo(
        User|int|null $recipient,
        string $message,
        ?Task $task = null,
        string $type = 'info',
        string $title = 'Thông báo mới',
    ): void {
        $user = $recipient instanceof User
            ? $recipient
            : ($recipient ? User::find($recipient) : null);

        if (! $user) {
            return;
        }

        $user->notify(new RealtimeTaskNotification($message, $task, $type, $title));
    }

    public function notifyAssignmentCreated(TaskAssignment $assignment): void
    {
        $recipient = $this->assignmentRecipient($assignment);
        if (! $recipient) {
            return;
        }

        $assignment->loadMissing([
            'task.parentTask',
            'task.primaryDepartment',
            'task.coordinatingDepartments',
            'assignee',
            'assigneeDepartment.leader',
        ]);

        $task = $assignment->task;
        if (! $task) {
            return;
        }

        if ($task->is_subtask && $task->parent_task_id) {
            $message = sprintf(
                'Bạn được giao nhiệm vụ con: %s (Từ: %s)',
                $task->title,
                $task->parentTask->title,
            );
        } elseif ($assignment->is_department_target || $assignment->isBatchDepartmentAssignment) {
            $message = 'Lãnh đạo đã giao cho tổ của bạn công việc: '.$task->title;
        } elseif (
            $assignment->assignee_id
            && $task->isTeamTask()
            && $task->isPrimaryLeader($assignment->assignee)
        ) {
            $coords = $task->coordinatingDepartments->pluck('name')->filter()->implode(', ') ?: 'không';
            $message = sprintf(
                'Tổ %s được giao làm Chủ trì việc: %s. Phối hợp: %s. Hãy phân công thành viên và theo dõi minh chứng phối hợp.',
                $task->primaryDepartment->name,
                $task->title,
                $coords,
            );
        } elseif (
            $assignment->assignee_id
            && $task->isTeamTask()
            && $task->isCoordinatingLeader($assignment->assignee)
        ) {
            $dept = $task->getCoordinatingDepartmentFor($assignment->assignee);
            $message = sprintf(
                'Tổ %s được giao Phối hợp việc: %s (Chủ trì: %s). Hãy thêm thành viên và nộp minh chứng.',
                $dept?->name ?? '',
                $task->title,
                $task->primaryDepartment->name,
            );
        } elseif ($task->isTeamTask()) {
            $message = 'Bạn được Trưởng tổ thêm vào công việc nhóm: '.$task->title;
        } else {
            $message = 'Bạn được phân công công việc mới: '.$task->title;
        }

        $this->sendTo($recipient, $message, $task, 'success', 'Công việc mới');
    }

    public function notifyStatusChanged(TaskAssignment $assignment, ?string $previousStatus): void
    {
        if ($previousStatus === $assignment->status) {
            return;
        }

        $assignment->loadMissing([
            'task.parentTask.primaryDepartment',
            'task.primaryDepartment',
            'task.createdBy',
            'task.scopeDepartment',
            'task.assignments.assignee',
            'task.assignments.assigneeDepartment.leader',
            'assignee',
            'assigneeDepartment',
        ]);

        $task = $assignment->task;
        if (! $task) {
            return;
        }

        $actor = $this->assignmentRecipient($assignment);
        $who = $assignment->target_display_name;
        if ($actor && ! $assignment->is_department_target) {
            $who = $actor->full_name_vn !== ''
                ? $actor->full_name_vn
                : (string) $actor->username;
        }

        if ($assignment->status === TaskStatus::PENDING) {
            if ($task->is_subtask && $task->parent_task_id) {
                $parent = $task->parentTask;
                $leaderId = $parent?->primary_department_id
                    ? $parent->primaryDepartment?->leader_id
                    : null;
                if ($leaderId) {
                    $this->sendTo(
                        $leaderId,
                        sprintf(
                            '%s đã nộp minh chứng nhiệm vụ con "%s" (Từ: %s) — vui lòng nghiệm thu.',
                            $who,
                            $task->title,
                            $parent->title,
                        ),
                        $task,
                        'info',
                        'Có minh chứng mới',
                    );
                }
            } else {
                $manager = $task->createdBy;
                if ($task->isTeamTask() && $task->primaryDepartment) {
                    $who = sprintf('Tổ chủ trì %s (%s)', $task->primaryDepartment->name, $who);
                } elseif ($assignment->is_department_target && $assignment->assignee_department_id) {
                    $who = 'Tổ '.$assignment->assigneeDepartment->name;
                }

                if ($manager) {
                    $this->sendTo(
                        $manager,
                        sprintf('%s đã nộp minh chứng cho việc: %s', $who, $task->title),
                        $task,
                        'info',
                        'Có minh chứng mới',
                    );
                }
            }

            return;
        }

        if (in_array($assignment->status, [TaskStatus::COMPLETED, TaskStatus::REDO], true)) {
            $result = $assignment->evaluation_result;
            if ($result === EvaluationResult::DAT) {
                $resultText = 'Đạt';
            } elseif ($result === EvaluationResult::TRE_BI_TRU_DIEM) {
                $resultText = 'Yêu cầu làm lại và trừ 1';
            } elseif ($result === EvaluationResult::CHO_LAM_LAI) {
                $resultText = 'Yêu cầu làm lại';
            } else {
                $resultText = $assignment->status === TaskStatus::COMPLETED
                    ? 'nghiệm thu'
                    : 'yêu cầu làm lại';
            }

            $type = $assignment->status === TaskStatus::COMPLETED ? 'success' : 'danger';

            if ($task->is_subtask) {
                if ($actor) {
                    $this->sendTo(
                        $actor,
                        sprintf(
                            'Trưởng tổ đã đánh giá nhiệm vụ con "%s": %s.',
                            $task->title,
                            $resultText,
                        ),
                        $task,
                        $type,
                        'Kết quả đánh giá',
                    );
                }
                if ($assignment->status === TaskStatus::COMPLETED && $task->parent_task_id) {
                    $this->notifyAllSubtasksDone($task->parentTask, $task->scopeDepartment);
                }
            } else {
                $message = sprintf(
                    'Lãnh đạo đã đánh giá công việc "%s": %s.',
                    $task->title,
                    $resultText,
                );
                if ($task->isTeamTask()) {
                    $this->notifyAssignmentActors($task, $message, $type);
                } elseif ($actor) {
                    $this->sendTo($actor, $message, $task, $type, 'Kết quả đánh giá');
                }
            }
        }
    }

    public function notifyAllSubtasksDone(Task $parent, ?Department $scopeDepartment = null): void
    {
        if (! $parent || ! $parent->allSubtasksCompleted($scopeDepartment)) {
            return;
        }

        if ($scopeDepartment !== null) {
            $leaderId = $scopeDepartment->leader_id;
        } else {
            $parent->loadMissing('primaryDepartment');
            $leaderId = $parent->primary_department_id
                ? $parent->primaryDepartment?->leader_id
                : null;
        }

        if (! $leaderId) {
            return;
        }

        $scopeLabel = $scopeDepartment !== null
            ? ' của tổ '.$scopeDepartment->name
            : '';

        $message = sprintf(
            'Các công việc thành phần%s của "%s" đã xong, vui lòng chốt tiến độ nhiệm vụ gốc!',
            $scopeLabel,
            $parent->title,
        );

        $exists = DB::table('notifications')
            ->where('notifiable_id', $leaderId)
            ->where('notifiable_type', User::class)
            ->whereNull('read_at')
            ->where('data', 'like', '%"task_id":'.$parent->id.'%')
            ->where('data', 'like', '%'.addcslashes($message, '%_\\').'%')
            ->exists();

        if ($exists) {
            return;
        }

        $this->sendTo($leaderId, $message, $parent, 'info', 'Sẵn sàng chốt tiến độ');
    }

    protected function notifyAssignmentActors(Task $task, string $message, string $type = 'info'): void
    {
        $seen = [];
        $assignments = $task->assignments()
            ->with(['assignee', 'assigneeDepartment.leader'])
            ->get();

        foreach ($assignments as $assignment) {
            $user = $assignment->acting_user;
            if (! $user || isset($seen[$user->id])) {
                continue;
            }
            $seen[$user->id] = true;

            $this->sendTo($user, $message, $task, $type, 'Kết quả đánh giá');
        }
    }
}
