import json
from datetime import timedelta
from io import BytesIO

from django.contrib import messages
from django.contrib.auth.decorators import login_required
from django.core.exceptions import PermissionDenied
from django.core.paginator import Paginator
from django.db import transaction
from django.db.models import Count, Prefetch, Q
from django.http import Http404, HttpResponse, HttpResponseForbidden, JsonResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse
from django.utils import timezone
from django.views.decorators.http import require_http_methods, require_POST
from openpyxl import Workbook

from accounts.decorators import assigner_required, can_assign_required, manager_required
from accounts.models import Department, User

from .forms import (
    AddMembersForm,
    CoordinatingProofForm,
    InternalEvaluationForm,
    ReviewForm,
    StaffTaskUpdateForm,
    SubtaskCreateForm,
    SubtaskEditForm,
    SubtaskReviewForm,
    TaskAssignForm,
    TaskCreateForm,
    TaskEditForm,
)
from .models import (
    CoordinatingProof,
    EvaluationResult,
    Notification,
    Task,
    TaskAssignment,
    TaskAttachment,
    TaskParticipation,
)
from .reporting import (
    academic_year_label,
    academic_year_start_year,
    build_department_matrix,
    build_person_matrix,
    department_period_cards,
    led_departments_for,
    person_period_cards,
    system_period_cards,
)


# ───────────────────────── Helpers ─────────────────────────


def _staff_assignment_q(user):
    """Assignment thuộc user cá nhân hoặc Tổ/Nhóm mà user đang là Trưởng tổ."""
    return Q(assignee=user) | Q(assignee_department__leader=user)


def _assignment_detail_qs():
    """Base queryset cho trang chi tiết assignment (select/prefetch đủ quan hệ)."""
    return TaskAssignment.objects.select_related(
        'task',
        'task__created_by',
        'task__primary_department',
        'task__primary_department__leader',
        'task__delegated_updater',
        'task__parent_task',
        'assignee',
        'assignee_department',
        'assignee_department__leader',
    ).prefetch_related(
        'task__coordinating_departments',
        'task__coordinating_departments__leader',
    )


def _staff_assignments_qs(user):
    """
    Cô lập danh sách việc theo role:
    - department: việc họ tạo HOẶC được giao
    - staff: chỉ việc được giao (assignee / trưởng tổ nhận theo tổ)
    """
    qs = _assignment_detail_qs()
    if getattr(user, 'is_department', False):
        qs = qs.filter(Q(task__created_by=user) | _staff_assignment_q(user))
    else:
        qs = qs.filter(_staff_assignment_q(user))
    return qs.distinct()


def _is_direct_staff_assignee(assignment, user):
    """True nếu user là người nhận (cá nhân) hoặc Trưởng tổ của bản giao theo tổ."""
    if not user or not getattr(user, 'is_authenticated', False):
        return False
    if assignment.assignee_id == user.id:
        return True
    dept = assignment.assignee_department
    return bool(dept and dept.leader_id == user.id)


def _user_in_assignment_departments(user, assignment):
    """
    Thành viên tổ liên quan tới ĐÚNG bản phân công này (Kanban nội bộ).

    Không mở rộng sang assignment anh/em của cùng batch task (mỗi tổ một bản nộp).
    Không dùng primary/coordinating của Task — tránh tổ B mở bản nộp tổ A.
    """
    if not user or not getattr(user, 'is_authenticated', False):
        return False
    member_dept_ids = set(
        Department.objects.filter(
            Q(members=user) | Q(leader=user),
        ).values_list('pk', flat=True)
    )
    if not member_dept_ids:
        return False

    if assignment.assignee_department_id in member_dept_ids:
        return True

    if assignment.assignee_id and Department.objects.filter(
        pk__in=member_dept_ids,
    ).filter(
        Q(members=assignment.assignee) | Q(leader=assignment.assignee),
    ).exists():
        return True

    return False


def _user_historically_related_to_task(user, task):
    """
    Liên quan lịch sử tới việc:
    - người tạo task (created_by), hoặc
    - từng tham gia (TaskParticipation), hoặc
    - tạo sub-task / giao nội bộ (created_by của task con).
    """
    if not user or not getattr(user, 'is_authenticated', False):
        return False
    if task.created_by_id == user.id:
        return True
    if task.participations.filter(user_id=user.id).exists():
        return True
    if Task.objects.filter(parent_task=task, created_by=user).exists():
        return True
    return False


def _user_oversees_assignment(user, assignment):
    """
    Được xem chi tiết assignment nếu:
    - là assignee / trưởng tổ bản giao theo tổ, hoặc
    - Ban Giám đốc, hoặc
    - Trưởng tổ Chủ trì/Phối hợp của task (hiện tại), hoặc
    - Trưởng tổ của tổ chứa người được giao (sau khi chuyển giao nội bộ), hoặc
    - người tạo task (created_by) — kể cả khi không còn là trưởng tổ / role Tổ, hoặc
    - liên quan lịch sử (tham gia / tạo sub-task nội bộ), hoặc
    - thành viên tổ liên quan đúng bản phân công (không sang bản nộp tổ khác).
    """
    if not user or not getattr(user, 'is_authenticated', False):
        return False
    if getattr(user, 'is_director', False) or getattr(user, 'is_manager', False):
        return True
    if _is_direct_staff_assignee(assignment, user):
        return True

    task = assignment.task
    if task.is_department_leader(user):
        return True

    if assignment.assignee_id and user.led_departments.filter(
        members=assignment.assignee,
    ).exists():
        return True

    primary = task.primary_department
    if primary and primary.leader_id == user.id:
        return True

    if _user_historically_related_to_task(user, task):
        return True

    if _user_in_assignment_departments(user, assignment):
        return True

    return False


def _get_staff_assignment(request, pk):
    """
    Resolve TaskAssignment cho staff detail.
    Ưu tiên pk là TaskAssignment.id; nếu không khớp, thử pk là Task.id
    (link Kanban/nhầm id) rồi chọn assignment user được phép xem.

    Assignment tồn tại nhưng không đủ quyền → 403 (không 404, không nhảy sang
    bản nộp tổ khác trên cùng task). Chỉ 404 khi không có bản ghi tương ứng.
    """
    user = request.user
    qs = _assignment_detail_qs()

    assignment = qs.filter(pk=pk).first()
    if assignment is not None:
        if _user_oversees_assignment(user, assignment):
            return assignment
        raise PermissionDenied(
            'Bạn không có quyền xem bản phân công này.'
        )

    # pk có thể là Task.id (link Kanban / nhầm id)
    task = Task.objects.filter(pk=pk).first()
    if task:
        candidates = list(qs.filter(task=task).order_by('pk'))
        for candidate in candidates:
            if _is_direct_staff_assignee(candidate, user):
                return candidate
        for candidate in candidates:
            if _user_oversees_assignment(user, candidate):
                return candidate
        if candidates:
            raise PermissionDenied(
                'Bạn không có quyền xem bản phân công này.'
            )

    raise Http404('No TaskAssignment matches the given query.')


def _manager_root_task_pk(task):
    """manager_task_detail chỉ nhận task gốc (không phải sub-task)."""
    return task.parent_task_id or task.pk


def _batch_department_for_assignment(assignment, user):
    department = assignment.assignee_department
    if (
        department
        and department.leader_id == user.id
        and assignment.task_id
    ):
        return department
    return None


def _managed_department(assignment, user):
    """Tổ mà user được quản lý trên đúng bản phân công đang mở."""
    batch_department = _batch_department_for_assignment(assignment, user)
    if batch_department:
        return batch_department
    task = assignment.task
    if task.is_primary_leader(user):
        return task.primary_department
    return task.get_coordinating_department_for(user)


def _require_any_team_leader(request, assignment):
    return (
        assignment.task.is_department_leader(request.user)
        or _batch_department_for_assignment(assignment, request.user) is not None
    )


def _require_primary_leader(request, assignment):
    return assignment.task.is_primary_leader(request.user)


def _can_manage_scoped_subtask(parent, subtask, user):
    """Bảo đảm Trưởng tổ batch chỉ thao tác sub-task thuộc đúng tổ mình."""
    if not parent.can_manage_subtasks(user):
        return False
    if subtask.scope_department_id:
        department = parent.get_batch_department_for(user)
        return (
            department is not None
            and department.pk == subtask.scope_department_id
        )
    return parent.is_primary_leader(user)


def _parent_assignment_for_user(parent, user):
    return (
        parent.assignments.filter(_staff_assignment_q(user))
        .select_related('assignee_department')
        .first()
    )


# ───────────────────────── Notifications ─────────────────────────


