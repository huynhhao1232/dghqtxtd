<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskNotification extends Model
{
    protected $table = 'task_notifications';

    protected $fillable = [
        'recipient_id',
        'message',
        'is_read',
        'related_task_id',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function relatedTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'related_task_id');
    }

    public function __toString(): string
    {
        $preview = mb_substr((string) $this->message, 0, 40);

        return "{$this->recipient}: {$preview}";
    }
}
