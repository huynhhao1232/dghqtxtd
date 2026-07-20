from django.contrib import messages
from django.contrib.auth import login as auth_login
from django.contrib.auth import logout as auth_logout
from django.contrib.auth import update_session_auth_hash
from django.contrib.auth.decorators import login_required
from django.core.exceptions import PermissionDenied
from django.db.models import Count, Prefetch, Q
from django.http import JsonResponse
from django.shortcuts import get_object_or_404, redirect, render
from django.views.decorators.http import require_http_methods, require_POST
import json

from .decorators import manager_required, redirect_by_role
from .forms import (
    DepartmentForm,
    GroupPostForm,
    LoginForm,
    ProfileUpdateForm,
    StaffCreateForm,
    StaffEditForm,
    StyledPasswordChangeForm,
)
from .models import Department, GroupPost, User
from .vietnamese import sort_users_by_vietnamese_name, user_display_full_name
from tasks.models import Task, TaskAssignment


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
    return redirect_by_role(request.user)


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
    edit_form = None
    edit_user = None
    open_create = request.GET.get('action') == 'create'
    action = request.POST.get('action')
    staff_id = request.POST.get('staff_id') or request.GET.get('staff_id')

    if request.method == 'GET' and request.GET.get('action') == 'edit' and staff_id:
        edit_user = get_object_or_404(User, pk=staff_id)
        edit_form = StaffEditForm(instance=edit_user)

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
            edit_form = StaffEditForm(request.POST, instance=edit_user)
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

    return render(
        request,
        'manager/staff_list.html',
        {
            'staff_list': staff_list,
            'departments': Department.objects.all(),
            'dept_filter': dept_filter,
            'q': q,
            'create_form': create_form,
            'edit_form': edit_form,
            'edit_user': edit_user,
            'open_create': open_create,
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


# ───────────────────────── Department interaction space ─────────────────────────


@login_required
@require_http_methods(['GET', 'POST'])
def department_interaction(request, dept_id):
    """Khu vực tương tác nhóm — chỉ thành viên / Trưởng tổ / Lãnh đạo."""
    department = get_object_or_404(
        Department.objects.select_related('leader').prefetch_related('members'),
        pk=dept_id,
    )
    if not department.user_can_access(request.user):
        raise PermissionDenied('Bạn không có quyền truy cập khu vực nhóm này.')

    post_form = GroupPostForm()
    if request.method == 'POST':
        post_form = GroupPostForm(request.POST)
        if post_form.is_valid():
            post = post_form.save(commit=False)
            post.department = department
            post.author = request.user
            post.save()
            messages.success(request, 'Đã đăng tin lên bảng tin nhóm.')
            return redirect('department_interaction', dept_id=department.pk)

    posts = (
        GroupPost.objects.filter(department=department)
        .select_related('author')
        .order_by('-created_at')[:50]
    )
    members = list(
        department.members.filter(is_active=True).order_by('last_name', 'first_name')
    )
    # Đưa Trưởng tổ lên đầu danh sách
    if department.leader_id:
        members.sort(key=lambda u: (0 if u.pk == department.leader_id else 1, str(u)))

    recent_tasks = (
        Task.objects.filter(
            Q(primary_department=department) | Q(coordinating_departments=department)
        )
        .select_related('primary_department', 'primary_department__leader')
        .prefetch_related('assignments', 'coordinating_departments')
        .distinct()
        .order_by('-created_at')[:5]
    )
    task_cards = []
    for task in recent_tasks:
        canonical = task.get_canonical_assignment()
        status = canonical.status if canonical else TaskAssignment.STATUS_TODO
        status_label = dict(TaskAssignment.STATUS_CHOICES).get(status, status)
        task_cards.append({
            'task': task,
            'status': status,
            'status_label': status_label,
        })

    return render(
        request,
        'accounts/department_interaction.html',
        {
            'department': department,
            'posts': posts,
            'post_form': post_form,
            'members': members,
            'task_cards': task_cards,
        },
    )