@login_required
def notification_read(request, pk):
    """Đánh dấu đã đọc và chuyển tới chi tiết công việc."""
    notification = get_object_or_404(Notification, pk=pk, recipient=request.user)
    notification.is_read = True
    notification.save(update_fields=['is_read'])

    if notification.related_task_id:
        assignment = (
            TaskAssignment.objects.filter(
                task_id=notification.related_task_id,
            )
            .filter(_staff_assignment_q(request.user))
            .first()
        )
        if assignment and not (request.user.is_director or request.user.is_manager):
            return redirect('staff_task_detail', pk=assignment.pk)
        if request.user.is_director or request.user.is_manager:
            task = Task.objects.filter(pk=notification.related_task_id).first()
            if task and not task.is_subtask:
                return redirect('manager_task_detail', pk=task.pk)
            pending = TaskAssignment.objects.filter(
                task_id=notification.related_task_id,
                status=TaskAssignment.STATUS_PENDING,
            ).first()
            if pending:
                return redirect('manager_review_task', pk=pending.pk)
            return redirect('manager_review_queue')
    return redirect('home')


# ───────────────────────── Staff Portal ─────────────────────────


@login_required
def staff_dashboard(request):
    if request.user.is_director or request.user.is_manager:
        return redirect('manager_dashboard')

    assignments = _staff_assignments_qs(request.user)
    today = timezone.localdate()

    in_progress = assignments.filter(
        status__in=[
            TaskAssignment.STATUS_TODO,
            TaskAssignment.STATUS_IN_PROGRESS,
            TaskAssignment.STATUS_REDO,
        ]
    ).count()
    overdue = assignments.exclude(status=TaskAssignment.STATUS_COMPLETED).filter(
        task__deadline__lt=today
    ).count()

    person_stats = person_period_cards(request.user)
    led_depts = led_departments_for(request.user)
    department_stats = [
        {
            'department': dept,
            'stats': department_period_cards(dept),
        }
        for dept in led_depts
    ]
    recent = assignments.order_by('-updated_at')[:5]

    return render(
        request,
        'staff/dashboard.html',
        {
            'in_progress': in_progress,
            'overdue': overdue,
            'person_stats': person_stats,
            'department_stats': department_stats,
            'recent_tasks': recent,
        },
    )


@login_required
def staff_my_tasks(request):
    if request.user.is_director or request.user.is_manager:
        return redirect('manager_dashboard')

    assignments = _staff_assignments_qs(request.user).order_by('task__deadline')
    status_filter = request.GET.get('status')
    if status_filter:
        assignments = assignments.filter(status=status_filter)

    return render(
        request,
        'staff/my_tasks.html',
        {
            'assignments': assignments,
            'status_filter': status_filter,
            'status_choices': TaskAssignment.STATUS_CHOICES,
        },
    )


