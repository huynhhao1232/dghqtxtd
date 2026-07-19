"""
Thống kê định lượng công việc theo Tháng / Quý / Năm học.
Khoảng thời gian luôn timezone-aware, nửa mở [start, end).
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date, datetime, time

from django.db.models import Count, F, Q, Sum
from django.utils import timezone

from accounts.models import Department, User

from .models import EvaluationResult, TaskAssignment, TaskParticipation


def local_now():
    return timezone.localtime(timezone.now())


def make_aware_local(day: date):
    tz = timezone.get_current_timezone()
    return timezone.make_aware(datetime.combine(day, time.min), tz)


def month_bounds(ref: date | None = None):
    ref = ref or local_now().date()
    start = ref.replace(day=1)
    if start.month == 12:
        end = start.replace(year=start.year + 1, month=1)
    else:
        end = start.replace(month=start.month + 1)
    return make_aware_local(start), make_aware_local(end)


def quarter_bounds(ref: date | None = None):
    ref = ref or local_now().date()
    q = (ref.month - 1) // 3
    start_month = q * 3 + 1
    start = date(ref.year, start_month, 1)
    if start_month == 10:
        end = date(ref.year + 1, 1, 1)
    else:
        end = date(ref.year, start_month + 3, 1)
    return make_aware_local(start), make_aware_local(end)


def academic_year_start_year(ref: date | None = None) -> int:
    ref = ref or local_now().date()
    return ref.year if ref.month >= 9 else ref.year - 1


def academic_year_bounds(start_year: int | None = None, ref: date | None = None):
    if start_year is None:
        start_year = academic_year_start_year(ref)
    return (
        make_aware_local(date(start_year, 9, 1)),
        make_aware_local(date(start_year + 1, 9, 1)),
    )


def academic_year_label(start_year: int) -> str:
    return f'{start_year}–{start_year + 1}'


@dataclass
class PeriodStats:
    dat_count: int = 0
    penalty_total: int = 0

    def as_dict(self):
        return {
            'dat_count': self.dat_count,
            'penalty_total': self.penalty_total,
        }


def _agg_from_qs(qs, result_field='evaluation_result', penalty_field='penalty_score'):
    row = qs.aggregate(
        dat_count=Count('id', filter=Q(**{result_field: EvaluationResult.DAT})),
        penalty_total=Sum(penalty_field),
    )
    return PeriodStats(
        dat_count=row['dat_count'] or 0,
        penalty_total=int(row['penalty_total'] or 0),
    )


def merge_stats(*stats_list: PeriodStats) -> PeriodStats:
    return PeriodStats(
        dat_count=sum(s.dat_count for s in stats_list),
        penalty_total=sum(s.penalty_total for s in stats_list),
    )


def personal_assignment_qs(user, start=None, end=None):
    """
    Assignment cá nhân / sub-task đã duyệt.
    Không tính assignment đại diện của Task Chủ trì–Phối hợp
    (kết quả cá nhân lấy từ TaskParticipation).
    """
    qs = TaskAssignment.objects.filter(
        assignee=user,
        evaluation_result__isnull=False,
        reviewed_at__isnull=False,
    ).filter(
        Q(task__primary_department__isnull=True) | Q(task__is_subtask=True)
    )
    if start is not None:
        qs = qs.filter(reviewed_at__gte=start)
    if end is not None:
        qs = qs.filter(reviewed_at__lt=end)
    return qs


def personal_participation_qs(user, start=None, end=None):
    qs = TaskParticipation.objects.filter(
        user=user,
        evaluation__in=[
            EvaluationResult.DAT,
            EvaluationResult.CHO_LAM_LAI,
            EvaluationResult.TRE_BI_TRU_DIEM,
        ],
        evaluated_at__isnull=False,
    )
    if start is not None:
        qs = qs.filter(evaluated_at__gte=start)
    if end is not None:
        qs = qs.filter(evaluated_at__lt=end)
    return qs


def department_assignment_qs(department, start=None, end=None):
    qs = TaskAssignment.objects.filter(
        assignee_department=department,
        evaluation_result__isnull=False,
        reviewed_at__isnull=False,
    )
    if start is not None:
        qs = qs.filter(reviewed_at__gte=start)
    if end is not None:
        qs = qs.filter(reviewed_at__lt=end)
    return qs


def classic_team_department_qs(department, start=None, end=None):
    qs = TaskAssignment.objects.filter(
        task__primary_department__isnull=False,
        task__is_subtask=False,
        evaluation_result__isnull=False,
        reviewed_at__isnull=False,
        assignee_id=F('task__primary_department__leader_id'),
    ).filter(
        Q(task__primary_department=department)
        | Q(task__coordinating_departments=department)
    ).distinct()
    if start is not None:
        qs = qs.filter(reviewed_at__gte=start)
    if end is not None:
        qs = qs.filter(reviewed_at__lt=end)
    return qs


def stats_for_person(user, start=None, end=None) -> PeriodStats:
    a = _agg_from_qs(personal_assignment_qs(user, start, end))
    p = _agg_from_qs(
        personal_participation_qs(user, start, end),
        result_field='evaluation',
        penalty_field='penalty_score',
    )
    return merge_stats(a, p)


def stats_for_department(department, start=None, end=None) -> PeriodStats:
    batch = _agg_from_qs(department_assignment_qs(department, start, end))
    classic = _agg_from_qs(classic_team_department_qs(department, start, end))
    return merge_stats(batch, classic)


def _period_card_bundle(stats_fn, ref: date | None = None):
    ref = ref or local_now().date()
    m_start, m_end = month_bounds(ref)
    q_start, q_end = quarter_bounds(ref)
    ay = academic_year_start_year(ref)
    a_start, a_end = academic_year_bounds(ay)
    month = stats_fn(m_start, m_end)
    quarter = stats_fn(q_start, q_end)
    academic = stats_fn(a_start, a_end)
    return {
        'month': month.as_dict(),
        'quarter': quarter.as_dict(),
        'academic_year': academic.as_dict(),
        'academic_year_label': academic_year_label(ay),
        'penalty_month': month.penalty_total,
        'penalty_quarter': quarter.penalty_total,
        'penalty_academic_year': academic.penalty_total,
    }


def person_period_cards(user, ref: date | None = None):
    return _period_card_bundle(
        lambda start, end: stats_for_person(user, start, end),
        ref=ref,
    )


def department_period_cards(department, ref: date | None = None):
    return _period_card_bundle(
        lambda start, end: stats_for_department(department, start, end),
        ref=ref,
    )


def _system_stats(start, end) -> PeriodStats:
    asg = TaskAssignment.objects.filter(
        evaluation_result__isnull=False,
        reviewed_at__isnull=False,
        reviewed_at__gte=start,
        reviewed_at__lt=end,
    )
    non_classic = asg.filter(
        Q(task__primary_department__isnull=True) | Q(task__is_subtask=True)
    )
    classic_can = asg.filter(
        task__primary_department__isnull=False,
        task__is_subtask=False,
        assignee_id=F('task__primary_department__leader_id'),
    )
    a_stats = merge_stats(_agg_from_qs(non_classic), _agg_from_qs(classic_can))
    p_stats = _agg_from_qs(
        TaskParticipation.objects.filter(
            evaluation__in=[
                EvaluationResult.DAT,
                EvaluationResult.CHO_LAM_LAI,
                EvaluationResult.TRE_BI_TRU_DIEM,
            ],
            evaluated_at__isnull=False,
            evaluated_at__gte=start,
            evaluated_at__lt=end,
        ),
        result_field='evaluation',
        penalty_field='penalty_score',
    )
    return merge_stats(a_stats, p_stats)


def system_period_cards(ref: date | None = None):
    ref = ref or local_now().date()
    m_start, m_end = month_bounds(ref)
    q_start, q_end = quarter_bounds(ref)
    ay = academic_year_start_year(ref)
    a_start, a_end = academic_year_bounds(ay)
    month = _system_stats(m_start, m_end)
    quarter = _system_stats(q_start, q_end)
    academic = _system_stats(a_start, a_end)
    return {
        'month': month.as_dict(),
        'quarter': quarter.as_dict(),
        'academic_year': academic.as_dict(),
        'academic_year_label': academic_year_label(ay),
        'penalty_month': month.penalty_total,
        'penalty_quarter': quarter.penalty_total,
        'penalty_academic_year': academic.penalty_total,
    }


def _period_columns(period: str, year: int):
    if period == 'quarter':
        columns = ['Q1', 'Q2', 'Q3', 'Q4']
        ranges = [
            (make_aware_local(date(year, 1, 1)), make_aware_local(date(year, 4, 1))),
            (make_aware_local(date(year, 4, 1)), make_aware_local(date(year, 7, 1))),
            (make_aware_local(date(year, 7, 1)), make_aware_local(date(year, 10, 1))),
            (
                make_aware_local(date(year, 10, 1)),
                make_aware_local(date(year + 1, 1, 1)),
            ),
        ]
        return columns, ranges

    if period == 'academic_year':
        columns = [
            'T09', 'T10', 'T11', 'T12',
            'T01', 'T02', 'T03', 'T04', 'T05', 'T06', 'T07', 'T08',
        ]
        ranges = []
        for m in range(9, 13):
            start = date(year, m, 1)
            end = date(year + 1, 1, 1) if m == 12 else date(year, m + 1, 1)
            ranges.append((make_aware_local(start), make_aware_local(end)))
        for m in range(1, 9):
            start = date(year + 1, m, 1)
            end = date(year + 1, m + 1, 1) if m < 8 else date(year + 1, 9, 1)
            ranges.append((make_aware_local(start), make_aware_local(end)))
        return columns, ranges

    columns = [f'T{m:02d}' for m in range(1, 13)]
    ranges = []
    for m in range(1, 13):
        start = date(year, m, 1)
        end = date(year + 1, 1, 1) if m == 12 else date(year, m + 1, 1)
        ranges.append((make_aware_local(start), make_aware_local(end)))
    return columns, ranges


def build_person_matrix(period: str, year: int):
    staff_list = list(
        User.objects.filter(is_manager=False, is_active=True).order_by(
            'last_name', 'first_name'
        )
    )
    columns, ranges = _period_columns(period, year)
    matrix = {
        s.id: {c: {'dat_count': 0, 'penalty_total': 0} for c in columns}
        for s in staff_list
    }
    for staff in staff_list:
        for col, (start, end) in zip(columns, ranges):
            matrix[staff.id][col] = stats_for_person(staff, start, end).as_dict()
    return staff_list, columns, matrix


def build_department_matrix(period: str, year: int):
    dept_list = list(Department.objects.select_related('leader').order_by('name'))
    columns, ranges = _period_columns(period, year)
    matrix = {
        d.id: {c: {'dat_count': 0, 'penalty_total': 0} for c in columns}
        for d in dept_list
    }
    for dept in dept_list:
        for col, (start, end) in zip(columns, ranges):
            matrix[dept.id][col] = stats_for_department(dept, start, end).as_dict()
    return dept_list, columns, matrix


def led_departments_for(user):
    return list(Department.objects.filter(leader=user).order_by('name'))
