"""Bàn giao công việc & xử lý khi viên chức chuyển tổ."""

from django.db import transaction
from django.db.models import Q

from accounts.models import User

from .models import Notification, TaskAssignment, TaskParticipation


def _user_display(user):
    return str(user).strip() or user.username


def incomplete_assignment_q():
    return ~Q(status=TaskAssignment.STATUS_COMPLETED)


def can_handover_assignment(actor, assignment):
    """
    Được bàn giao nếu:
    - chính assignee (cá nhân), hoặc
    - Trưởng tổ Chủ trì / Phối hợp / batch của task, hoặc
    - Ban Giám đốc.
    """
    if not actor or not getattr(actor, 'is_authenticated', False):
        return False
    if assignment.handover_status != TaskAssignment.HANDOVER_ACTIVE:
        return False
    if assignment.status == TaskAssignment.STATUS_COMPLETED:
        return False
    if not assignment.assignee_id:
        # Bản giao theo tổ: chỉ trưởng tổ / BGĐ bàn giao đại diện
        if getattr(actor, 'is_director', False):
            return True
        dept = assignment.assignee_department
        return bool(dept and dept.leader_id == actor.id)
    if assignment.assignee_id == actor.id:
        return True
    if getattr(actor, 'is_director', False):
        return True
    task = assignment.task
    if task.is_department_leader(actor):
        return True
    if task.get_batch_department_for(actor) is not None:
        return True
    # Trưởng tổ của tổ nguồn / tổ sở hữu assignment
    source = task.source_department or task.scope_department or task.primary_department
    if source and source.leader_id == actor.id:
        return True
    return False


def handover_candidates_for_assignment(assignment):
    """Thành viên cùng tổ (ACTIVE) có thể nhận bàn giao — loại người đang phụ trách."""
    task = assignment.task
    dept = None
    if assignment.assignee_department_id:
        dept = assignment.assignee_department
    elif task.source_department_id:
        dept = task.source_department
    elif task.scope_department_id:
        dept = task.scope_department
    elif task.primary_department_id:
        dept = task.primary_department
    elif assignment.assignee_id:
        dept = assignment.assignee.primary_department

    if dept is None:
        return User.objects.none()

    qs = dept.assignable_members()
    if assignment.assignee_id:
        qs = qs.exclude(pk=assignment.assignee_id)
    # Không chọn người đã có assignment ACTIVE trên cùng task
    existing_ids = task.assignments.filter(
        handover_status=TaskAssignment.HANDOVER_ACTIVE,
        assignee_id__isnull=False,
    ).values_list('assignee_id', flat=True)
    return qs.exclude(pk__in=existing_ids).select_related().prefetch_related('my_departments')


@transaction.atomic
def hand_over_assignment(assignment, to_user, *, actor=None, notify=True):
    """
    Đánh dấu assignment cũ HANDED_OVER và tạo assignment ACTIVE cho người mới.
    Đồng bộ participation nếu có.
    """
    if assignment.handover_status != TaskAssignment.HANDOVER_ACTIVE:
        raise ValueError('Bản phân công này đã được bàn giao.')
    if assignment.status == TaskAssignment.STATUS_COMPLETED:
        raise ValueError('Công việc đã hoàn thành, không thể bàn giao.')
    if not to_user or not to_user.is_bulk_assignable:
        raise ValueError('Người nhận bàn giao không hợp lệ (nghỉ phép hoặc đã khóa).')
    if assignment.assignee_id and assignment.assignee_id == to_user.id:
        raise ValueError('Không thể bàn giao cho chính người đang phụ trách.')

    task = assignment.task
    existing = task.assignments.filter(
        assignee=to_user,
        handover_status=TaskAssignment.HANDOVER_ACTIVE,
    ).first()
    if existing:
        raise ValueError('Người này đã có bản phân công đang hoạt động trên công việc.')

    from_user = assignment.assignee
    assignment.handover_status = TaskAssignment.HANDOVER_HANDED_OVER
    assignment.handed_over_to = to_user
    assignment.save(update_fields=['handover_status', 'handed_over_to', 'updated_at'])

    new_assignment = TaskAssignment.objects.create(
        task=task,
        assignee=to_user,
        status=TaskAssignment.STATUS_TODO,
        handover_status=TaskAssignment.HANDOVER_ACTIVE,
        notes=assignment.notes,
    )

    # Participation: bàn giao bản ghi ACTIVE của from_user (nếu có)
    if from_user:
        parts = list(
            task.participations.filter(
                user=from_user,
                handover_status=TaskParticipation.HANDOVER_ACTIVE,
            )
        )
        for part in parts:
            part.handover_status = TaskParticipation.HANDOVER_HANDED_OVER
            part.handed_over_to = to_user
            part.save(update_fields=['handover_status', 'handed_over_to'])
            existing_part = task.participations.filter(user=to_user).first()
            if existing_part:
                if existing_part.handover_status != TaskParticipation.HANDOVER_ACTIVE:
                    existing_part.handover_status = TaskParticipation.HANDOVER_ACTIVE
                    existing_part.handed_over_to = None
                    existing_part.department = part.department or existing_part.department
                    existing_part.role = part.role
                    existing_part.save(
                        update_fields=[
                            'handover_status',
                            'handed_over_to',
                            'department',
                            'role',
                        ]
                    )
            else:
                TaskParticipation.objects.create(
                    task=task,
                    user=to_user,
                    department=part.department,
                    role=part.role,
                    handover_status=TaskParticipation.HANDOVER_ACTIVE,
                )

    if notify:
        from_name = _user_display(from_user) if from_user else assignment.target_display_name
        to_name = _user_display(to_user)
        Notification.objects.create(
            recipient=to_user,
            message=(
                f'Bạn được bàn giao công việc "{task.title}" '
                f'từ {from_name}.'
            ),
            related_task=task,
        )
        if from_user and from_user.id != getattr(actor, 'id', None):
            Notification.objects.create(
                recipient=from_user,
                message=(
                    f'Công việc "{task.title}" đã được bàn giao cho {to_name}.'
                ),
                related_task=task,
            )

    return new_assignment