@login_required
@require_http_methods(['GET', 'POST'])
def staff_task_detail(request, pk):
    # Ban Giám đốc → trang quản lý (không dùng staff detail)
    if request.user.is_director or request.user.is_manager:
        asg = TaskAssignment.objects.select_related('task').filter(pk=pk).first()
        if asg:
            return redirect('manager_task_detail', pk=_manager_root_task_pk(asg.task))
        task = Task.objects.filter(pk=pk).first()
        if task:
            return redirect('manager_task_detail', pk=_manager_root_task_pk(task))
        return redirect('manager_dashboard')

    assignment = _get_staff_assignment(request, pk)
    task = assignment.task
    is_primary_leader = task.is_primary_leader(request.user)
    is_coord_leader = task.is_coordinating_leader(request.user)
    coord_dept = task.get_coordinating_department_for(request.user) if is_coord_leader else None
    batch_dept = _batch_department_for_assignment(assignment, request.user)
    is_batch_department_leader = batch_dept is not None
    is_delegated = task.delegated_updater_id == request.user.id
    is_direct_assignee = _is_direct_staff_assignee(assignment, request.user)

    # Cập nhật tiến độ trên ĐÚNG bản phân công đang mở:
    # - assignee (cá nhân / trưởng tổ nhận theo tổ) luôn được sửa, kể cả khi
    #   đồng thời là trưởng tổ / created_by / role Tổ;
    # - delegated_updater được sửa (ghi vào canonical trên task tổ).
    # Overseer (trưởng tổ / creator xem việc người khác) → chỉ xem.
    can_update = (
        assignment.status != TaskAssignment.STATUS_COMPLETED
        and (
            is_direct_assignee
            or is_delegated
            or is_batch_department_leader
        )
    )
    is_overseer = not can_update
    can_submit_coord_proof = (
        task.is_team_task
        and is_coord_leader
        and assignment.status not in (TaskAssignment.STATUS_COMPLETED, TaskAssignment.STATUS_PENDING)
    )

    work_assignment = assignment
    # Ủy quyền: ghi vào assignment canonical của Trưởng tổ Chủ trì.
    # Assignee thì luôn ghi đúng bản mình đang mở (không nhảy sang người khác).
    if task.is_team_task and can_update and is_delegated and not is_direct_assignee:
        canonical = task.get_canonical_assignment()
        if canonical:
            work_assignment = canonical

    form = StaffTaskUpdateForm(
        instance=work_assignment,
        is_primary_leader=is_primary_leader,
        is_subtask=task.is_subtask,
    )

    lead_participations = list(
        task.participations.filter(role=TaskParticipation.ROLE_LEAD)
        .select_related('user', 'department')
    ) if task.is_team_task else []
    coord_participations = list(
        task.participations.filter(role=TaskParticipation.ROLE_COORD)
        .select_related('user', 'department')
    ) if task.is_team_task else []

    my_participations = list(task.participations_for_leader(request.user))
    evaluation_participations = list(
        task.participations_for_internal_evaluation(request.user)
    )
    needs_eval = task.needs_internal_evaluation(request.user)

    coord_proofs = list(
        task.coordinating_proofs.select_related('department', 'uploaded_by').all()
    ) if task.is_team_task else []
    my_coord_proof = None
    if coord_dept:
        my_coord_proof = next((p for p in coord_proofs if p.department_id == coord_dept.id), None)
    coord_proof_form = CoordinatingProofForm(instance=my_coord_proof)

    # ── Sub-tasks (WBS) cho Trưởng tổ Chủ trì trên nhiệm vụ gốc ──
    can_manage_subtasks = task.can_manage_subtasks(request.user)
    subtask_rows = []
    subtask_done = subtask_total = subtask_pct = 0
    subtask_form = (
        SubtaskCreateForm(parent_task=task, department=batch_dept)
        if can_manage_subtasks
        else None
    )
    subtask_assignee_options = []
    if can_manage_subtasks or (task.is_team_task and is_primary_leader):
        subs_qs = task.subtasks.select_related(
            'created_by', 'scope_department'
        )
        if is_batch_department_leader:
            subs_qs = subs_qs.filter(scope_department=batch_dept)
        elif task.is_team_task:
            subs_qs = subs_qs.filter(scope_department__isnull=True)
        subs = (
            subs_qs
            .prefetch_related(
                Prefetch(
                    'assignments',
                    queryset=TaskAssignment.objects.select_related('assignee').order_by('pk'),
                )
            )
            .order_by('deadline', 'pk')
        )
        today = timezone.localdate()
        for st in subs:
            eff = st.get_effective_status()
            assignees = list(st.assignments.all())
            pending_asg = [a for a in assignees if a.status == TaskAssignment.STATUS_PENDING]
            subtask_rows.append({
                'task': st,
                'effective_status': eff,
                'status_label': dict(TaskAssignment.STATUS_CHOICES).get(eff, eff),
                'status_pill': {
                    TaskAssignment.STATUS_TODO: 'bg-gray-100 text-gray-700',
                    TaskAssignment.STATUS_IN_PROGRESS: 'bg-blue-100 text-blue-800',
                    TaskAssignment.STATUS_PENDING: 'bg-amber-100 text-amber-800',
                    TaskAssignment.STATUS_COMPLETED: 'bg-emerald-100 text-emerald-800',
                    TaskAssignment.STATUS_REDO: 'bg-rose-100 text-rose-800',
                }.get(eff, 'bg-gray-100 text-gray-700'),
                'assignees': assignees,
                'pending_assignments': pending_asg,
                'is_overdue': st.deadline < today and eff != TaskAssignment.STATUS_COMPLETED,
            })
        subtask_total = len(subtask_rows)
        subtask_done = sum(
            1 for r in subtask_rows
            if r['effective_status'] == TaskAssignment.STATUS_COMPLETED
        )
        subtask_pct = int(round((subtask_done / subtask_total) * 100)) if subtask_total else 0
        participant_qs = task.participations.select_related('user')
        if is_batch_department_leader:
            participant_qs = participant_qs.filter(department=batch_dept)
        for p in participant_qs:
            u = p.user
            if not u.is_active or u.is_director:
                continue
            subtask_assignee_options.append({
                'id': u.id,
                'name': u.get_full_name() or u.username,
                'username': u.username,
                'avatar': u.avatar_url,
            })

    if request.method == 'POST':
        action = request.POST.get('action', 'update_progress')

        if action == 'update_progress':
            if not can_update:
                messages.error(request, 'Bạn không có quyền chốt tiến độ công việc này.')
                return redirect('staff_task_detail', pk=pk)
            if work_assignment.status == TaskAssignment.STATUS_COMPLETED:
                messages.error(request, 'Công việc đã hoàn thành, không thể chỉnh sửa.')
                return redirect('staff_task_detail', pk=pk)

            form = StaffTaskUpdateForm(
                request.POST,
                request.FILES,
                instance=work_assignment,
                is_primary_leader=is_primary_leader,
                is_subtask=task.is_subtask,
            )
            if form.is_valid():
                obj = form.save(commit=False)
                if obj.status == TaskAssignment.STATUS_PENDING:
                    obj.submitted_at = timezone.now()
                obj.save()
                if task.is_team_task:
                    canonical = task.get_canonical_assignment()
                    # Chỉ đồng bộ khi chốt bản canonical (trưởng tổ / ủy quyền),
                    # không lan trạng thái từ assignment thành viên sang cả tổ.
                    if canonical and obj.pk == canonical.pk:
                        task.sync_team_assignment_status(obj)
                messages.success(request, 'Đã cập nhật công việc.')
                return redirect('staff_task_detail', pk=pk)

        elif action == 'submit_coord_proof':
            if not can_submit_coord_proof or not coord_dept:
                return HttpResponseForbidden('Chỉ Trưởng tổ Phối hợp mới được nộp minh chứng này.')
            coord_proof_form = CoordinatingProofForm(
                request.POST,
                request.FILES,
                instance=my_coord_proof,
            )
            if coord_proof_form.is_valid():
                proof = coord_proof_form.save(commit=False)
                proof.task = task
                proof.department = coord_dept
                proof.uploaded_by = request.user
                proof.save()
                # Thông báo Trưởng tổ Chủ trì
                if task.primary_department and task.primary_department.leader_id:
                    Notification.objects.create(
                        recipient_id=task.primary_department.leader_id,
                        message=(
                            f'Tổ {coord_dept.name} đã nộp minh chứng phối hợp cho việc: {task.title}'
                        ),
                        related_task=task,
                    )
                messages.success(request, 'Đã nộp minh chứng cho Đơn vị chủ trì.')
                return redirect('staff_task_detail', pk=pk)

        elif action == 'internal_evaluate':
            if not is_primary_leader:
                return HttpResponseForbidden(
                    'Chỉ Trưởng tổ Chủ trì mới được đánh giá từng cá nhân.'
                )
            canonical = task.get_canonical_assignment()
            if not canonical or canonical.status != TaskAssignment.STATUS_COMPLETED:
                messages.error(
                    request,
                    'Chỉ đánh giá nội bộ sau khi Lãnh đạo đã nghiệm thu công việc.',
                )
                return redirect('staff_task_detail', pk=pk)

            eval_parts = list(
                task.participations_for_internal_evaluation(request.user)
            )
            eval_form = InternalEvaluationForm(request.POST, participations=eval_parts)
            if eval_form.is_valid():
                eval_form.save()
                messages.success(request, 'Đã lưu đánh giá từng cá nhân trong nhiệm vụ.')
                return redirect('staff_task_detail', pk=pk)
            messages.error(request, 'Vui lòng đánh giá đầy đủ tất cả cá nhân trong nhiệm vụ.')

    display_assignment = assignment
    if task.is_team_task:
        canonical = task.get_canonical_assignment()
        if canonical:
            display_assignment = canonical

    # Thành viên có thể thêm: theo tổ của trưởng tổ đang xem
    manage_dept = None
    member_role = None
    if is_batch_department_leader:
        manage_dept = batch_dept
        member_role = TaskParticipation.ROLE_LEAD
    elif is_primary_leader:
        manage_dept = task.primary_department
        member_role = TaskParticipation.ROLE_LEAD
    elif is_coord_leader:
        manage_dept = coord_dept
        member_role = TaskParticipation.ROLE_COORD

    available_members = []
    if manage_dept and display_assignment.status not in (
        TaskAssignment.STATUS_PENDING,
        TaskAssignment.STATUS_COMPLETED,
    ):
        existing_ids = set(task.participations.values_list('user_id', flat=True))
        existing_ids.add(request.user.id)
        # Không thêm các trưởng tổ khác đã có assignment
        existing_ids.update(task.assignments.values_list('assignee_id', flat=True))
        available_members = list(
            User.objects.filter(
                my_departments=manage_dept,
                is_active=True,
            )
            .exclude(role=User.ROLE_DIRECTOR)
            .exclude(pk__in=existing_ids)
            .order_by('last_name', 'first_name')
        )

    open_evaluate = request.GET.get('evaluate') == '1' or (
        needs_eval and request.method == 'GET'
    )

    return render(
        request,
        'staff/task_detail.html',
        {
            'assignment': display_assignment,
            'my_assignment': assignment,
            'task': task,
            'form': form,
            'coord_proof_form': coord_proof_form,
            'my_coord_proof': my_coord_proof,
            'coord_proofs': coord_proofs,
            'proof_max_mb': TaskAssignment.PROOF_MAX_SIZE_MB,
            'can_update': can_update,
            'is_overseer': is_overseer,
            'can_submit_coord_proof': can_submit_coord_proof,
            'is_primary_leader': is_primary_leader,
            'is_coord_leader': is_coord_leader,
            'is_team_leader': is_primary_leader or is_coord_leader,
            'can_manage_members': (
                is_primary_leader
                or is_coord_leader
                or is_batch_department_leader
            ),
            'is_team_task': task.is_team_task,
            'is_department_assignment': assignment.is_department_target,
            'is_batch_department_leader': is_batch_department_leader,
            'is_subtask': task.is_subtask,
            'is_delegated_updater': is_delegated,
            'coord_dept': coord_dept,
            'lead_participations': lead_participations,
            'coord_participations': coord_participations,
            'my_participations': my_participations,
            'evaluation_participations': evaluation_participations,
            'available_members': available_members,
            'manage_dept': manage_dept,
            'open_evaluate_modal': (
                open_evaluate and bool(evaluation_participations)
            ),
            'needs_eval': needs_eval,
            'eval_none': TaskParticipation.EVAL_NONE,
            'eval_choices': EvaluationResult.CHOICES,
            'eval_dat': EvaluationResult.DAT,
            'eval_cho_lam_lai': EvaluationResult.CHO_LAM_LAI,
            'eval_tre_bi_tru_diem': EvaluationResult.TRE_BI_TRU_DIEM,
            'can_manage_subtasks': can_manage_subtasks,
            'subtask_rows': subtask_rows,
            'subtask_done': subtask_done,
            'subtask_total': subtask_total,
            'subtask_pct': subtask_pct,
            'subtask_form': subtask_form,
            'subtask_assignee_options_json': json.dumps(
                subtask_assignee_options, ensure_ascii=False
            ),
            'has_subtask_assignees': bool(subtask_assignee_options),
            'subtask_review_form': SubtaskReviewForm(),
            'open_subtask_modal': request.GET.get('subtask') == '1',
        },
    )


@login_required
@require_POST
def staff_task_create_subtask(request, pk):
    """Trưởng tổ tạo nhiệm vụ con trong phạm vi bản phân công của tổ."""
    if request.user.is_director or request.user.is_manager:
        return redirect('manager_dashboard')

    parent_assignment = _get_staff_assignment(request, pk)
    parent = parent_assignment.task
    if not parent.can_manage_subtasks(request.user):
        return HttpResponseForbidden('Bạn không có quyền phân rã công việc này.')

    scope_department = _batch_department_for_assignment(
        parent_assignment, request.user
    )
    form = SubtaskCreateForm(
        request.POST,
        parent_task=parent,
        department=scope_department,
    )
    if not form.is_valid():
        for field_errors in form.errors.values():
            for e in field_errors:
                messages.error(request, e)
        return redirect(f"{reverse('staff_task_detail', args=[pk])}?subtask=1")

    with transaction.atomic():
        sub = Task(
            title=form.cleaned_data['title'].strip(),
            description='',
            created_by=request.user,
            deadline=form.cleaned_data['deadline'],
            cycle=parent.cycle,
            primary_department=None,
            parent_task=parent,
            scope_department=scope_department,
        )
        sub.full_clean()
        sub.save()
        for user in form.cleaned_data['assignees']:
            TaskAssignment.objects.get_or_create(task=sub, assignee=user)

    messages.success(
        request,
        f'Đã tạo nhiệm vụ con "{sub.title}" và giao cho '
        f'{form.cleaned_data["assignees"].count()} người.',
    )
    return redirect('staff_task_detail', pk=pk)


