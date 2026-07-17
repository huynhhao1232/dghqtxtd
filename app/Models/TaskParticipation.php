<?php

namespace App\Models;

use App\Support\EvaluationResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class TaskParticipation extends Model
{
    public const ROLE_LEAD = 'lead_member';
    public const ROLE_COORD = 'coord_member';

    public const EVAL_NONE = 'none';
    public const EVAL_DAT = EvaluationResult::DAT;
    public const EVAL_CHO_LAM_LAI = EvaluationResult::CHO_LAM_LAI;
    public const EVAL_TRE_BI_TRU_DIEM = EvaluationResult::TRE_BI_TRU_DIEM;
    public const EVAL_PASS = self::EVAL_DAT;
    public const EVAL_FAIL = self::EVAL_CHO_LAM_LAI;

    protected $fillable = [
        'task_id',
        'user_id',
        'department_id',
        'role',
        'evaluation',
        'penalty_score',
        'evaluated_at',
    ];

    protected $casts = [
        'penalty_score' => 'integer',
        'evaluated_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function getIsEvaluatedAttribute(): bool
    {
        return in_array($this->evaluation, [
            self::EVAL_DAT,
            self::EVAL_CHO_LAM_LAI,
            self::EVAL_TRE_BI_TRU_DIEM,
        ], true);
    }

    public function applyEvaluation(string $result): void
    {
        if (! EvaluationResult::isValid($result)) {
            throw new InvalidArgumentException('Kết quả đánh giá không hợp lệ.');
        }

        $this->evaluation = $result;
        $this->penalty_score = $result === EvaluationResult::TRE_BI_TRU_DIEM ? 1 : 0;
        $this->evaluated_at = Carbon::now();
    }

    public function __toString(): string
    {
        return "{$this->task->title} · {$this->user}";
    }
}
