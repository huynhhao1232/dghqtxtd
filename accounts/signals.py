from django.db.models.signals import m2m_changed
from django.dispatch import receiver

from .models import Department


@receiver(m2m_changed, sender=Department.members.through)
def on_department_members_changed(sender, instance, action, pk_set, **kwargs):
    """
    Khi gỡ thành viên khỏi Tổ/Nhóm → đánh dấu bàn giao việc chưa xong + báo Trưởng tổ.
    """
    if action != 'post_remove' or not pk_set:
        return
    from accounts.models import User
    from tasks.handover import handle_department_member_removed

    department = instance
    users = User.objects.filter(pk__in=pk_set)
    for user in users:
        handle_department_member_removed(department, user)
