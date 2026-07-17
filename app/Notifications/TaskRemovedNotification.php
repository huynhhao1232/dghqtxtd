<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class TaskRemovedNotification extends Notification implements ShouldBroadcastNow
{
    use Queueable;

    public function __construct(private readonly Task $task) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->payload()))->onConnection('sync');
    }

    public function broadcastType(): string
    {
        return 'danger';
    }

    private function payload(): array
    {
        return [
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'title' => 'Thay đổi phân công',
            'message' => 'Bạn đã được rút khỏi công việc: '.$this->task->title,
            'type' => 'danger',
            'url' => route('staff_my_tasks'),
        ];
    }
}
