<?php

namespace App\Models;

use App\Support\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Task extends Model
{
    public const CYCLE_MONTH = 'month';
    public const CYCLE_QUARTER = 'quarter';
    public const CYCLE_SEMESTER = 'semester';

    public const CYCLE_CHOICES = [
        self::CYCLE_MONTH => 'Tháng',
        self::CYCLE_QUARTER => 'Quý',
        self::CYCLE_SEMESTER => 'Kỳ',
    ];

    protected $fillable = [
        'title',
        'description',
        'created_by_id',
        'deadline',
        'cycle',
        'primary_department_id',
        'delegated_updater_id',
        'parent_task_id',
        'scope_department_id',
        'is_subtask',
    ];

    protected $casts = [
        'deadline' => 'date',
        'is_subtask' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Task $task) {
            $task->is_subtask = $task->parent_task_id !== null;
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function primaryDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'primary_department_id');
    }

    public function coordinatingDepartments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'task_coordinating_department');
    }

    public function delegatedUpdater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_updater_id');
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function scopeDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'scope_department_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(TaskParticipation::class);
    }

    public function coordinatingProofs(): HasMany
    {
        return $this->hasMany(CoordinatingProof::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(TaskNotification::class, 'related_task_id');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->deadline->lt(Carbon::today());
    }

    public function isTeamTask(): bool
    {
        return $this->primary_department_id !== null && ! $this->is_subtask;
    }

    public function isBatchDepartmentTask(): bool
    {
        if ($this->is_subtask || $this->primary_department_id) {
            return false;
        }

        return $this->assignments()
            ->whereNotNull('assignee_department_id')
            ->exists();
    }

    public function getBatchDepartmentFor(?User $user): ?Department
    {
        if (! $user || $this->is_subtask || $this->primary_department_id) {
            return null;
        }

        $assignment = $this->assignments()
            ->with('assigneeDepartment')
            ->whereHas('assigneeDepartment', function ($q) use ($user) {
                $q->where('leader_id', $user->id);
            })
            ->first();

        return $assignment?->assigneeDepartment;
    }

    public function isPrimaryLeader(?User $user): bool
    {
        if (! $user || ! $this->primary_department_id) {
            return false;
        }

        return (int) ($this->primaryDepartment?->leader_id) === (int) $user->id;
    }

    public function isCoordinatingLeader(?User $user): bool
    {
        if (! $user || ! $this->isTeamTask()) {
            return false;
        }

        return $this->coordinatingDepartments()
            ->where('leader_id', $user->id)
            ->exists();
    }

    public function getCoordinatingDepartmentFor(?User $user): ?Department
    {
        if (! $user) {
            return null;
        }

        return $this->coordinatingDepartments()
            ->where('leader_id', $user->id)
            ->first();
    }

    public function isDepartmentLeader(?User $user): bool
    {
        return $this->isPrimaryLeader($user) || $this->isCoordinatingLeader($user);
    }

    public function canManageSubtasks(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->is_subtask) {
            return false;
        }

        if ($this->isTeamTask()) {
            return $this->isPrimaryLeader($user);
        }

        return $this->getBatchDepartmentFor($user) !== null;
    }

    public function canUpdateProgress(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->is_subtask || ! $this->isTeamTask()) {
            return true;
        }

        if ($this->isPrimaryLeader($user)) {
            return true;
        }

        return (int) $this->delegated_updater_id === (int) $user->id;
    }

    public function canSubmitCoordinatingProof(?User $user): bool
    {
        return $this->isCoordinatingLeader($user);
    }

    public function getCanonicalAssignment(): ?TaskAssignment
    {
        if (! $this->isTeamTask()) {
            return null;
        }

        $leaderId = $this->primaryDepartment?->leader_id;
        if ($leaderId) {
            $found = $this->assignments()->where('assignee_id', $leaderId)->first();
            if ($found) {
                return $found;
            }
        }

        return $this->assignments()->orderBy('id')->first();
    }

    public function syncTeamAssignmentStatus(?TaskAssignment $canonical): void
    {
        if (! $canonical || ! $this->isTeamTask()) {
            return;
        }

        $this->assignments()
            ->where('id', '!=', $canonical->id)
            ->update([
                'status' => $canonical->status,
                'submitted_at' => $canonical->submitted_at,
                'evaluation_result' => $canonical->evaluation_result,
                'penalty_score' => $canonical->penalty_score,
                'manager_comment' => $canonical->manager_comment,
                'reviewed_at' => $canonical->reviewed_at,
            ]);
    }

    public function participationsForLeader(?User $user)
    {
        $qs = $this->participations()->with(['user', 'department']);

        if ($this->isPrimaryLeader($user)) {
            return $qs->where('role', TaskParticipation::ROLE_LEAD);
        }

        $coordDept = $this->getCoordinatingDepartmentFor($user);
        if ($coordDept) {
            return $qs->where('role', TaskParticipation::ROLE_COORD)
                ->where('department_id', $coordDept->id);
        }

        $batchDept = $this->getBatchDepartmentFor($user);
        if ($batchDept) {
            return $qs->where('department_id', $batchDept->id);
        }

        return $qs->whereRaw('1 = 0');
    }

    public function participationsForInternalEvaluation(?User $user)
    {
        $qs = $this->participations()->with(['user', 'department']);

        if ($this->isPrimaryLeader($user)) {
            return $qs;
        }

        return $qs->whereRaw('1 = 0');
    }

    public function needsInternalEvaluation(?User $user): bool
    {
        if ($this->is_subtask) {
            return false;
        }

        $canonical = $this->getCanonicalAssignment();
        if (! $canonical || $canonical->status !== TaskStatus::COMPLETED) {
            return false;
        }

        $parts = $this->participationsForInternalEvaluation($user);

        return $parts->exists()
            && $parts->where('evaluation', TaskParticipation::EVAL_NONE)->exists();
    }

    public function getEffectiveStatus(): string
    {
        if ($this->isTeamTask()) {
            $canonical = $this->getCanonicalAssignment();

            return $canonical ? $canonical->status : TaskStatus::TODO;
        }

        $statuses = $this->assignments()->pluck('status')->all();

        if (! $statuses) {
            return TaskStatus::TODO;
        }

        if (in_array(TaskStatus::PENDING, $statuses, true)) {
            return TaskStatus::PENDING;
        }

        if (
            in_array(TaskStatus::REDO, $statuses, true)
            || in_array(TaskStatus::IN_PROGRESS, $statuses, true)
        ) {
            return TaskStatus::IN_PROGRESS;
        }

        if (count(array_unique($statuses)) === 1 && $statuses[0] === TaskStatus::COMPLETED) {
            return TaskStatus::COMPLETED;
        }

        if (count(array_unique($statuses)) === 1 && $statuses[0] === TaskStatus::TODO) {
            return TaskStatus::TODO;
        }

        return TaskStatus::IN_PROGRESS;
    }

    public function isEffectivelyCompleted(): bool
    {
        return $this->getEffectiveStatus() === TaskStatus::COMPLETED;
    }

    /**
     * @return array{0:int,1:int,2:int} completed, total, percent
     */
    public function subtaskProgress(?Department $department = null): array
    {
        $subs = $this->subtasks;
        if ($department !== null) {
            $subs = $subs->where('scope_department_id', $department->id);
        }

        $total = $subs->count();
        if (! $total) {
            return [0, 0, 0];
        }

        $done = $subs->filter(fn (Task $s) => $s->isEffectivelyCompleted())->count();
        $pct = (int) round(($done / $total) * 100);

        return [$done, $total, $pct];
    }

    public function allSubtasksCompleted(?Department $department = null): bool
    {
        $query = $this->subtasks();
        if ($department !== null) {
            $query->where('scope_department_id', $department->id);
        }

        $subs = $query->get();
        if ($subs->isEmpty()) {
            return false;
        }

        return $subs->every(fn (Task $s) => $s->isEffectivelyCompleted());
    }

    public function __toString(): string
    {
        return (string) $this->title;
    }
}