def _redirect_to_parent_detail(parent, user):
    parent_assignment = _parent_assignment_for_user(parent, user)
    if parent_assignment:
        return redirect('staff_task_detail', pk=parent_assignment.pk)
    return redirect('staff_my_tasks')


@login_required
@require_POST
def staff_subtask_edit(request, pk):
    """Trưởng tổ Chủ trì sửa tiêu đề, deadline và điểm của nhiệm vụ con."""
    if request.user.is_director or request.user.is_manager:
        return redirect('manager_dashboard')

    subtask = get_object_or_404(
        Task.objects.select_related(
            'parent_task',
            'parent_task__primary_department',
        ),
        pk=pk,
        is_subtask=True,
        parent_task__isnull=False,
    )
    parent = subtask.parent_task
    if not _can_manage_scoped_subtask(parent, subtask, request.user):
        return HttpResponseForbidden(
            'Bạn không có quyền sửa nhiệm vụ con của tổ khác.'
        )

    form = SubtaskEditForm(
        request.POST,
        instance=subtask,
        parent_task=parent,
    )
    if form.is_valid():
        updated = form.save(commit=False)
        updated.full_clean()
        updated.save()
        messages.success(request, f'Đã cập nhật nhiệm vụ con "{updated.title}".')
    else:
        for field_errors in form.errors.values():
            for error in field_errors:
                messages.error(request, error)
    return _redirect_to_parent_detail(parent, request.user)


@login_required
@require_POST
def staff_subtask_delete(request, pk):
    """Trưởng tổ Chủ trì xóa nhiệm vụ con và dữ liệu liên quan (CASCADE)."""
    if request.user.is_director or request.user.is_manager:
        return redirect('manager_dashboard')

    subtask = get_object_or_404(
        Task.objects.select_related(
            'parent_task',
            'parent_task__primary_department',
        ),
        pk=pk,
        is_subtask=True,
        parent_task__isnull=False,
    )
    parent = subtask.parent_task
    if not _can_manage_scoped_subtask(parent, subtask, request.user):
        return HttpResponseForbidden(
            'Bạn không có quyền xóa nhiệm vụ con của tổ khác.'
        )

    title = subtask.title
    subtask.delete()
    messages.success(request, f'Đã xóa nhiệm vụ con "{title}".')
    return _redirect_to_parent_detail(parent, request.user)


@login_required
@require_POST
def staff_subtask_review(request, pk):
    """
    Trưởng tổ Chủ trì nghiệm thu assignment của nhiệm vụ con.
    pk = TaskAssignment.pk của sub-task đang chờ duyệt.
    """
    if request.user.is_director or request.user.is_manager:
        return redirect('manager_dashboard')

    child_assignment = get_object_or_404(
        TaskAssignment.objects.select_related(
            'task',
            'task__parent_task',
            'task__parent_task__primary_department',
            'assignee',
        ),
        pk=pk,
        status=TaskAssignment.STATUS_PENDING,
    )
    subtask = child_assignment.task
    parent = subtask.parent_task
    if not subtask.is_subtask or not parent:
        return HttpResponseForbidden('Không phải nhiệm vụ con.')
    if not _can_manage_scoped_subtask(parent, subtask, request.user):
        return HttpResponseForbidden(
            'Bạn không có quyền nghiệm thu nhiệm vụ con của tổ khác.'
        )

    form = SubtaskReviewForm(request.POST)
    if not form.is_valid():
        messages.error(request, 'Vui lòng chọn kết quả nghiệm thu.')
        parent_asg = _parent_assignment_for_user(parent, request.user)
        if parent_asg:
            return redirect('staff_task_detail', pk=parent_asg.pk)
        return redirect('staff_my_tasks')

    result = form.cleaned_data['evaluation_result']
    child_assignment.apply_review(
        evaluation_result=result,
        comment=form.cleaned_data['manager_comment'],
    )
    child_assignment.save()

    label = EvaluationResult.LABELS.get(result, result)
    messages.success(
        request,
        f'Đã đánh giá "{subtask.title}" cho {child_assignment.assignee}: {label}.',
    )

    parent_asg = _parent_assignment_for_user(parent, request.user)
    if parent_asg:
        return redirect('staff_task_detail', pk=parent_asg.pk)
    return redirect('staff_my_tasks')


@login_required
@require_POST
def staff_task_add_members(request, pk):
    if request.user.is_director or request.user.is_manager:
        return redirect('manager_dashboard')

    assignment = _get_staff_assignment(request, pk)
    task = assignment.task
    if not _require_any_team_leader(request, assignment):
        return HttpResponseForbidden('Chỉ Trưởng tổ mới được thêm thành viên.')

    is_primary = task.is_primary_leader(request.user)
    batch_dept = _batch_department_for_assignment(assignment, request.user)
    coord_dept = task.get_coordinating_department_for(request.user)
    manage_dept = _managed_department(assignment, request.user)
    role = (
        TaskParticipation.ROLE_COORD
        if coord_dept and not is_primary and not batch_dept
        else TaskParticipation.ROLE_LEAD
    )
    if not manage_dept:
        return HttpResponseForbidden('Không xác định được tổ để thêm thành viên.')

    exclude_ids = list(task.participations.values_list('user_id', flat=True))
    exclude_ids.append(request.user.id)
    form = AddMembersForm(
        request.POST,
        department=manage_dept,
        exclude_ids=exclude_ids,
    )
    if not form.is_valid():
        messages.error(request, 'Không thể thêm thành viên. Vui lòng chọn lại.')
        return redirect('staff_task_detail', pk=pk)

    added = 0
    for user in form.cleaned_data['member_ids']:
        part, created = TaskParticipation.objects.get_or_create(
            task=task,
            user=user,
            defaults={
                'department': manage_dept,
                'role': role,
            },
        )
        if created:
            added += 1
        else:
            # Cập nhật role/dept nếu đã tồn tại nhưng thiếu
            updates = []
            if not part.department_id:
                part.department = manage_dept
                updates.append('department')
            if part.role != role and is_primary:
                part.role = role
                updates.append('role')
            if updates:
                part.save(update_fields=updates)
        # Batch giữ assignment cha theo tổ; user chỉ nhận assignment trên sub-task.
        if not batch_dept:
            TaskAssignment.objects.get_or_create(task=task, assignee=user)

    if added:
        messages.success(request, f'Đã thêm {added} thành viên vào công việc.')
    else:
        messages.info(request, 'Các thành viên đã chọn đã có trong danh sách.')
    return redirect('staff_task_detail', pk=pk)


@login_required
@require_POST
def staff_task_set_delegate(request, pk):
    if request.user.is_director or request.user.is_manager:
        return redirect('manager_dashboard')

    assignment = _get_staff_assignment(request, pk)
    task = assignment.task
    if not _require_primary_leader(request, assignment):
        return HttpResponseForbidden('Chỉ Trưởng tổ Chủ trì mới được ủy quyền báo cáo.')

    user_id = request.POST.get('user_id')
    clear = request.POST.get('clear') == '1'

    if clear:
        task.delegated_updater = None
        task.save(update_fields=['delegated_updater', 'updated_at'])
        messages.success(request, 'Đã hủy ủy quyền báo cáo.')
        return redirect('staff_task_detail', pk=pk)

    member = get_object_or_404(
        TaskParticipation.objects.select_related('user'),
        task=task,
        user_id=user_id,
        role=TaskParticipation.ROLE_LEAD,
    )
    task.delegated_updater = member.user
    task.save(update_fields=['delegated_updater', 'updated_at'])
    Notification.objects.create(
        recipient=member.user,
        message=f'Bạn được ủy quyền cập nhật tiến độ cho việc: {task.title}',
        related_task=task,
    )
    messages.success(
        request,
        f'Đã ủy quyền báo cáo cho {member.user.get_full_name() or member.user.username}.',
    )
    return redirect('staff_task_detail', pk=pk)


