from django.contrib import messages
from django.contrib.auth import login as auth_login
from django.contrib.auth import logout as auth_logout
from django.contrib.auth import update_session_auth_hash
from django.contrib.auth.decorators import login_required
from django.core.exceptions import PermissionDenied
from django.db.models import Count, Prefetch, Q
from django.http import HttpResponse, JsonResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.urls import reverse
from django.utils import timezone
from django.views.decorators.http import require_http_methods, require_POST
import json
from io import BytesIO

from .decorators import manager_required, redirect_by_role
from .forms import (
    DepartmentForm,
    LoginForm,
    ProfileUpdateForm,
    StaffCreateForm,
    StaffEditForm,
    StaffImportForm,
    StyledPasswordChangeForm,
)
from .models import Department, User
from .user_import import (
    build_import_template_workbook,
    import_users_from_rows,
    read_import_file,
)
from .vietnamese import sort_users_by_vietnamese_name, user_display_full_name
from tasks.forms import DepartmentTaskAssignForm
from tasks.models import Task, TaskAssignment, TaskAttachment, TaskParticipation
from tasks.views import (
    STATUS_LABELS,
    STATUS_PILL,
    _enrich_task_row,
    _status_aggregate,
    _task_display_status,
)


@require_http_methods(['GET', 'POST'])
def login_view(request):
    if request.user.is_authenticated:
        return redirect_by_role(request.user)

    form = LoginForm(request, data=request.POST or None)
    if request.method == 'POST' and form.is_valid():
        auth_login(request, form.get_user())
        messages.success(request, f'Xin chào, {request.user.get_full_name() or request.user.username}!')
        return redirect_by_role(request.user)

    return render(request, 'registration/login.html', {'form': form})


@login_required
def logout_view(request):
    auth_logout(request)
    messages.info(request, 'Bạn đã đăng xuất thành công.')
    return redirect('login')


@login_required
@require_http_methods(['GET', 'POST'])
def profile_view(request):
    profile_form = ProfileUpdateForm(
        request.POST or None,
        request.FILES or None,
        instance=request.user,
    )
    password_form = StyledPasswordChangeForm(user=request.user)

    if request.method == 'POST':
        action = request.POST.get('action')
        if action == 'profile' and profile_form.is_valid():
            profile_form.save()
            messages.success(request, 'Đã cập nhật thông tin cá nhân.')
            return redirect('profile')
        if action == 'password':
            password_form = StyledPasswordChangeForm(user=request.user, data=request.POST)
            if password_form.is_valid():
                user = password_form.save()
                update_session_auth_hash(request, user)
                messages.success(request, 'Đã đổi mật khẩu thành công.')
                return redirect('profile')

    return render(
        request,
        'accounts/profile.html',
        {
            'profile_form': profile_form,
            'password_form': password_form,
        },
    )


@login_required
def home_redirect(request):
    return redirect_by_role(request.user    )


