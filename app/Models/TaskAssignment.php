<?php

namespace App\Models;

use App\Support\EvaluationResult;
use App\Support\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class TaskAssignment extends Model
{
    public const STATUS_TODO = TaskStatus::TODO;
    public const STATUS_IN_PROGRESS = TaskStatus::IN_PROGRESS;
    public const STATUS_PENDING = TaskStatus::PENDING;
    public const STATUS_COMPLETED = TaskStatus::COMPLETED;
    public const STATUS_REDO = TaskStatus::REDO;

    public const PROOF_MAX_SIZE_MB = 5;
    public const PROOF_MAX_SIZE_BYTES = self::PROOF_MAX_SIZE_MB * 1024 * 1024;

    /** Runtime flag for notifications (not persisted). */
    public bool $isBatchDepartmentAssignment = false;

    /** Prevent the legacy observer notification when a native notification is sent. */
    public bool $suppressCreatedNotification = false;

    protected $fillable = [
        'task_id',
        'assignee_id',
        'assignee_department_id',
        'status',
        'notes',
        'proof_file',
        'evaluation_result',
        'penalty_score',
        'manager_comment',
        'submitted_at',
        'reviewed_at',
    ];

    protected $casts = [
        'penalty_score' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (TaskAssignment $assignment) {
            $assignment->validateTarget();
        });
    }

    public function validateTarget(): void
    {
        $hasUser = $this->assignee_id !== null;
        $hasDept = $this->assignee_department_id !== null;

        if ($hasUser === $hasDept) {
            throw new InvalidArgumentException(
                'Mỗi bản phân công phải gắn đúng một đối tượng: cá nhân hoặc Tổ/Nhóm.'
            );
        }
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function assigneeDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'assignee_department_id');
    }

    public function getIsDepartmentTargetAttribute(): bool
    {
        return $this->assignee_department_id !== null;
    }

    public function getActingUserAttribute(): ?User
    {
        if ($this->assignee_id) {
            return $this->assignee;
        }

        if ($this->assignee_department_id) {
            return $this->assigneeDepartment?->leader;
        }

        return null;
    }

    public function getTargetDisplayNameAttribute(): string
    {
        if ($this->is_department_target && $this->assignee_department_id) {
            return (string) $this->assigneeDepartment->name;
        }

        if ($this->assignee_id) {
            return (string) $this->assignee;
        }

        return '—';
    }

    public function getTargetAvatarUrlAttribute(): string
    {
        $user = $this->acting_user;
        if ($user) {
            return $user->avatar_url;
        }

        if ($this->is_department_target && $this->assignee_department_id) {
            $name = rawurlencode($this->assigneeDepartment->name);

            return "https://ui-avatars.com/api/?name={$name}&background=0f766e&color=fff";
        }

        return 'https://ui-avatars.com/api/?name=?&background=64748b&color=fff';
    }

    public function getProofDisplayNameAttribute(): string
    {
        if (! $this->proof_file) {
            return '';
        }

        return basename($this->proof_file);
    }

    public function getIsOverdueAttribute(): bool
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return false;
        }

        return $this->task->deadline->lt(Carbon::today());
    }

    public function getEvaluationResultLabelAttribute(): string
    {
        return EvaluationResult::label($this->evaluation_result);
    }

    public function applyReview(string $evaluationResult, string $comment = ''): void
    {
        if (! EvaluationResult::isValid($evaluationResult)) {
            throw new InvalidArgumentException('Kết quả đánh giá không hợp lệ.');
        }

        $this->evaluation_result = $evaluationResult;
        $this->penalty_score = $evaluationResult === EvaluationResult::TRE_BI_TRU_DIEM ? 1 : 0;
        $this->manager_comment = $comment;
        $this->reviewed_at = Carbon::now();

        if ($evaluationResult === EvaluationResult::DAT) {
            $this->status = self::STATUS_COMPLETED;
        } else {
            $this->status = self::STATUS_REDO;
        }
    }

    public function __toString(): string
    {
        return "{$this->task->title} → {$this->target_display_name}";
    }
}
