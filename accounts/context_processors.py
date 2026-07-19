from accounts.models import Department


def notifications(request):
    """Đưa badge + danh sách thông báo gần nhất vào mọi template kế thừa base.html."""
    from tasks.models import Notification

    if not request.user.is_authenticated:
        return {
            'unread_notification_count': 0,
            'recent_notifications': [],
            'sidebar_departments': [],
        }

    qs = Notification.objects.filter(recipient=request.user)
    return {
        'unread_notification_count': qs.filter(is_read=False).count(),
        'recent_notifications': qs.select_related('related_task')[:8],
        'sidebar_departments': _sidebar_departments(request.user),
    }


def _sidebar_departments(user):
    """
    Nhóm hiện trên Sidebar: tổ user là thành viên + tổ user làm Trưởng tổ.
    (Không cần truyền từ từng view.)
    """
    member_ids = set(user.my_departments.values_list('pk', flat=True))
    led_ids = set(user.led_departments.values_list('pk', flat=True))
    ids = member_ids | led_ids
    if not ids:
        return []
    return list(Department.objects.filter(pk__in=ids).order_by('name'))
