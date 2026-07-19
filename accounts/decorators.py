from functools import wraps

from django.contrib.auth.decorators import login_required
from django.core.exceptions import PermissionDenied
from django.shortcuts import redirect


def redirect_by_role(user):
    """Điều hướng sau đăng nhập theo Role."""
    if user.is_manager:
        return redirect('manager_dashboard')
    return redirect('staff_dashboard')


def manager_required(view_func):
    """Chặn tuyệt đối Nhân viên truy cập URL dành cho Lãnh đạo (403)."""

    @wraps(view_func)
    @login_required
    def _wrapped(request, *args, **kwargs):
        if not request.user.is_manager:
            raise PermissionDenied('Bạn không có quyền truy cập khu vực Lãnh đạo.')
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
