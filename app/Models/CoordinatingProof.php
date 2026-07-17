<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoordinatingProof extends Model
{
    public const MAX_SIZE_MB = 5;
    public const MAX_SIZE_BYTES = self::MAX_SIZE_MB * 1024 * 1024;

    protected $fillable = [
        'task_id',
        'department_id',
        'uploaded_by_id',
        'proof_file',
        'notes',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function getDisplayNameAttribute(): string
    {
        if (! $this->proof_file) {
            return '';
        }

        return basename($this->proof_file);
    }

    public function __toString(): string
    {
        return "{$this->task->title} ← {$this->department->name}";
    }
}
