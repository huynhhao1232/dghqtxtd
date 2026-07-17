<?php

namespace App\Services;

use App\Models\Department;
use App\Models\TaskAssignment;
use App\Models\TaskParticipation;
use App\Models\User;
use App\Support\EvaluationResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Thống kê định lượng công việc theo Tháng / Quý / Năm học.
 * Khoảng thời gian luôn timezone-aware (Asia/Ho_Chi_Minh), nửa mở [start, end).
 */
class ReportingService
{
    protected function timezone(): string
    {
        return config('app.timezone', 'Asia/Ho_Chi_Minh');
    }

    protected function localNow(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    protected function makeAwareLocal(int $year, int $month, int $day = 1): Carbon
    {
        return Carbon::create($year, $month, $day, 0, 0, 0, $this->timezone());
    }

    protected function refLocal(?Carbon $ref = null): Carbon
    {
        return $ref
            ? $ref->copy()->timezone($this->timezone())
            : $this->localNow();
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    public function monthBounds(?Carbon $ref = null): array
    {
        $ref = $this->refLocal($ref);
        $start = $this->makeAwareLocal($ref->year, $ref->month, 1);
        $end = $start->copy()->addMonth();

        return [$start, $end];
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    public function quarterBounds(?Carbon $ref = null): array
    {
        $ref = $this->refLocal($ref);
        $q = intdiv($ref->month - 1, 3);
        $startMonth = $q * 3 + 1;
        $start = $this->makeAwareLocal($ref->year, $startMonth, 1);
        $end = $startMonth === 10
            ? $this->makeAwareLocal($ref->year + 1, 1, 1)
            : $this->makeAwareLocal($ref->year, $startMonth + 3, 1);

        return [$start, $end];
    }

    public function academicYearStartYear(?Carbon $ref = null): int
    {
        $ref = $this->refLocal($ref);

        return $ref->month >= 9 ? $ref->year : $ref->year - 1;
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    public function academicYearBounds(?int $startYear = null, ?Carbon $ref = null): array
    {
        if ($startYear === null) {
            $startYear = $this->academicYearStartYear($ref);
        }

        return [
            $this->makeAwareLocal($startYear, 9, 1),
            $this->makeAwareLocal($startYear + 1, 9, 1),
        ];
    }

    public function academicYearLabel(int $startYear): string
    {
        return $startYear.'–'.($startYear + 1);
    }

    /**
     * @return array{dat_count:int,penalty_total:int}
     */
    protected function periodStats(int $datCount = 0, int $penaltyTotal = 0): array
    {
        return [
            'dat_count' => $datCount,
            'penalty_total' => $penaltyTotal,
        ];
    }

    /**
     * @return array{dat_count:int,penalty_total:int}
     */
    protected function aggFromQs(Builder $qs, string $resultField = 'evaluation_result', string $penaltyField = 'penalty_score'): array
    {
        $datCount = (clone $qs)->where($resultField, EvaluationResult::DAT)->count();
        $penaltyTotal = (int) ((clone $qs)->sum($penaltyField) ?? 0);

        return $this->periodStats($datCount, $penaltyTotal);
    }

    /**
     * @param  array{dat_count:int,penalty_total:int}  ...$statsList
     * @return array{dat_count:int,penalty_total:int}
     */
    protected function mergeStats(array ...$statsList): array
    {
        return $this->periodStats(
            array_sum(array_column($statsList, 'dat_count')),
            array_sum(array_column($statsList, 'penalty_total')),
        );
    }

    protected function personalAssignmentQs(User $user, ?Carbon $start = null, ?Carbon $end = null): Builder
    {
        $qs = TaskAssignment::query()
            ->where('assignee_id', $user->id)
            ->whereNotNull('evaluation_result')
            ->whereNotNull('reviewed_at')
            ->whereHas('task', function (Builder $q) {
                $q->whereNull('primary_department_id')
                    ->orWhere('is_subtask', true);
            });

        if ($start !== null) {
            $qs->where('reviewed_at', '>=', $start);
        }
        if ($end !== null) {
            $qs->where('reviewed_at', '<', $end);
        }

        return $qs;
    }

    protected function personalParticipationQs(User $user, ?Carbon $start = null, ?Carbon $end = null): Builder
    {
        $qs = TaskParticipation::query()
            ->where('user_id', $user->id)
            ->whereIn('evaluation', [
                EvaluationResult::DAT,
                EvaluationResult::CHO_LAM_LAI,
                EvaluationResult::TRE_BI_TRU_DIEM,
            ])
            ->whereNotNull('evaluated_at');

        if ($start !== null) {
            $qs->where('evaluated_at', '>=', $start);
        }
        if ($end !== null) {
            $qs->where('evaluated_at', '<', $end);
        }

        return $qs;
    }

    protected function departmentAssignmentQs(Department $department, ?Carbon $start = null, ?Carbon $end = null): Builder
    {
        $qs = TaskAssignment::query()
            ->where('assignee_department_id', $department->id)
            ->whereNotNull('evaluation_result')
            ->whereNotNull('reviewed_at');

        if ($start !== null) {
            $qs->where('reviewed_at', '>=', $start);
        }
        if ($end !== null) {
            $qs->where('reviewed_at', '<', $end);
        }

        return $qs;
    }

    protected function classicTeamDepartmentQs(Department $department, ?Carbon $start = null, ?Carbon $end = null): Builder
    {
        $qs = TaskAssignment::query()
            ->whereNotNull('evaluation_result')
            ->whereNotNull('reviewed_at')
            ->whereHas('task', function (Builder $q) use ($department) {
                $q->whereNotNull('primary_department_id')
                    ->where('is_subtask', false)
                    ->where(function (Builder $inner) use ($department) {
                        $inner->where('primary_department_id', $department->id)
                            ->orWhereHas('coordinatingDepartments', function (Builder $coord) use ($department) {
                                $coord->where('departments.id', $department->id);
                            });
                    });
            })
            ->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('tasks')
                    ->join('departments as primary_dept', 'primary_dept.id', '=', 'tasks.primary_department_id')
                    ->whereColumn('tasks.id', 'task_assignments.task_id')
                    ->whereColumn('task_assignments.assignee_id', 'primary_dept.leader_id');
            });

        if ($start !== null) {
            $qs->where('reviewed_at', '>=', $start);
        }
        if ($end !== null) {
            $qs->where('reviewed_at', '<', $end);
        }

        return $qs;
    }

    /**
     * @return array{dat_count:int,penalty_total:int}
     */
    public function statsForPerson(User $user, ?Carbon $start = null, ?Carbon $end = null): array
    {
        $a = $this->aggFromQs($this->personalAssignmentQs($user, $start, $end));
        $p = $this->aggFromQs(
            $this->personalParticipationQs($user, $start, $end),
            'evaluation',
            'penalty_score',
        );

        return $this->mergeStats($a, $p);
    }

    /**
     * @return array{dat_count:int,penalty_total:int}
     */
    public function statsForDepartment(Department $dept, ?Carbon $start = null, ?Carbon $end = null): array
    {
        $batch = $this->aggFromQs($this->departmentAssignmentQs($dept, $start, $end));
        $classic = $this->aggFromQs($this->classicTeamDepartmentQs($dept, $start, $end));

        return $this->mergeStats($batch, $classic);
    }

    /**
     * @param  callable(Carbon,Carbon):array{dat_count:int,penalty_total:int}  $statsFn
     * @return array{
     *     month:array{dat_count:int,penalty_total:int},
     *     quarter:array{dat_count:int,penalty_total:int},
     *     academic_year:array{dat_count:int,penalty_total:int},
     *     academic_year_label:string,
     *     penalty_month:int,
     *     penalty_quarter:int,
     *     penalty_academic_year:int
     * }
     */
    protected function periodCardBundle(callable $statsFn, ?Carbon $ref = null): array
    {
        $ref = $this->refLocal($ref);
        [$mStart, $mEnd] = $this->monthBounds($ref);
        [$qStart, $qEnd] = $this->quarterBounds($ref);
        $ay = $this->academicYearStartYear($ref);
        [$aStart, $aEnd] = $this->academicYearBounds($ay);

        $month = $statsFn($mStart, $mEnd);
        $quarter = $statsFn($qStart, $qEnd);
        $academic = $statsFn($aStart, $aEnd);

        return [
            'month' => $month,
            'quarter' => $quarter,
            'academic_year' => $academic,
            'academic_year_label' => $this->academicYearLabel($ay),
            'penalty_month' => $month['penalty_total'],
            'penalty_quarter' => $quarter['penalty_total'],
            'penalty_academic_year' => $academic['penalty_total'],
        ];
    }

    public function personPeriodCards(User $user, ?Carbon $ref = null): array
    {
        return $this->periodCardBundle(
            fn (Carbon $start, Carbon $end) => $this->statsForPerson($user, $start, $end),
            $ref,
        );
    }

    public function departmentPeriodCards(Department $dept, ?Carbon $ref = null): array
    {
        return $this->periodCardBundle(
            fn (Carbon $start, Carbon $end) => $this->statsForDepartment($dept, $start, $end),
            $ref,
        );
    }

    /**
     * @return array{dat_count:int,penalty_total:int}
     */
    protected function systemStats(Carbon $start, Carbon $end): array
    {
        $asg = TaskAssignment::query()
            ->whereNotNull('evaluation_result')
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '>=', $start)
            ->where('reviewed_at', '<', $end);

        $nonClassic = (clone $asg)->whereHas('task', function (Builder $q) {
            $q->whereNull('primary_department_id')
                ->orWhere('is_subtask', true);
        });

        $classicCan = (clone $asg)
            ->whereHas('task', function (Builder $q) {
                $q->whereNotNull('primary_department_id')
                    ->where('is_subtask', false);
            })
            ->whereExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('tasks')
                    ->join('departments as primary_dept', 'primary_dept.id', '=', 'tasks.primary_department_id')
                    ->whereColumn('tasks.id', 'task_assignments.task_id')
                    ->whereColumn('task_assignments.assignee_id', 'primary_dept.leader_id');
            });

