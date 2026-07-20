from functools import wraps

from django.contrib.auth.decorators import login_required
from django.core.exceptions import PermissionDenied
from django.shortcuts import redirect


def redirect_by_role(user):
    """Điều hướng sau đăng nhập theo Role."""
    if user.is_director or user.is_manager:
        return redirect('manager_dashboard')
    return redirect('staff_dashboard')


def manager_required(view_func):
    """Chặn tuyệt đối Nhân viên truy cập URL dành cho Lãnh đạo (403)."""

    @wraps(view_func)
    @login_required
    def _wrapped(request, *args, **kwargs):
        if not (request.user.is_director or request.user.is_manager):
            raise PermissionDenied('Bạn không có quyền truy cập khu vực Lãnh đạo.')
        return view_func(request, *args, **kwargs)

    return _wrapped


def can_assign_required(view_func):
    """Cho phép Ban Giám đốc hoặc Tổ chuyên môn giao việc; staff → 403."""

    @wraps(view_func)
    @login_required
    def _wrapped(request, *args, **kwargs):
        if not request.user.can_assign_tasks():
            raise PermissionDenied('Bạn không có quyền giao việc.')
        return view_func(request, *args, **kwargs)

    return _wrapped


def assigner_required(view_func):
    """
    Ban Giám đốc hoặc Tổ chuyên môn — danh sách / chi tiết việc đã giao.
    Staff không có quyền (403). Phạm vi dữ liệu lọc thêm trong view.
    """

    @wraps(view_func)
    @login_required
    def _wrapped(request, *args, **kwargs):
        user = request.user
        if not (
            getattr(user, 'is_director', False)
            or getattr(user, 'is_manager', False)
            or getattr(user, 'is_department', False)
            or user.can_assign_tasks()
        ):
            raise PermissionDenied('Bạn không có quyền xem công việc đã giao.')
        return view_func(request, *args, **kwargs)

    return _wrapped


def staff_required(view_func):
    """Chỉ Nhân viên (không phải Lãnh đạo) hoặc cho phép cả hai xem khu vực cá nhân."""

    @wraps(view_func)
    @login_required
    def _wrapped(request, *args, **kwargs):
        if request.user.is_manager:
            # Lãnh đạo nên dùng portal quản lý; vẫn cho phép xem nếu cần
            # Theo yêu cầu: Nhân viên → Staff Dashboard
            pass
        return view_func(request, *args, **kwargs)

    return _wrapped
