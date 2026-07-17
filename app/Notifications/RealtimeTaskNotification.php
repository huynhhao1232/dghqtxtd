<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class RealtimeTaskNotification extends Notification implements ShouldBroadcastNow
{
    use Queueable;

    public function __construct(
        private readonly string $message,
        private readonly ?Task $task = null,
        private readonly string $type = 'info',
        private readonly string $title = 'Thông báo mới',
        private readonly ?string $url = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload($notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->payload($notifiable)))->onConnection('sync');
    }

    public function broadcastType(): string
    {
        return $this->type;
    }

    private function payload(object $notifiable): array
    {
        return [
            'task_id' => $this->task?->id,
            'task_title' => $this->task?->title,
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'url' => $this->url ?? ($notifiable instanceof User ? $this->resolveUrl($notifiable) : route('home')),
        ];
    }

    private function resolveUrl(User $user): string
    {
        if (! $this->task) {
            return route('home');
        }

        if ($user->is_manager) {
            if (! $this->task->is_subtask) {
                return route('manager_task_detail', $this->task);
            }

            $pending = TaskAssignment::query()
                ->where('task_id', $this->task->id)
                ->where('status', TaskAssignment::STATUS_PENDING)
                ->first();

            return $pending
                ? route('manager_review_task', $pending)
                : route('manager_review_queue');
        }

        $assignment = TaskAssignment::query()
            ->where('task_id', $this->task->id)
            ->where(function ($query) use ($user): void {
                $query->where('assignee_id', $user->id)
                    ->orWhereHas('assigneeDepartment', fn ($department) => $department->where('leader_id', $user->id));
            })
            ->first();

        return $assignment
            ? route('staff_task_detail', $assignment)
            : route('staff_my_tasks');
    }
}