@login_required
@require_POST
def staff_task_remove_member(request, pk):
    if request.user.is_director or request.user.is_manager:
        return redirect('manager_dashboard')

    assignment = _get_staff_assignment(request, pk)
    task = assignment.task
    if not _require_any_team_leader(request, assignment):
        return HttpResponseForbidden('Chỉ Trưởng tổ mới được gỡ thành viên.')

    user_id = request.POST.get('user_id')
    manage_dept = _managed_department(assignment, request.user)
    part = get_object_or_404(
        TaskParticipation,
        task=task,
        user_id=user_id,
        department=manage_dept,
    )

    # Chỉ gỡ thành viên thuộc phạm vi tổ của mình
    batch_dept = _batch_department_for_assignment(assignment, request.user)
    if batch_dept:
        if part.department_id != batch_dept.id:
            return HttpResponseForbidden(
                'Bạn chỉ được gỡ thành viên thuộc tổ mình.'
            )
    elif task.is_primary_leader(request.user):
        if part.role != TaskParticipation.ROLE_LEAD:
            return HttpResponseForbidden('Bạn chỉ được gỡ thành viên tổ Chủ trì.')
    else:
        coord_dept = task.get_coordinating_department_for(request.user)
        if (
            part.role != TaskParticipation.ROLE_COORD
            or not coord_dept
            or part.department_id != coord_dept.id
        ):
            return HttpResponseForbidden('Bạn chỉ được gỡ thành viên tổ Phối hợp của mình.')

    if task.delegated_updater_id == part.user_id:
        task.delegated_updater = None
        task.save(update_fields=['delegated_updater', 'updated_at'])

    member_asg = TaskAssignment.objects.filter(task=task, assignee_id=user_id).first()
    if member_asg and member_asg.status not in (
        TaskAssignment.STATUS_PENDING,
        TaskAssignment.STATUS_COMPLETED,
    ):
        # Không xóa assignment của các trưởng tổ
        if (
            not batch_dept
            and member_asg.assignee_id
            and not task.is_department_leader(member_asg.assignee)
        ):
            member_asg.delete()
    part.delete()
    messages.success(request, 'Đã gỡ thành viên khỏi công việc.')
    return redirect('staff_task_detail', pk=pk)


# ───────────────────────── Manager Portal ─────────────────────────


@manager_required
def manager_dashboard(request):
    staff_qs = User.objects.filter(is_active=True).exclude(role=User.ROLE_DIRECTOR)
    assignments = TaskAssignment.objects.select_related(
        'task',
        'assignee',
        'assignee_department',
        'assignee_department__leader',
    )
    today = timezone.localdate()

    total = assignments.count()
    completed = assignments.filter(status=TaskAssignment.STATUS_COMPLETED).count()
    completion_rate = round((completed / total) * 100, 1) if total else 0

    overdue_list = (
        assignments.exclude(status=TaskAssignment.STATUS_COMPLETED)
        .filter(task__deadline__lt=today)
        .order_by('task__deadline')[:10]
    )

    period_stats = system_period_cards()
    pending_count = assignments.filter(
        status=TaskAssignment.STATUS_PENDING,
        task__is_subtask=False,
    ).count()

    return render(
        request,
        'manager/dashboard.html',
        {
            'completion_rate': completion_rate,
            'total_tasks': total,
            'completed_tasks': completed,
            'pending_count': pending_count,
            'staff_count': staff_qs.count(),
            'overdue_list': overdue_list,
            'period_stats': period_stats,
        },
    )


def _storage_upload_error_message(exc):
    """Human-readable hint when media storage (esp. S3 / Long Van) fails."""
    name = type(exc).__name__
    text = str(exc)
    low = text.lower()
    if 'InvalidAccessKeyId' in text or 'SignatureDoesNotMatch' in text or 'InvalidAccessKey' in text:
        return (
            'Không lưu được file đính kèm: Access Key Long Van S3 không hợp lệ '
            '(InvalidAccessKeyId). Cần tạo lại AccessKey/SecretKey trên Long Van, '
            'cập nhật .env rồi restart edueval — hoặc tạm đặt USE_S3=0 để lưu media trên VPS.'
        )
    if 'NoSuchBucket' in text:
        return (
            'Không lưu được file đính kèm: bucket S3 chưa tồn tại. '
            'Kiểm tra AWS_STORAGE_BUCKET_NAME hoặc chạy manage.py check_s3 --create-bucket.'
        )
    if 'ProxyConnectionError' in name or 'proxy' in low or (
        '403 Forbidden' in text and 'Proxy' in name
    ):
        return (
            'Không lưu được file đính kèm lên S3 (proxy PythonAnywhere chặn host Long Van). '
            'Trên tài khoản PA free hãy đặt USE_S3=0 trong WSGI hoặc xin allowlist '
            's3-hcm5-r1.longvan.net. Chi tiết kỹ thuật đã ghi vào error log.'
        )
    if 'ClientError' in name or '403 Forbidden' in text or 'Forbidden' in text:
        return (
            'Không lưu được file đính kèm lên S3 (403/Forbidden). '
            'Kiểm tra quyền AccessKey trên bucket Long Van, hoặc tạm USE_S3=0 trên VPS. '
            f'Chi tiết: {exc}'
        )
    return f'Không lưu được file đính kèm: {exc}'


@can_assign_required
@require_http_methods(['GET', 'POST'])
def manager_create_task(request):
    """FBV giữ nguyên; tương đương UserPassesTestMixin với can_assign_tasks()."""
    form = TaskAssignForm(
        request.POST or None,
        request.FILES or None,
        user=request.user,
    )
    if request.method == 'POST' and form.is_valid():
        mode = form.cleaned_data['assign_mode']

        def _after_create_redirect(task):
            if request.user.is_director or request.user.is_manager:
                return redirect('manager_task_detail', pk=task.pk)
            # Tổ chuyên môn → danh sách việc đã giao (scoped)
            return redirect('manager_manage_tasks')

        try:
            if mode == TaskAssignForm.ASSIGN_BATCH_DEPARTMENT:
                departments = list(form.cleaned_data['batch_departments'])
                attachments = form.cleaned_data.get('attachments') or []
                with transaction.atomic():
                    task = Task.objects.create(
                        title=form.cleaned_data['title'],
                        description=form.cleaned_data['description'],
                        deadline=form.cleaned_data['deadline'],
                        cycle=form.cleaned_data['cycle'],
                        created_by=request.user,
                        primary_department=None,
                    )
                    for uploaded_file in attachments:
                        try:
                            uploaded_file.seek(0)
                        except (AttributeError, ValueError):
                            pass
                        TaskAttachment.objects.create(
                            task=task,
                            file=uploaded_file,
                            original_name=uploaded_file.name,
                            file_size=uploaded_file.size,
                        )
                    for dept in departments:
                        assignment = TaskAssignment(
                            task=task,
                            assignee_department=dept,
                        )
                        assignment._batch_department_assignment = True
                        assignment.save()

                messages.success(
                    request,
                    f'Đã giao nhiệm vụ chung "{task.title}" cho {len(departments)} Tổ/Nhóm '
                    f'(mỗi tổ một bản nộp độc lập).',
                )
                return _after_create_redirect(task)

            # Tổ/Nhóm — mỗi thành viên tự thực hiện: 1 Task + 1 Assignment / người
            if (
                mode == TaskAssignForm.ASSIGN_DEPARTMENT
                and form.cleaned_data.get('department_execution_mode')
                == TaskAssignForm.EXEC_ALL_MEMBERS
            ):
                members = list(form.cleaned_data['assignees'])
                attachments = form.cleaned_data.get('attachments') or []
                base_title = form.cleaned_data['title']
                dept = form.cleaned_data.get('primary_department')
                first_task = None
                with transaction.atomic():
                    for member in members:
                        display = str(member).strip() or member.username
                        task = Task.objects.create(
                            title=f'{base_title} - [{display}]',
                            description=form.cleaned_data['description'],
                            deadline=form.cleaned_data['deadline'],
                            cycle=form.cleaned_data['cycle'],
                            created_by=request.user,
                            primary_department=None,
                        )
                        for uploaded_file in attachments:
                            try:
                                uploaded_file.seek(0)
                            except (AttributeError, ValueError):
                                pass
                            TaskAttachment.objects.create(
                                task=task,
                                file=uploaded_file,
                                original_name=uploaded_file.name,
                                file_size=uploaded_file.size,
                            )
                        TaskAssignment.objects.create(task=task, assignee=member)
                        if first_task is None:
                            first_task = task

                dept_label = dept.name if dept else 'Tổ/Nhóm'
                messages.success(
                    request,
                    f'Đã giao "{base_title}" cho {len(members)} thành viên {dept_label} '
                    f'(mỗi người một nhiệm vụ riêng).',
                )
                return _after_create_redirect(first_task)

            task = form.save(commit=False)
            task.created_by = request.user
            task.primary_department = form.cleaned_data.get('primary_department')
            task.save()
            coords = form.cleaned_data.get('coordinating_departments') or []
            if coords:
                task.coordinating_departments.set(coords)
            for f in form.cleaned_data.get('attachments') or []:
                TaskAttachment.objects.create(
                    task=task,
                    file=f,
                    original_name=f.name,
                    file_size=f.size,
                )
            assignees = form.cleaned_data['assignees']
            count = 0
            for user in assignees:
                _, created = TaskAssignment.objects.get_or_create(task=task, assignee=user)
                if created:
                    count += 1
            if mode == TaskAssignForm.ASSIGN_DEPARTMENT and task.primary_department:
                coord_names = ', '.join(d.name for d in task.coordinating_departments.all()) or 'không'
                messages.success(
                    request,
                    f'Đã giao việc "{task.title}" — Chủ trì: {task.primary_department.name}; '
                    f'Phối hợp: {coord_names} ({count} trưởng tổ nhận việc).',
                )
            else:
                messages.success(request, f'Đã giao việc "{task.title}" cho {count} nhân viên.')
            if request.user.is_director or request.user.is_manager:
                return redirect('manager_create_task')
            return redirect('manager_manage_tasks')
        except OSError as exc:
            messages.error(request, _storage_upload_error_message(exc))
        except Exception as exc:
            # botocore ClientError / ProxyConnectionError and other storage backends
            mod = type(exc).__module__ or ''
            name = type(exc).__name__
            if (
                'Proxy' in name
                or 'ClientError' in name
                or 'Boto' in mod
                or 'botocore' in mod
                or 'storages' in mod
            ):
                messages.error(request, _storage_upload_error_message(exc))
            else:
                raise

    options = []
    for u in form.fields['assignees'].queryset:
        depts = ', '.join(d.name for d in u.my_departments.all())
        options.append({
            'id': u.id,
            'name': u.get_full_name() or u.username,
            'username': u.username,
            'avatar': u.avatar_url,
            'department': depts,
        })

    dept_options = [
        {'id': d.id, 'name': d.name, 'leader': str(d.leader) if d.leader_id else ''}
        for d in Department.objects.select_related('leader').all()
    ]

    return render(
        request,
        'manager/create_task.html',
        {
            'form': form,
            'staff_options_json': json.dumps(options, ensure_ascii=False),
            'dept_options_json': json.dumps(dept_options, ensure_ascii=False),
            'selected_assignee_ids': json.dumps(
                [str(x) for x in (request.POST.getlist('assignees') if request.method == 'POST' else [])]
            ),
            'selected_coord_ids': json.dumps(
                [str(x) for x in (request.POST.getlist('coordinating_departments') if request.method == 'POST' else [])]
            ),
            'selected_batch_dept_ids': json.dumps(
                [str(x) for x in (
                    request.POST.getlist('batch_departments')
                    if request.method == 'POST'
                    else []
                )]
            ),
            'max_attachment_mb': TaskAttachment.MAX_SIZE_MB,
            'max_attachment_count': TaskAttachment.MAX_COUNT,
        },
    )