        $aStats = $this->mergeStats(
            $this->aggFromQs($nonClassic),
            $this->aggFromQs($classicCan),
        );

        $pStats = $this->aggFromQs(
            TaskParticipation::query()
                ->whereIn('evaluation', [
                    EvaluationResult::DAT,
                    EvaluationResult::CHO_LAM_LAI,
                    EvaluationResult::TRE_BI_TRU_DIEM,
                ])
                ->whereNotNull('evaluated_at')
                ->where('evaluated_at', '>=', $start)
                ->where('evaluated_at', '<', $end),
            'evaluation',
            'penalty_score',
        );

        return $this->mergeStats($aStats, $pStats);
    }

    public function systemPeriodCards(?Carbon $ref = null): array
    {
        $ref = $this->refLocal($ref);
        [$mStart, $mEnd] = $this->monthBounds($ref);
        [$qStart, $qEnd] = $this->quarterBounds($ref);
        $ay = $this->academicYearStartYear($ref);
        [$aStart, $aEnd] = $this->academicYearBounds($ay);

        $month = $this->systemStats($mStart, $mEnd);
        $quarter = $this->systemStats($qStart, $qEnd);
        $academic = $this->systemStats($aStart, $aEnd);

        return [
            'month' => $month,
            'quarter' => $quarter,
            'academic_year' => $academic,
            'academic_year_label' => $this->academicYearLabel($ay),
            'penalty_month' => $month['penalty_total'],
            'penalty_quarter' => $quarter['penalty_total'],
            'penalty_academic_year' => $academic['penalty_total'],
        ];
    }

    /**
     * @return array{0:list<string>,1:list<array{0:Carbon,1:Carbon}>}
     */
    protected function periodColumns(string $period, int $year): array
    {
        if ($period === 'quarter') {
            $columns = ['Q1', 'Q2', 'Q3', 'Q4'];
            $ranges = [
                [$this->makeAwareLocal($year, 1, 1), $this->makeAwareLocal($year, 4, 1)],
                [$this->makeAwareLocal($year, 4, 1), $this->makeAwareLocal($year, 7, 1)],
                [$this->makeAwareLocal($year, 7, 1), $this->makeAwareLocal($year, 10, 1)],
                [$this->makeAwareLocal($year, 10, 1), $this->makeAwareLocal($year + 1, 1, 1)],
            ];

            return [$columns, $ranges];
        }

        if ($period === 'academic_year') {
            $columns = [
                'T09', 'T10', 'T11', 'T12',
                'T01', 'T02', 'T03', 'T04', 'T05', 'T06', 'T07', 'T08',
            ];
            $ranges = [];
            for ($m = 9; $m <= 12; $m++) {
                $start = $this->makeAwareLocal($year, $m, 1);
                $end = $m === 12
                    ? $this->makeAwareLocal($year + 1, 1, 1)
                    : $this->makeAwareLocal($year, $m + 1, 1);
                $ranges[] = [$start, $end];
            }
            for ($m = 1; $m <= 8; $m++) {
                $start = $this->makeAwareLocal($year + 1, $m, 1);
                $end = $m < 8
                    ? $this->makeAwareLocal($year + 1, $m + 1, 1)
                    : $this->makeAwareLocal($year + 1, 9, 1);
                $ranges[] = [$start, $end];
            }

            return [$columns, $ranges];
        }

        $columns = [];
        $ranges = [];
        for ($m = 1; $m <= 12; $m++) {
            $columns[] = sprintf('T%02d', $m);
            $start = $this->makeAwareLocal($year, $m, 1);
            $end = $m === 12
                ? $this->makeAwareLocal($year + 1, 1, 1)
                : $this->makeAwareLocal($year, $m + 1, 1);
            $ranges[] = [$start, $end];
        }

        return [$columns, $ranges];
    }

    /**
     * @return array{0:Collection<int,User>,1:list<string>,2:array<int,array<string,array{dat_count:int,penalty_total:int}>>}
     */
    public function buildPersonMatrix(string $period, int $year): array
    {
        $users = User::query()
            ->where('is_manager', false)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        [$columns, $ranges] = $this->periodColumns($period, $year);

        $matrix = [];
        foreach ($users as $user) {
            $matrix[$user->id] = [];
            foreach ($columns as $i => $col) {
                [$start, $end] = $ranges[$i];
                $matrix[$user->id][$col] = $this->statsForPerson($user, $start, $end);
            }
        }

        return [$users, $columns, $matrix];
    }

    /**
     * @return array{0:Collection<int,Department>,1:list<string>,2:array<int,array<string,array{dat_count:int,penalty_total:int}>>}
     */
    public function buildDepartmentMatrix(string $period, int $year): array
    {
        $departments = Department::query()
            ->with('leader')
            ->orderBy('name')
            ->get();

        [$columns, $ranges] = $this->periodColumns($period, $year);

        $matrix = [];
        foreach ($departments as $dept) {
            $matrix[$dept->id] = [];
            foreach ($columns as $i => $col) {
                [$start, $end] = $ranges[$i];
                $matrix[$dept->id][$col] = $this->statsForDepartment($dept, $start, $end);
            }
        }

        return [$departments, $columns, $matrix];
    }

    /**
     * @return Collection<int,Department>
     */
    public function ledDepartmentsFor(User $user): Collection
    {
        return Department::query()
            ->where('leader_id', $user->id)
            ->orderBy('name')
            ->get();
    }
}
