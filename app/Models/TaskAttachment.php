<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAttachment extends Model
{
    public const MAX_SIZE_MB = 5;
    public const MAX_SIZE_BYTES = self::MAX_SIZE_MB * 1024 * 1024;
    public const MAX_COUNT = 10;

    protected $fillable = [
        'task_id',
        'file',
        'original_name',
        'file_size',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->original_name) {
            return $this->original_name;
        }

        return basename((string) $this->file);
    }

    public function getSizeDisplayAttribute(): string
    {
        $size = $this->file_size ?: 0;

        if ($size < 1024) {
            return "{$size} B";
        }

        if ($size < 1024 * 1024) {
            return sprintf('%.1f KB', $size / 1024);
        }

        return sprintf('%.1f MB', $size / (1024 * 1024));
    }

    public function __toString(): string
    {
        return $this->original_name ?: (string) $this->file;
    }
}
