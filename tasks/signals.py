from django.db.models.signals import post_save, pre_save
from django.dispatch import receiver

from .models import Notification, TaskAssignment


def _assignment_recipient(instance):
    """Người nhận thông báo cho bản phân công (cá nhân hoặc Trưởng tổ đại diện)."""
    return instance.acting_user


@receiver(post_save, sender=TaskAssignment)
def notify_on_assignment_created(sender, instance, created, **kwargs):
    """Khi được phân công / thêm vào task tổ → thông báo Assignee / Trưởng tổ."""
    if not created:
        return
    recipient = _assignment_recipient(instance)
    if not recipient:
        return

    task = instance.task
    if task.is_subtask and task.parent_task_id:
        message = (
            f'Bạn được giao nhiệm vụ con: {task.title} '
            f'(Từ: {task.parent_task.title})'
        )
    elif instance.is_department_target or getattr(instance, '_batch_department_assignment', False):
        message = f'Lãnh đạo đã giao cho tổ của bạn công việc: {task.title}'
    elif instance.assignee_id and task.is_team_task and task.is_primary_leader(instance.assignee):
        coords = ', '.join(d.name for d in task.coordinating_departments.all()) or 'không'
        message = (
            f'Tổ {task.primary_department.name} được giao làm Chủ trì việc: {task.title}. '
            f'Phối hợp: {coords}. Hãy phân công thành viên và theo dõi minh chứng phối hợp.'
        )
    elif instance.assignee_id and task.is_team_task and task.is_coordinating_leader(instance.assignee):
        dept = task.get_coordinating_department_for(instance.assignee)
        message = (
            f'Tổ {dept.name if dept else ""} được giao Phối hợp việc: {task.title} '
            f'(Chủ trì: {task.primary_department.name}). Hãy thêm thành viên và nộp minh chứng.'
        )
    elif task.is_team_task:
        message = f'Bạn được Trưởng tổ thêm vào công việc nhóm: {task.title}'
    else:
        message = f'Bạn được phân công công việc mới: {task.title}'

    Notification.objects.create(
        recipient=recipient,
        message=message,
        related_task=task,
    )


@receiver(pre_save, sender=TaskAssignment)
def cache_previous_status(sender, instance, **kwargs):
    """Lưu trạng thái cũ để so sánh khi cập nhật."""
    if not instance.pk:
        instance._previous_status = None
        return
    try:
        old = TaskAssignment.objects.get(pk=instance.pk)
        instance._previous_status = old.status
    except TaskAssignment.DoesNotExist:
        instance._previous_status = None


def _notify_parent_leader_all_subtasks_done(parent, scope_department=None):
    """
    Khi mọi sub-task của parent đã hoàn thành → nhắc Trưởng tổ chốt nhiệm vụ gốc.
    Idempotent trong ngày (tránh spam cùng nội dung).
    """
    if not parent or not parent.all_subtasks_completed(scope_department):
        return
    if scope_department is not None:
        leader_id = scope_department.leader_id
    else:
        leader_id = (
            parent.primary_department.leader_id
            if parent.primary_department_id
            else None
        )
    if not leader_id:
        return
    scope_label = (
        f' của tổ {scope_department.name}'
        if scope_department is not None
        else ''
    )
    message = (
        f'Các công việc thành phần{scope_label} của "{parent.title}" đã xong, '
        f'vui lòng chốt tiến độ nhiệm vụ gốc!'
    )
    exists = Notification.objects.filter(
        recipient_id=leader_id,
        related_task=parent,
        message=message,
        is_read=False,
    ).exists()
    if exists:
        return
    Notification.objects.create(
        recipient_id=leader_id,
        message=message,
        related_task=parent,
    )


def _notify_assignment_actors(task, message):
    """Gửi thông báo tới mọi đối tượng nhận việc (user hoặc Trưởng tổ đại diện)."""
    seen = set()
    for assignment in task.assignments.select_related(
        'assignee',
        'assignee_department__leader',
    ):
        user = assignment.acting_user
        if not user or user.pk in seen:
            continue
        seen.add(user.pk)
        Notification.objects.create(
            recipient=user,
            message=message,
            related_task=task,
        )


@receiver(post_save, sender=TaskAssignment)
def notify_on_status_change(sender, instance, created, **kwargs):
    if created:
        return

    previous = getattr(instance, '_previous_status', None)
    if previous == instance.status:
        return

    task = instance.task
    actor = _assignment_recipient(instance)
    who = instance.target_display_name
    if actor and not instance.is_department_target:
        who = actor.get_full_name() or actor.username

    if instance.status == TaskAssignment.STATUS_PENDING:
        if task.is_subtask and task.parent_task_id:
            parent = task.parent_task
            leader_id = (
                parent.primary_department.leader_id
                if parent.primary_department_id
                else None
            )
            if leader_id:
                Notification.objects.create(
                    recipient_id=leader_id,
                    message=(
                        f'{who} đã nộp minh chứng nhiệm vụ con "{task.title}" '
                        f'(Từ: {parent.title}) — vui lòng nghiệm thu.'
                    ),
                    related_task=task,
                )
        else:
            manager = task.created_by
            if task.is_team_task and task.primary_department:
                who = f'Tổ chủ trì {task.primary_department.name} ({who})'
            elif instance.is_department_target and instance.assignee_department_id:
                who = f'Tổ {instance.assignee_department.name}'
            Notification.objects.create(
                recipient=manager,
                message=f'{who} đã nộp minh chứng cho việc: {task.title}',
                related_task=task,
            )

    elif instance.status in (TaskAssignment.STATUS_COMPLETED, TaskAssignment.STATUS_REDO):
        from .models import EvaluationResult

        result = instance.evaluation_result
        if result == EvaluationResult.DAT:
            result_text = 'Đạt'
        elif result == EvaluationResult.TRE_BI_TRU_DIEM:
            result_text = 'Yêu cầu làm lại và trừ 1'
        elif result == EvaluationResult.CHO_LAM_LAI:
            result_text = 'Yêu cầu làm lại'
        else:
            result_text = (
                'nghiệm thu'
                if instance.status == TaskAssignment.STATUS_COMPLETED
                else 'yêu cầu làm lại'
            )

        if task.is_subtask:
            if actor:
                Notification.objects.create(
                    recipient=actor,
                    message=(
                        f'Trưởng tổ đã đánh giá nhiệm vụ con "{task.title}": '
                        f'{result_text}.'
                    ),
                    related_task=task,
                )
            if instance.status == TaskAssignment.STATUS_COMPLETED and task.parent_task_id:
                _notify_parent_leader_all_subtasks_done(
                    task.parent_task,
                    task.scope_department,
                )
        else:
            message = f'Lãnh đạo đã đánh giá công việc "{task.title}": {result_text}.'
            if task.is_team_task:
                # Classic Chủ trì–Phối hợp: mọi trưởng tổ cùng nhận kết quả nghiệm thu.
                _notify_assignment_actors(task, message)
            elif actor:
                # Giao đồng loạt / cá nhân: chỉ thông báo đúng bản phân công vừa chấm.
                Notification.objects.create(
                    recipient=actor,
                    message=message,
                    related_task=task,
                )