STATUS_LABELS = dict(TaskAssignment.STATUS_CHOICES)

STATUS_PILL = {
    TaskAssignment.STATUS_TODO: 'bg-gray-100 text-gray-700',
    TaskAssignment.STATUS_IN_PROGRESS: 'bg-blue-100 text-blue-800',
    TaskAssignment.STATUS_PENDING: 'bg-amber-100 text-amber-800',
    TaskAssignment.STATUS_COMPLETED: 'bg-emerald-100 text-emerald-800',
    TaskAssignment.STATUS_REDO: 'bg-rose-100 text-rose-800',
}

STATUS_PROGRESS = {
    TaskAssignment.STATUS_TODO: 5,
    TaskAssignment.STATUS_IN_PROGRESS: 45,
    TaskAssignment.STATUS_PENDING: 75,
    TaskAssignment.STATUS_COMPLETED: 100,
    TaskAssignment.STATUS_REDO: 30,
}


def _task_display_status(task):
    """Trạng thái tổng quát của một Task (ưu tiên assignment canonical / ưu tiên chờ duyệt)."""
    if task.is_team_task:
        canonical = task.get_canonical_assignment()
        return canonical.status if canonical else TaskAssignment.STATUS_TODO

    statuses = [a.status for a in task.assignments.all()]
    if not statuses:
        return TaskAssignment.STATUS_TODO
    if TaskAssignment.STATUS_PENDING in statuses:
        return TaskAssignment.STATUS_PENDING
    if TaskAssignment.STATUS_REDO in statuses or TaskAssignment.STATUS_IN_PROGRESS in statuses:
        return TaskAssignment.STATUS_IN_PROGRESS
    if all(s == TaskAssignment.STATUS_COMPLETED for s in statuses):
        return TaskAssignment.STATUS_COMPLETED
    if all(s == TaskAssignment.STATUS_TODO for s in statuses):
        return TaskAssignment.STATUS_TODO
    return TaskAssignment.STATUS_IN_PROGRESS


def _task_has_department_assignments(task):
    return any(a.assignee_department_id for a in task.assignments.all())


def _task_progress(task, display_status):
    """
    Tiến độ %:
    - Có thành viên tham gia: tỷ lệ Đạt / tổng
    - Giao đồng loạt (department assignments): Đã nộp (chờ duyệt + hoàn thành) / tổng
    - Nhiều assignment cá nhân: tỷ lệ hoàn thành
    - Còn lại: ước lượng theo trạng thái tổng quát
    """
    if task.is_team_task:
        pct = STATUS_PROGRESS.get(display_status, 0)
        return pct, f'Toàn nhiệm vụ · {STATUS_LABELS.get(display_status, "")}'

    parts = list(task.participations.all())
    if parts:
        passed = sum(1 for p in parts if p.evaluation == TaskParticipation.EVAL_DAT)
        total = len(parts)
        pct = int(round((passed / total) * 100)) if total else 0
        return pct, f'{passed}/{total} thành viên đạt'

    assignments = list(task.assignments.all())
    if _task_has_department_assignments(task) or (
        len(assignments) > 1 and not task.is_team_task
    ):
        submitted = sum(
            1
            for a in assignments
            if a.status in (TaskAssignment.STATUS_PENDING, TaskAssignment.STATUS_COMPLETED)
        )
        total = len(assignments)
        pct = int(round((submitted / total) * 100)) if total else 0
        return pct, f'Đã nộp {submitted}/{total}'

    if len(assignments) > 1:
        done = sum(1 for a in assignments if a.status == TaskAssignment.STATUS_COMPLETED)
        total = len(assignments)
        pct = int(round((done / total) * 100)) if total else 0
        return pct, f'{done}/{total} người hoàn thành'

    pct = STATUS_PROGRESS.get(display_status, 0)
    return pct, STATUS_LABELS.get(display_status, '')


def _enrich_task_row(task, today):
    display_status = _task_display_status(task)
    progress_pct, progress_label = _task_progress(task, display_status)
    is_overdue = (
        task.deadline < today and display_status != TaskAssignment.STATUS_COMPLETED
    )
    task.display_status = display_status
    task.display_status_label = STATUS_LABELS.get(display_status, display_status)
    task.display_status_pill = STATUS_PILL.get(display_status, STATUS_PILL[TaskAssignment.STATUS_TODO])
    task.progress_pct = progress_pct
    task.progress_label = progress_label
    task.row_is_overdue = is_overdue
    task.target_departments = [
        a.assignee_department
        for a in task.assignments.all()
        if a.assignee_department_id
    ]
    return task


def _manager_tasks_queryset(user=None):
    """
    Task gốc (không gồm sub-task) mà user đã giao đi (created_by).
    Dùng cho trang "Công việc đã giao" — chỉ việc mình phân công, kể cả BGH.
    Việc được giao tới xem ở "Việc của tôi" / staff list.
    """
    qs = (
        Task.objects.filter(parent_task__isnull=True)
        .select_related('primary_department', 'created_by')
        .prefetch_related(
            'coordinating_departments',
            Prefetch(
                'assignments',
                queryset=TaskAssignment.objects.select_related(
                    'assignee',
                    'assignee_department',
                    'assignee_department__leader',
                ),
            ),
            Prefetch(
                'participations',
                queryset=TaskParticipation.objects.select_related('user'),
            ),
        )
        .annotate(assignment_count=Count('assignments', distinct=True))
    )
    if user is None:
        return qs
    return qs.filter(created_by=user)


def _user_can_administer_managed_task(user, task):
    """
    Người tạo luôn quản lý được task mình giao.
    BGH (director/manager) giữ oversight toàn trường (chi tiết/sửa qua URL);
    danh sách "đã giao" vẫn chỉ hiện việc mình tạo.
    """
    if not user or not getattr(user, 'is_authenticated', False):
        return False
    if getattr(user, 'is_director', False) or getattr(user, 'is_manager', False):
        return True
    if getattr(user, 'is_department', False) and task.created_by_id == user.id:
        return True
    return False


