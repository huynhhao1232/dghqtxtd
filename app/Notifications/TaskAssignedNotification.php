<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class TaskAssignedNotification extends Notification implements ShouldBroadcastNow
{
    use Queueable;

    public function __construct(private readonly Task $task) {}

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
        return 'success';
    }

    private function payload(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'title' => 'Công việc mới',
            'message' => 'Lãnh đạo vừa giao cho bạn công việc: '.$this->task->title,
            'type' => 'success',
            'url' => $notifiable instanceof User ? $this->taskUrlFor($notifiable) : route('home'),
        ];
    }

    private function taskUrlFor(User $user): string
    {
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