@manager_required
@require_http_methods(['GET'])
def manager_staff_import_template(request):
    """Tải file mẫu Excel import tài khoản."""
    wb = build_import_template_workbook()
    buffer = BytesIO()
    wb.save(buffer)
    buffer.seek(0)
    response = HttpResponse(
        buffer.getvalue(),
        content_type='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    )
    response['Content-Disposition'] = 'attachment; filename="mau_import_tai_khoan.xlsx"'
    return response


# ───────────────────────── Manager: Departments ─────────────────────────


@manager_required
@require_http_methods(['GET', 'POST'])
def manager_departments(request):
    departments = Department.objects.select_related('leader').annotate(
        active_members=Count('members', filter=Q(members__is_active=True))
    ).order_by('name')

    form = DepartmentForm()
    edit_form = None
    edit_dept = None
    view_dept = None

    action = request.POST.get('action') or request.GET.get('action')
    dept_id = request.POST.get('dept_id') or request.GET.get('dept_id')

    if request.method == 'GET' and action == 'edit' and dept_id:
        edit_dept = get_object_or_404(Department, pk=dept_id)
        edit_form = DepartmentForm(instance=edit_dept)
    elif request.method == 'GET' and action == 'members' and dept_id:
        view_dept = get_object_or_404(
            Department.objects.select_related('leader').prefetch_related(
                Prefetch('members', queryset=User.objects.order_by('last_name', 'first_name'))
            ),
            pk=dept_id,
        )

    member_candidates = []
    if view_dept:
        member_ids = set(view_dept.members.values_list('pk', flat=True))
        for u in User.objects.filter(is_active=True).exclude(role=User.ROLE_DIRECTOR).exclude(pk__in=member_ids).order_by(
            'last_name', 'first_name'
        ):
            member_candidates.append({
                'id': u.pk,
                'name': str(u),
                'username': u.username,
                'position': u.position or '',
                'avatar': u.avatar_url,
            })

    if request.method == 'POST':
        if action == 'create':
            form = DepartmentForm(request.POST)
            if form.is_valid():
                form.save()
                messages.success(request, 'Đã thêm Tổ/Nhóm mới.')
                return redirect('manager_departments')
        elif action == 'edit' and dept_id:
            edit_dept = get_object_or_404(Department, pk=dept_id)
            edit_form = DepartmentForm(request.POST, instance=edit_dept)
            if edit_form.is_valid():
                edit_form.save()
                messages.success(request, f'Đã cập nhật tổ "{edit_dept.name}".')
                return redirect('manager_departments')
        elif action == 'delete' and dept_id:
            dept = get_object_or_404(Department, pk=dept_id)
            name = dept.name
            dept.delete()
            messages.success(request, f'Đã xóa tổ "{name}".')
            return redirect('manager_departments')

    return render(
        request,
        'manager/departments.html',
        {
            'departments': departments,
            'form': form,
            'edit_form': edit_form,
            'edit_dept': edit_dept,
            'view_dept': view_dept,
            'member_candidates': member_candidates,
            'open_create': request.method == 'POST' and action == 'create' and form.errors,
            'open_edit': edit_form is not None,
            'open_members': view_dept is not None,
        },
    )


@manager_required
@require_POST
def department_add_member(request, dept_id):
    """AJAX: thêm viên chức vào Tổ/Nhóm."""
    department = get_object_or_404(Department, pk=dept_id)
    user_id = request.POST.get('user_id')
    if not user_id:
        return JsonResponse({'ok': False, 'error': 'Thiếu user_id.'}, status=400)

    user = get_object_or_404(User, pk=user_id, is_active=True)
    if department.members.filter(pk=user.pk).exists():
        return JsonResponse({'ok': False, 'error': 'Người này đã là thành viên của tổ.'}, status=400)

    department.members.add(user)
    return JsonResponse({
        'ok': True,
        'member': {
            'id': user.pk,
            'name': str(user),
            'username': user.username,
            'position': user.position or '—',
            'avatar': user.avatar_url,
            'is_active': user.is_active,
            'is_leader': department.leader_id == user.pk,
        },
        'member_count': department.members.filter(is_active=True).count(),
    })


@manager_required
@require_POST
def department_remove_member(request, dept_id):
    """AJAX: gỡ viên chức khỏi Tổ/Nhóm."""
    department = get_object_or_404(Department, pk=dept_id)
    user_id = request.POST.get('user_id')
    if not user_id:
        return JsonResponse({'ok': False, 'error': 'Thiếu user_id.'}, status=400)

    user = get_object_or_404(User, pk=user_id)
    if not department.members.filter(pk=user.pk).exists():
        return JsonResponse({'ok': False, 'error': 'Người này không thuộc tổ.'}, status=400)

    if department.leader_id == user.pk:
        return JsonResponse({
            'ok': False,
            'error': 'Không thể gỡ Trưởng tổ. Hãy chỉ định Trưởng tổ khác trước.',
        }, status=400)

    department.members.remove(user)
    return JsonResponse({
        'ok': True,
        'user_id': user.pk,
        'member': {
            'id': user.pk,
            'name': str(user),
            'username': user.username,
            'position': user.position or '',
            'avatar': user.avatar_url,
        },
        'member_count': department.members.filter(is_active=True).count(),
    })


# ───────────────────────── Manager: Staff ─────────────────────────


@manager_required
@require_http_methods(['GET', 'POST'])
def manager_staff_list(request):
    dept_filter = request.GET.get('department', '')
    q = request.GET.get('q', '').strip()

    staff_qs = (
        User.objects.exclude(role=User.ROLE_DIRECTOR)
        .prefetch_related('my_departments')
    )
    if dept_filter:
        staff_qs = staff_qs.filter(my_departments__id=dept_filter).distinct()
    if q:
        staff_qs = staff_qs.filter(
            Q(first_name__icontains=q)
            | Q(last_name__icontains=q)
            | Q(username__icontains=q)
            | Q(email__icontains=q)
            | Q(position__icontains=q)
        )

    # Sắp xếp tăng dần: Tên → Tên đệm → Họ (bảng chữ cái tiếng Việt)
    staff_list = sort_users_by_vietnamese_name(list(staff_qs))

    create_form = StaffCreateForm()
    import_form = StaffImportForm()
    edit_form = None
    edit_user = None
    open_create = request.GET.get('action') == 'create'
    open_import = request.GET.get('action') == 'import'
    import_errors = request.session.pop('staff_import_errors', None)
    action = request.POST.get('action')
    staff_id = request.POST.get('staff_id') or request.GET.get('staff_id')

    if request.method == 'GET' and request.GET.get('action') == 'edit' and staff_id:
        edit_user = get_object_or_404(User, pk=staff_id)
        edit_form = StaffEditForm(instance=edit_user, actor=request.user)

    if request.method == 'POST':
        if action == 'create':
            create_form = StaffCreateForm(request.POST)
            open_create = True
            if create_form.is_valid():
                user = create_form.save()
                role_label = user.role_label
                messages.success(
                    request,
                    f'Đã tạo tài khoản {role_label} "{user.get_full_name() or user.username}" '
                    f'(username: {user.username}).',
                )
                return redirect('manager_staff')
        elif action == 'edit' and staff_id:
            edit_user = get_object_or_404(User, pk=staff_id)
            edit_form = StaffEditForm(request.POST, instance=edit_user, actor=request.user)
            if edit_form.is_valid():
                edit_form.save()
                messages.success(request, f'Đã cập nhật viên chức "{edit_user}".')
                return redirect('manager_staff')
        elif action == 'toggle_active' and staff_id:
            staff = get_object_or_404(User, pk=staff_id)
            staff.is_active = not staff.is_active
            staff.save(update_fields=['is_active'])
            state = 'mở khóa' if staff.is_active else 'khóa'
            messages.success(request, f'Đã {state} tài khoản "{staff}".')
            return redirect('manager_staff')
        elif action == 'import':
            import_form = StaffImportForm(request.POST, request.FILES)
            open_import = True
            if import_form.is_valid():
                try:
                    rows = read_import_file(import_form.cleaned_data['file'])
                except ValueError as exc:
                    messages.error(request, str(exc))
                else:
                    if not rows:
                        messages.warning(request, 'File không có dòng dữ liệu nào.')
                    else:
                        outcome = import_users_from_rows(rows)
                        if outcome.created:
                            messages.success(
                                request,
                                f'Đã import thành công {outcome.created} tài khoản.',
                            )
                        if outcome.skipped:
                            messages.warning(
                                request,
                                f'Bỏ qua {outcome.skipped} dòng (lỗi hoặc trùng).',
                            )
                        if outcome.errors:
                            request.session['staff_import_errors'] = [
                                {
                                    'row': e.row_num,
                                    'username': e.username,
                                    'message': e.message,
                                }
                                for e in outcome.errors[:50]
                            ]
                        if outcome.created and not outcome.errors:
                            return redirect('manager_staff')
                        return redirect(f"{reverse('manager_staff')}?action=import")

    return render(
        request,
        'manager/staff_list.html',
        {
            'staff_list': staff_list,
            'departments': Department.objects.all(),
            'dept_filter': dept_filter,
            'q': q,
            'create_form': create_form,
            'import_form': import_form,
            'import_errors': import_errors,
            'edit_form': edit_form,
            'edit_user': edit_user,
            'open_create': open_create,
            'open_import': open_import,
            'open_edit': edit_form is not None,
            'edit_dept_options_json': json.dumps(
                [{'id': d.pk, 'name': d.name} for d in Department.objects.all()],
                ensure_ascii=False,
            ),
            'edit_selected_dept_ids': json.dumps(
                [str(x) for x in edit_form.data.getlist('departments')]
                if edit_form and edit_form.is_bound
                else (
                    [str(d.pk) for d in edit_user.my_departments.all()]
                    if edit_user
                    else []
                )
            ),
        },
    )


@manager_required
@require_http_methods(['GET'])
def manager_staff_import_template(request):
    """Tải file mẫu Excel import tài khoản."""
    wb = build_import_template_workbook()
    buffer = BytesIO()
    wb.save(buffer)
    buffer.seek(0)
    response = HttpResponse(
        buffer.getvalue(),
        content_type='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    )
    response['Content-Disposition'] = 'attachment; filename="mau_import_tai_khoan.xlsx"'
    return response


# ───────────────────────── Department task Workspace ─────────────────────────


def _department_tasks_queryset(department):
    """Công việc liên quan tổ: FK phòng ban, giao theo tổ, hoặc assignee là thành viên."""
    return (
        Task.objects.filter(
            Q(primary_department=department)
            | Q(coordinating_departments=department)
            | Q(assignments__assignee_department=department)
            | Q(assignments__assignee__my_departments=department)
            | Q(source_department=department)
        )
        .select_related(
            'created_by',
            'primary_department',
            'primary_department__leader',
            'source_department',
        )
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
        .distinct()
        .order_by('-created_at')
    )


def _partition_department_tasks_for_kanban(tasks):
    """
    Gom task giao đồng loạt (batch_key / tiêu đề '... - [Tên]') thành nhóm.
    Trả về list (kind, payload) với kind in {'batch', 'single'}.
    """
    batch_groups = {}
    legacy_groups = {}
    standalone = []

    for task in tasks:
        if task.batch_key:
            batch_groups.setdefault(str(task.batch_key), []).append(task)
            continue
        base = Task.strip_member_title_suffix(task.title)
        if base:
            bucket = int(task.created_at.timestamp() // 10) if task.created_at else 0
            key = (task.created_by_id, base, bucket)
            legacy_groups.setdefault(key, []).append(task)
            continue
        standalone.append(task)

    ordered = []
    for group in batch_groups.values():
        newest = max((t.created_at for t in group), default=None)
        ordered.append((newest, 'batch', group))

    for group in legacy_groups.values():
        if len(group) >= 2:
            newest = max((t.created_at for t in group), default=None)
            ordered.append((newest, 'batch', group))
        else:
            standalone.extend(group)

    for task in standalone:
        ordered.append((task.created_at, 'single', task))

    ordered.sort(key=lambda x: x[0] or timezone.now(), reverse=True)
    return [(kind, payload) for _ts, kind, payload in ordered]


def _build_department_kanban_batch_row(member_tasks, user, department, today):
    """Một thẻ Kanban gộp cho nhóm giao đồng loạt theo thành viên."""
    enriched = [_enrich_task_row(t, today) for t in member_tasks]
    anchor = min(enriched, key=lambda t: t.pk)
    title = enriched[0].batch_display_title
    deadline = min((t.deadline for t in enriched), default=anchor.deadline)

    people = []
    statuses = []
    seen_users = set()
    for task in enriched:
        for asg in task.assignments.all():
            if asg.assignee_id:
                if asg.assignee_id in seen_users:
                    continue
                seen_users.add(asg.assignee_id)
                people.append(asg.assignee)
                statuses.append(asg.status)
            elif asg.assignee_department_id and asg.assignee_department.leader_id:
                leader = asg.assignee_department.leader
                if leader.pk in seen_users:
                    continue
                seen_users.add(leader.pk)
                people.append(leader)
                statuses.append(asg.status)

    done, total, _pct, display = _status_aggregate(statuses)
    if not statuses:
        display = _task_display_status(anchor)
        done, total = 0, len(enriched)

    col = _kanban_column_for_status(display)
    is_overdue = any(
        t.deadline < today and _task_display_status(t) != TaskAssignment.STATUS_COMPLETED
        for t in enriched
    )

    # Ghi đè field hiển thị trên anchor (chỉ trong memory, không save)
    anchor.title = title
    anchor.deadline = deadline
    anchor.display_status = display
    anchor.display_status_label = STATUS_LABELS.get(display, display)
    anchor.display_status_pill = STATUS_PILL.get(
        display, STATUS_PILL[TaskAssignment.STATUS_TODO]
    )
    anchor.row_is_overdue = is_overdue
    anchor.progress_label = f'{done}/{total}' if total else ''

    # Không kéo thẻ nhóm — trạng thái từng người khác nhau
    action = None
    if user.is_director:
        action = {
            'label': 'Xem chi tiết nhóm',
            'url': reverse('manager_task_detail', args=[anchor.pk]),
            'kind': 'review',
        }

    return {
        'task': anchor,
        'assignees': people,
        'primary_assignee': people[0] if people else None,
        'action': action,
        'kanban_column': col,
        'can_drag': False,
        'is_batch_group': True,
        'batch_done': done,
        'batch_total': total,
        'batch_task_ids': [t.pk for t in sorted(enriched, key=lambda t: t.pk)],
        'member_tasks': enriched,
    }


def _build_department_kanban_rows(tasks, user, department, today):
    """Danh sách thẻ Kanban: 1 thẻ / batch, 1 thẻ / việc đơn."""
    rows = []
    for kind, payload in _partition_department_tasks_for_kanban(tasks):
        if kind == 'batch':
            group = payload
            if len(group) >= 2:
                rows.append(
                    _build_department_kanban_batch_row(group, user, department, today)
                )
                continue
            # Chỉ còn 1 task của batch trong tổ này → hiện như việc đơn (bỏ hậu tố tên)
            payload = group[0]

        task = payload
        st = _task_display_status(task)
        col = _kanban_column_for_status(st)
        enriched = _enrich_task_row(task, today)
        if Task.strip_member_title_suffix(enriched.title) or enriched.batch_key:
            enriched.title = enriched.batch_display_title
        assignees = _department_task_assignees(enriched)
        can_drag = _user_can_update_task_status(user, enriched, department)
        rows.append({
            'task': enriched,
            'assignees': assignees,
            'primary_assignee': assignees[0] if assignees else None,
            'action': _department_task_action(enriched, user, department),
            'kanban_column': col,
            'can_drag': can_drag,
            'is_batch_group': False,
            'batch_done': None,
            'batch_total': None,
            'batch_task_ids': [enriched.pk],
            'member_tasks': [enriched],
        })
    return rows


def _department_task_action(task, user, department=None):
    """Nút hành động theo role: nghiệm thu (lãnh đạo/tổ) hoặc cập nhật tiến độ (assignee)."""
    assignments = list(task.assignments.all())
    my_asg = next((a for a in assignments if a.assignee_id == user.id), None)
    if my_asg is None:
        my_asg = next(
            (
                a for a in assignments
                if a.assignee_department_id
                and a.assignee_department.leader_id == user.id
            ),
            None,
        )
    pending = next(
        (a for a in assignments if a.status == TaskAssignment.STATUS_PENDING),
        None,
    )

    if user.is_director:
        if pending:
            return {
                'label': 'Nghiệm thu / Chấm điểm',
                'url': reverse('manager_review_task', args=[pending.pk]),
                'kind': 'review',
            }
        return {
            'label': 'Nghiệm thu / Chấm điểm',
            'url': reverse('manager_task_detail', args=[task.pk]),
            'kind': 'review',
        }

    is_dept_overseer = (
        user.is_department
        or (department is not None and department.leader_id == user.id)
        or task.created_by_id == user.id
    )
    if is_dept_overseer:
        # Ưu tiên assignment của chính trưởng tổ; nếu đã chuyển giao → xem bản của thành viên.
        if my_asg:
            return {
                'label': 'Cập nhật tiến độ',
                'url': reverse('staff_task_detail', args=[my_asg.pk]),
                'kind': 'progress',
            }
        target = pending or (assignments[0] if assignments else None)
        if target:
            return {
                'label': 'Xem chi tiết',
                'url': reverse('staff_task_detail', args=[target.pk]),
                'kind': 'review',
            }
        # Fallback: pk Task — staff_task_detail sẽ resolve sang assignment phù hợp.
        return {
            'label': 'Xem chi tiết',
            'url': reverse('staff_task_detail', args=[task.pk]),
            'kind': 'review',
        }

    if my_asg:
        return {
            'label': 'Cập nhật tiến độ',
            'url': reverse('staff_task_detail', args=[my_asg.pk]),
            'kind': 'progress',
        }
    return None


def _department_task_assignees(task):
    """Danh sách người nhận hiển thị (avatar + tên)."""
    people = []
    seen = set()
    for asg in task.assignments.all():
        if asg.assignee_id and asg.assignee_id not in seen:
            seen.add(asg.assignee_id)
            people.append(asg.assignee)
        elif asg.assignee_department_id and asg.assignee_department.leader_id:
            leader = asg.assignee_department.leader
            if leader.pk not in seen:
                seen.add(leader.pk)
                people.append(leader)
    return people


# Kanban columns ↔ TaskAssignment.status
# todo          → Cần làm          ← todo
# in_progress   → Đang làm         ← in_progress, redo
# done          → Chờ nghiệm thu / Đã xong ← pending_review, completed
KANBAN_TODO = 'todo'
KANBAN_IN_PROGRESS = 'in_progress'
KANBAN_DONE = 'done'
KANBAN_COLUMN_KEYS = (KANBAN_TODO, KANBAN_IN_PROGRESS, KANBAN_DONE)

KANBAN_STATUS_TO_COLUMN = {
    TaskAssignment.STATUS_TODO: KANBAN_TODO,
    TaskAssignment.STATUS_IN_PROGRESS: KANBAN_IN_PROGRESS,
    TaskAssignment.STATUS_REDO: KANBAN_IN_PROGRESS,
    TaskAssignment.STATUS_PENDING: KANBAN_DONE,
    TaskAssignment.STATUS_COMPLETED: KANBAN_DONE,
}

KANBAN_COLUMN_TO_STATUS = {
    KANBAN_TODO: TaskAssignment.STATUS_TODO,
    KANBAN_IN_PROGRESS: TaskAssignment.STATUS_IN_PROGRESS,
    KANBAN_DONE: TaskAssignment.STATUS_PENDING,  # kéo sang Done → Chờ nghiệm thu
}


def _kanban_column_for_status(status):
    return KANBAN_STATUS_TO_COLUMN.get(status, KANBAN_TODO)


def _user_can_update_task_status(user, task, department):
    """Trưởng tổ / Lãnh đạo / người được giao việc."""
    if not user or not user.is_authenticated:
        return False
    if user.is_director:
        return True
    if department.leader_id == user.id or (
        user.is_department and department.user_can_access(user)
    ):
        return True
    return any(a.assignee_id == user.id for a in task.assignments.all())


def _assignments_to_update_for_user(user, task, department):
    """Assignees cập nhật assignment của mình; lãnh đạo/trưởng tổ cập nhật tất cả."""
    assignments = list(task.assignments.all())
    if not assignments:
        return []
    if user.is_director or department.leader_id == user.id or (
        user.is_department and department.user_can_access(user)
    ):
        return assignments
    return [a for a in assignments if a.assignee_id == user.id]


@login_required
@require_POST
def update_task_status_api(request, dept_id):
    """
    POST JSON {task_id, status} — status là cột Kanban: todo | in_progress | done
    (hoặc giá trị TaskAssignment.status trực tiếp).
    """
    department = get_object_or_404(Department, pk=dept_id)
    if not department.user_can_access(request.user):
        return JsonResponse({'ok': False, 'error': 'Không có quyền truy cập tổ này.'}, status=403)

    try:
        payload = json.loads(request.body.decode('utf-8') or '{}')
    except (TypeError, ValueError, UnicodeDecodeError):
        payload = {}

    task_id = payload.get('task_id') or request.POST.get('task_id')
    raw_status = (payload.get('status') or request.POST.get('status') or '').strip()

    if not task_id or not raw_status:
        return JsonResponse({'ok': False, 'error': 'Thiếu task_id hoặc status.'}, status=400)

    if raw_status in KANBAN_COLUMN_TO_STATUS:
        new_status = KANBAN_COLUMN_TO_STATUS[raw_status]
        kanban_col = raw_status
    elif raw_status in dict(TaskAssignment.STATUS_CHOICES):
        new_status = raw_status
        kanban_col = _kanban_column_for_status(raw_status)
    else:
        return JsonResponse({'ok': False, 'error': 'Trạng thái không hợp lệ.'}, status=400)

    task = (
        _department_tasks_queryset(department)
        .filter(pk=task_id)
        .first()
    )
    if not task:
        return JsonResponse({'ok': False, 'error': 'Không tìm thấy nhiệm vụ.'}, status=404)

    if not _user_can_update_task_status(request.user, task, department):
        return JsonResponse(
            {'ok': False, 'error': 'Bạn không có quyền cập nhật trạng thái nhiệm vụ này.'},
            status=403,
        )

    targets = _assignments_to_update_for_user(request.user, task, department)
    if not targets:
        return JsonResponse({'ok': False, 'error': 'Không có phân công để cập nhật.'}, status=400)

    # Kéo sang Done: giữ completed nếu đã hoàn thành; còn lại → pending_review
    updated = 0
    for asg in targets:
        if kanban_col == KANBAN_DONE and asg.status == TaskAssignment.STATUS_COMPLETED:
            continue
        if asg.status == new_status:
            continue
        asg.status = new_status
        fields = ['status', 'updated_at']
        if new_status == TaskAssignment.STATUS_PENDING and not asg.submitted_at:
            asg.submitted_at = timezone.now()
            fields.append('submitted_at')
        asg.save(update_fields=fields)
        updated += 1

    task.refresh_from_db()
    # Bỏ cache prefetch để tính lại display status
    task._prefetched_objects_cache = {}
    display = _task_display_status(
        _department_tasks_queryset(department).filter(pk=task.pk).first() or task
    )
    return JsonResponse({
        'ok': True,
        'task_id': task.pk,
        'status': new_status,
        'kanban': _kanban_column_for_status(display),
        'display_status': display,
        'updated': updated,
    })


@login_required
@require_http_methods(['GET', 'POST'])
def department_interaction(request, dept_id):
    """Workspace nhiệm vụ nội bộ nhóm — chỉ thành viên / Trưởng tổ / Lãnh đạo."""
    department = get_object_or_404(
        Department.objects.select_related('leader').prefetch_related('members'),
        pk=dept_id,
    )
    if not department.user_can_access(request.user):
        raise PermissionDenied('Bạn không có quyền truy cập khu vực nhóm này.')

    can_assign = (
        request.user.is_director
        or request.user.is_department
        or getattr(request.user, 'role', None) == User.ROLE_DEPARTMENT
    )
    assign_form = DepartmentTaskAssignForm(
        department=department,
        user=request.user,
    )
    show_assign_modal = False

    if request.method == 'POST':
        if not can_assign:
            raise PermissionDenied('Chỉ Trưởng tổ / Lãnh đạo được giao việc nội bộ.')
        assign_form = DepartmentTaskAssignForm(
            request.POST,
            request.FILES or None,
            department=department,
            user=request.user,
        )
        if assign_form.is_valid():
            task = assign_form.save(commit=False)
            task.created_by = request.user
            task.primary_department = department
            task.save()
            for uploaded in assign_form.cleaned_data.get('attachments') or []:
                TaskAttachment.objects.create(
                    task=task,
                    file=uploaded,
                    original_name=uploaded.name,
                    file_size=uploaded.size,
                )
            assignees = assign_form.cleaned_data['assignees']
            count = 0
            for member in assignees:
                _, created = TaskAssignment.objects.get_or_create(
                    task=task,
                    assignee=member,
                )
                if created:
                    count += 1
            messages.success(
                request,
                f'Đã giao việc nội bộ "{task.title}" cho {count} thành viên tổ.',
            )
            return redirect('department_interaction', dept_id=department.pk)
        show_assign_modal = True
        messages.error(request, 'Không thể giao việc. Vui lòng kiểm tra lại biểu mẫu.')

    members_count = department.members.filter(is_active=True).count()
    today = timezone.localdate()
    tasks_qs = _department_tasks_queryset(department)
    all_tasks = list(tasks_qs)
    task_rows = _build_department_kanban_rows(
        all_tasks, request.user, department, today
    )

    stats = {
        'todo': 0,
        'in_progress': 0,
        'pending': 0,
        'overdue': 0,
    }
    kanban = {
        KANBAN_TODO: [],
        KANBAN_IN_PROGRESS: [],
        KANBAN_DONE: [],
    }
    can_drag_any = False
    for row in task_rows:
        col = row['kanban_column']
        st = row['task'].display_status
        if col == KANBAN_TODO:
            stats['todo'] += 1
        elif col == KANBAN_IN_PROGRESS:
            stats['in_progress'] += 1
        elif st == TaskAssignment.STATUS_PENDING:
            stats['pending'] += 1
        if row['task'].row_is_overdue:
            stats['overdue'] += 1
        if row.get('can_drag'):
            can_drag_any = True
        kanban[col].append(row)

    member_options = []
    for u in assign_form.fields['assignees'].queryset.prefetch_related('my_departments'):
        member_options.append({
            'id': u.id,
            'name': u.get_full_name() or u.username,
            'username': u.username,
            'avatar': u.avatar_url,
            'department': department.name,
        })

    return render(
        request,
        'accounts/department_detail.html',
        {
            'department': department,
            'members_count': members_count,
            'stats': stats,
            'task_rows': task_rows,
            'kanban': kanban,
            'kanban_todo': kanban[KANBAN_TODO],
            'kanban_in_progress': kanban[KANBAN_IN_PROGRESS],
            'kanban_done': kanban[KANBAN_DONE],
            'can_assign': can_assign,
            'can_drag_any': can_drag_any,
            'status_update_url': reverse('update_task_status_api', args=[department.pk]),
            'assign_form': assign_form,
            'show_assign_modal': show_assign_modal,
            'member_options_json': json.dumps(member_options, ensure_ascii=False),
            'selected_assignee_ids': json.dumps(
                [str(x) for x in (
                    request.POST.getlist('assignees') if request.method == 'POST' else []
                )]
            ),
            'max_attachment_mb': TaskAttachment.MAX_SIZE_MB,
            'max_attachment_count': TaskAttachment.MAX_COUNT,
        },
    )