def _get_managed_task_or_403(user, pk):
    task = get_object_or_404(Task, pk=pk, parent_task__isnull=True)
    if not _user_can_administer_managed_task(user, task):
        raise PermissionDenied('Bạn không có quyền quản lý công việc này.')
    return task


@assigner_required
@require_http_methods(['GET'])
def manager_manage_tasks(request):
    today = timezone.localdate()
    q = (request.GET.get('q') or '').strip()
    status_filter = (request.GET.get('status') or '').strip()
    dept_filter = (request.GET.get('dept') or '').strip()

    base_qs = _manager_tasks_queryset(request.user)

    # Thống kê trên toàn bộ (trước khi lọc toolbar)
    all_tasks = list(base_qs)
    stats = {
        'total': len(all_tasks),
        'in_progress': 0,
        'pending': 0,
        'overdue': 0,
    }
    status_map = {}
    for t in all_tasks:
        st = _task_display_status(t)
        status_map[t.pk] = st
        if st == TaskAssignment.STATUS_IN_PROGRESS or st == TaskAssignment.STATUS_REDO:
            stats['in_progress'] += 1
        elif st == TaskAssignment.STATUS_PENDING:
            stats['pending'] += 1
        if t.deadline < today and st != TaskAssignment.STATUS_COMPLETED:
            stats['overdue'] += 1

    qs = base_qs
    if q:
        qs = qs.filter(Q(title__icontains=q) | Q(description__icontains=q))
    if dept_filter.isdigit():
        dept_id = int(dept_filter)
        qs = qs.filter(
            Q(primary_department_id=dept_id)
            | Q(coordinating_departments__id=dept_id)
            | Q(assignments__assignee_department_id=dept_id)
        ).distinct()
    if status_filter == 'overdue':
        overdue_ids = [
            t.pk for t in qs
            if t.deadline < today
            and status_map.get(t.pk, _task_display_status(t)) != TaskAssignment.STATUS_COMPLETED
        ]
        qs = qs.filter(pk__in=overdue_ids)
    elif status_filter in dict(TaskAssignment.STATUS_CHOICES):
        matched = [pk for pk, st in status_map.items() if st == status_filter]
        qs = qs.filter(pk__in=matched)

    qs = qs.order_by('-created_at')
    paginator = Paginator(qs, 10)
    page_obj = paginator.get_page(request.GET.get('page'))

    rows = [_enrich_task_row(t, today) for t in page_obj.object_list]

    edit_task_id = request.GET.get('edit')
    edit_form = None
    edit_task = None
    if edit_task_id and str(edit_task_id).isdigit():
        edit_task = _get_managed_task_or_403(request.user, int(edit_task_id))
        edit_form = TaskEditForm(instance=edit_task)

    return render(
        request,
        'manager/manage_tasks.html',
        {
            'page_obj': page_obj,
            'tasks': rows,
            'stats': stats,
            'q': q,
            'status_filter': status_filter,
            'dept_filter': dept_filter,
            'departments': Department.objects.all(),
            'status_choices': TaskAssignment.STATUS_CHOICES,
            'edit_form': edit_form,
            'edit_task': edit_task,
            'today': today,
        },
    )


@assigner_required
@require_http_methods(['POST'])
def manager_task_edit(request, pk):
    task = _get_managed_task_or_403(request.user, pk)
    form = TaskEditForm(request.POST, instance=task)
    if form.is_valid():
        form.save()
        messages.success(request, f'Đã cập nhật công việc "{task.title}".')
        return redirect('manager_manage_tasks')
    messages.error(request, 'Không thể lưu — vui lòng kiểm tra lại thông tin.')
    return redirect(f"{reverse('manager_manage_tasks')}?edit={pk}")


@assigner_required
@require_POST
def manager_task_extend(request, pk):
    task = _get_managed_task_or_403(request.user, pk)
    try:
        days = int(request.POST.get('days', 3))
    except (TypeError, ValueError):
        days = 3
    if days not in (3, 5):
        days = 3
    task.deadline = task.deadline + timedelta(days=days)
    task.save(update_fields=['deadline', 'updated_at'])
    messages.success(
        request,
        f'Đã gia hạn "{task.title}" thêm {days} ngày (hạn mới: {task.deadline.strftime("%d/%m/%Y")}).',
    )
    wants_json = (
        request.headers.get('X-Requested-With') == 'XMLHttpRequest'
        or 'application/json' in (request.headers.get('Accept') or '')
    )
    if wants_json:
        return JsonResponse({
            'ok': True,
            'deadline': task.deadline.isoformat(),
            'deadline_display': task.deadline.strftime('%d/%m/%Y'),
            'days': days,
        })
    return redirect('manager_manage_tasks')


@assigner_required
@require_POST
def manager_task_delete(request, pk):
    task = _get_managed_task_or_403(request.user, pk)
    title = task.title
    task.delete()
    messages.success(request, f'Đã xóa công việc "{title}".')
    return redirect('manager_manage_tasks')


@manager_required
def manager_review_queue(request):
    # Sub-task do Trưởng tổ nghiệm thu — không vào hàng đợi Lãnh đạo
    pending_assignments = (
        TaskAssignment.objects.filter(
            status=TaskAssignment.STATUS_PENDING,
            task__is_subtask=False,
        )
        .select_related(
            'task',
            'assignee',
            'assignee_department',
            'assignee_department__leader',
            'task__primary_department',
        )
        .order_by('submitted_at')
    )
    # Task Chủ trì–Phối hợp chỉ có một bản chờ duyệt đại diện cho toàn Task.
    queue = [
        assignment
        for assignment in pending_assignments
        if not assignment.task.is_team_task
        or assignment.pk == getattr(
            assignment.task.get_canonical_assignment(), 'pk', None
        )
    ]
    return render(request, 'manager/review_queue.html', {'queue': queue})


def _apply_manager_review(assignment, evaluation_result, comment):
    assignment.apply_review(evaluation_result=evaluation_result, comment=comment)
    assignment.save()
    if assignment.task.is_team_task:
        assignment.task.sync_team_assignment_status(assignment)
        if evaluation_result == EvaluationResult.DAT:
            leader_id = (
                assignment.task.primary_department.leader_id
                if assignment.task.primary_department_id
                else None
            )
            if leader_id:
                Notification.objects.create(
                    recipient_id=leader_id,
                    message=(
                        f'Lãnh đạo đã nghiệm thu "{assignment.task.title}". '
                        f'Hãy đánh giá từng cá nhân tham gia nhiệm vụ.'
                    ),
                    related_task=assignment.task,
                )


@manager_required
@require_http_methods(['GET', 'POST'])
def manager_review_task(request, pk):
    assignment = get_object_or_404(
        TaskAssignment.objects.select_related(
            'task',
            'assignee',
            'assignee_department',
            'assignee_department__leader',
            'task__primary_department',
        ).prefetch_related('task__participations__user', 'task__coordinating_departments', 'task__coordinating_proofs'),
        pk=pk,
        status=TaskAssignment.STATUS_PENDING,
        task__is_subtask=False,
    )
    if (
        assignment.task.is_team_task
        and assignment.pk
        != getattr(assignment.task.get_canonical_assignment(), 'pk', None)
    ):
        return HttpResponseForbidden(
            'Nhiệm vụ Chủ trì–Phối hợp chỉ được đánh giá một lần cho toàn Task.'
        )
    form = ReviewForm(request.POST or None)
    if request.method == 'POST' and form.is_valid():
        result = form.cleaned_data['evaluation_result']
        _apply_manager_review(
            assignment,
            evaluation_result=result,
            comment=form.cleaned_data['manager_comment'],
        )
        label = EvaluationResult.LABELS.get(result, result)
        messages.success(
            request,
            f'Đã đánh giá {assignment.target_display_name}: {label}.',
        )
        return redirect('manager_review_queue')

    return render(
        request,
        'manager/review_task.html',
        {
            'assignment': assignment,
            'task': assignment.task,
            'form': form,
            'participations': assignment.task.participations.select_related('user').all()
            if assignment.task.is_team_task
            else [],
        },
    )