def related_incomplete_assignments_for_dept_transfer(user, department):
    """Assignment chưa hoàn thành của user liên quan tới tổ vừa rời."""
    return (
        TaskAssignment.objects.filter(
            assignee=user,
            handover_status=TaskAssignment.HANDOVER_ACTIVE,
        )
        .filter(incomplete_assignment_q())
        .filter(
            Q(task__source_department=department)
            | Q(task__primary_department=department)
            | Q(task__scope_department=department)
            | Q(
                task__participations__user=user,
                task__participations__department=department,
                task__participations__handover_status=TaskParticipation.HANDOVER_ACTIVE,
            )
        )
        .select_related('task', 'assignee')
        .distinct()
    )


def related_incomplete_participations_for_dept_transfer(user, department):
    """Participation ACTIVE trong tổ — chỉ giữ những task chưa hoàn thành hiệu lực."""
    parts = list(
        TaskParticipation.objects.filter(
            user=user,
            department=department,
            handover_status=TaskParticipation.HANDOVER_ACTIVE,
        ).select_related('task', 'user', 'department')
    )
    return [p for p in parts if not p.task.is_effectively_completed()]


@transaction.atomic
def handle_department_member_removed(department, user):
    """
    Khi viên chức bị gỡ khỏi Tổ/Nhóm:
    - Đánh dấu HANDED_OVER các assignment/participation chưa xong liên quan tổ
    - Thông báo Trưởng tổ để phân công lại
    """
    if not department or not user:
        return 0

    assignments = list(related_incomplete_assignments_for_dept_transfer(user, department))
    assignment_task_ids = {a.task_id for a in assignments}
    participations = [
        p
        for p in related_incomplete_participations_for_dept_transfer(user, department)
        if p.handover_status == TaskParticipation.HANDOVER_ACTIVE
    ]

    notified_tasks = set()
    count = 0
    name = _user_display(user)

    for asg in assignments:
        asg.handover_status = TaskAssignment.HANDOVER_HANDED_OVER
        asg.handed_over_to = None
        asg.save(update_fields=['handover_status', 'handed_over_to', 'updated_at'])
        count += 1
        # Đồng bộ participation cùng task+user trong tổ
        TaskParticipation.objects.filter(
            task_id=asg.task_id,
            user=user,
            department=department,
            handover_status=TaskParticipation.HANDOVER_ACTIVE,
        ).update(
            handover_status=TaskParticipation.HANDOVER_HANDED_OVER,
            handed_over_to=None,
        )
        if asg.task_id not in notified_tasks:
            notified_tasks.add(asg.task_id)
            _notify_leader_reassign(department, user, asg.task, name)

    for part in participations:
        if part.task_id in assignment_task_ids:
            continue  # đã xử lý cùng assignment
        part.handover_status = TaskParticipation.HANDOVER_HANDED_OVER
        part.handed_over_to = None
        part.save(update_fields=['handover_status', 'handed_over_to'])
        count += 1
        if part.task_id not in notified_tasks:
            notified_tasks.add(part.task_id)
            _notify_leader_reassign(department, user, part.task, name)

    return count


def _notify_leader_reassign(department, user, task, name=None):
    leader = department.leader
    if not leader:
        return
    name = name or _user_display(user)
    message = (
        f'Viên chức {name} đã chuyển tổ. '
        f'Vui lòng phân công lại công việc {task.title}'
    )
    # Tránh spam cùng nội dung chưa đọc
    if Notification.objects.filter(
        recipient=leader,
        related_task=task,
        message=message,
        is_read=False,
    ).exists():
        return
    Notification.objects.create(
        recipient=leader,
        message=message,
        related_task=task,
    )