@assigner_required
@require_http_methods(['GET'])
def manager_task_detail(request, pk):
    allowed = _get_managed_task_or_403(request.user, pk)
    task = get_object_or_404(
        Task.objects.select_related('created_by', 'primary_department', 'primary_department__leader')
        .prefetch_related(
            'coordinating_departments',
            'attachments',
            Prefetch(
                'participations',
                queryset=TaskParticipation.objects.select_related(
                    'user', 'department'
                ),
            ),
            Prefetch(
                'assignments',
                queryset=TaskAssignment.objects.select_related(
                    'assignee',
                    'assignee_department',
                    'assignee_department__leader',
                ).order_by('pk'),
            ),
        ),
        pk=allowed.pk,
        parent_task__isnull=True,
    )
    all_assignments = list(task.assignments.all())
    canonical = task.get_canonical_assignment() if task.is_team_task else None
    assignments = [canonical] if canonical else all_assignments
    submitted = sum(
        1
        for a in assignments
        if a.status in (TaskAssignment.STATUS_PENDING, TaskAssignment.STATUS_COMPLETED)
    )
    total = len(assignments)
    progress_pct = int(round((submitted / total) * 100)) if total else 0
    review_form = ReviewForm()

    return render(
        request,
        'manager/task_detail.html',
        {
            'task': task,
            'assignments': assignments,
            'participations': list(task.participations.all()),
            'submitted_count': submitted,
            'total_count': total,
            'progress_pct': progress_pct,
            'review_form': review_form,
            'status_labels': STATUS_LABELS,
            'status_pill': STATUS_PILL,
        },
    )


@manager_required
@require_POST
def manager_assignment_review(request, pk, assignment_pk):
    task = get_object_or_404(Task, pk=pk, parent_task__isnull=True)
    assignment = get_object_or_404(
        TaskAssignment.objects.select_related(
            'task',
            'assignee',
            'assignee_department',
            'assignee_department__leader',
        ),
        pk=assignment_pk,
        task=task,
        status=TaskAssignment.STATUS_PENDING,
        task__is_subtask=False,
    )
    if (
        task.is_team_task
        and assignment.pk != getattr(task.get_canonical_assignment(), 'pk', None)
    ):
        return HttpResponseForbidden(
            'Nhiệm vụ Chủ trì–Phối hợp chỉ được đánh giá một lần cho toàn Task.'
        )
    form = ReviewForm(request.POST)
    if not form.is_valid():
        err = '; '.join(
            f'{field}: {", ".join(errs)}'
            for field, errs in form.errors.items()
        ) or 'Dữ liệu không hợp lệ'
        messages.error(request, f'Không thể nghiệm thu — {err}.')
        return redirect('manager_task_detail', pk=task.pk)

    result = form.cleaned_data['evaluation_result']
    _apply_manager_review(
        assignment,
        evaluation_result=result,
        comment=form.cleaned_data['manager_comment'],
    )
    label = EvaluationResult.LABELS.get(result, result)
    messages.success(
        request,
        f'Đã đánh giá {assignment.target_display_name}: {label}.',
    )
    return redirect('manager_task_detail', pk=task.pk)


def _statistics_params(request):
    today = timezone.localdate()
    scope = request.GET.get('scope', 'person')
    if scope not in ('person', 'department'):
        scope = 'person'
    period = request.GET.get('period', 'month')
    if period not in ('month', 'quarter', 'academic_year'):
        period = 'month'
    default_year = (
        academic_year_start_year(today)
        if period == 'academic_year'
        else today.year
    )
    try:
        year = int(request.GET.get('year', default_year))
    except (TypeError, ValueError):
        year = default_year
    return scope, period, year


def _build_statistics_rows(scope, period, year):
    if scope == 'department':
        entities, columns, matrix = build_department_matrix(period, year)
        rows = []
        for dept in entities:
            cells = [matrix[dept.id][c] for c in columns]
            rows.append({
                'label': dept.name,
                'sublabel': str(dept.leader) if dept.leader_id else '—',
                'cells': cells,
                'dat_total': sum(c['dat_count'] for c in cells),
                'penalty_total': sum(c['penalty_total'] for c in cells),
            })
        return rows, columns, academic_year_label(year) if period == 'academic_year' else str(year)

    entities, columns, matrix = build_person_matrix(period, year)
    rows = []
    for staff in entities:
        cells = [matrix[staff.id][c] for c in columns]
        rows.append({
            'label': staff.get_full_name() or staff.username,
            'sublabel': staff.department_name,
            'cells': cells,
            'dat_total': sum(c['dat_count'] for c in cells),
            'penalty_total': sum(c['penalty_total'] for c in cells),
        })
    return rows, columns, academic_year_label(year) if period == 'academic_year' else str(year)


@manager_required
def manager_statistics(request):
    scope, period, year = _statistics_params(request)
    rows, columns, period_label = _build_statistics_rows(scope, period, year)
    today = timezone.localdate()
    ay = academic_year_start_year(today)

    return render(
        request,
        'manager/statistics.html',
        {
            'rows': rows,
            'columns': columns,
            'year': year,
            'period': period,
            'scope': scope,
            'period_label': period_label,
            'years': range(today.year - 2, today.year + 2),
            'academic_years': range(ay - 2, ay + 2),
        },
    )


@manager_required
def manager_export_excel(request):
    scope, period, year = _statistics_params(request)
    rows, columns, period_label = _build_statistics_rows(scope, period, year)

    wb = Workbook()
    ws = wb.active
    scope_title = 'Ca_nhan' if scope == 'person' else 'To_nhom'
    ws.title = f'{scope_title}_{year}'[:31]
    header = (
        ['Họ tên', 'Phòng ban']
        if scope == 'person'
        else ['Tổ/Nhóm', 'Trưởng tổ']
    )
    for col in columns:
        header.extend([f'{col} Đạt', f'{col} Trừ'])
    header.extend(['Tổng Đạt', 'Tổng trừ'])
    ws.append(header)

    for row in rows:
        values = [row['label'], row['sublabel']]
        for cell in row['cells']:
            values.extend([cell['dat_count'], cell['penalty_total']])
        values.extend([row['dat_total'], row['penalty_total']])
        ws.append(values)

    buffer = BytesIO()
    wb.save(buffer)
    buffer.seek(0)
    response = HttpResponse(
        buffer.getvalue(),
        content_type=(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ),
    )
    filename = f'thong_ke_{scope}_{period}_{period_label}.xlsx'.replace('–', '-')
    response['Content-Disposition'] = f'attachment; filename="{filename}"'
    return response


# ───────────────────────── Task detail API (Kanban modal) ─────────────────────────


def _safe_file_url(file_field):
    """Trả về URL media / signed S3; None nếu file trống hoặc storage lỗi."""
    if not file_field:
        return None
    try:
        return file_field.url
    except (ValueError, OSError):
        return None


def _collect_task_proofs(task):
    """Gom minh chứng assignment + phối hợp + file đính kèm giao việc."""
    proofs = []
    for asg in task.assignments.all():
        url = _safe_file_url(asg.proof_file)
        if url:
            proofs.append({'name': asg.proof_display_name or 'Minh chứng', 'url': url})
    for cp in task.coordinating_proofs.all():
        url = _safe_file_url(cp.proof_file)
        if url:
            proofs.append({'name': cp.display_name or 'Minh chứng phối hợp', 'url': url})
    for att in task.attachments.all():
        url = _safe_file_url(att.file)
        if url:
            proofs.append({'name': att.display_name or 'File đính kèm', 'url': url})
    return proofs


@login_required
@require_http_methods(['GET'])
def task_detail_api(request, pk):
    """
    GET /api/tasks/<pk>/ — JSON chi tiết task cho modal Kanban.
    pk khớp data-task-id (Task.id); cũng chấp nhận TaskAssignment.id như staff_task_detail.
    """
    try:
        assignment = _get_staff_assignment(request, pk)
    except PermissionDenied:
        return JsonResponse(
            {'error': 'Bạn không có quyền xem nhiệm vụ này.'},
            status=403,
        )
    except Http404:
        return JsonResponse({'error': 'Không tìm thấy nhiệm vụ.'}, status=404)

    task = (
        Task.objects.select_related('created_by')
        .prefetch_related(
            'assignments__assignee',
            'assignments__assignee_department',
            'attachments',
            'coordinating_proofs',
        )
        .filter(pk=assignment.task_id)
        .first()
    ) or assignment.task

    display = _task_display_status(task)
    assignee_names = [
        asg.target_display_name for asg in task.assignments.all()
    ]
    assignee_name = ', '.join(assignee_names) if assignee_names else '—'

    review_asg = assignment
    if task.is_team_task:
        canonical = task.get_canonical_assignment()
        if canonical:
            review_asg = canonical

    grade = (
        review_asg.evaluation_result_label
        if review_asg.evaluation_result
        else '—'
    )
    score = review_asg.penalty_score if review_asg.evaluation_result else None
    comment = review_asg.manager_comment or ''

    return JsonResponse({
        'id': task.pk,
        'title': task.title,
        'description': task.description or '',
        'assignee': assignee_name,
        'assignee_name': assignee_name,
        'deadline': task.deadline.strftime('%d/%m/%Y') if task.deadline else '—',
        'status': display,
        'status_display': STATUS_LABELS.get(display, display),
        'grade': grade,
        'score': score,
        'comment': comment,
        'proofs': _collect_task_proofs(task),
    })
