import re
import uuid

from django.db import migrations, models
import django.db.models.deletion


MEMBER_TITLE_SUFFIX_RE = re.compile(r'^(.+?) - \[.+\]$')


def retrofit_batch_keys(apps, schema_editor):
    """Gom các task cũ dạng 'Tiêu đề - [Tên]' cùng lúc tạo thành một batch_key."""
    Task = apps.get_model('tasks', 'Task')
    TaskAssignment = apps.get_model('tasks', 'TaskAssignment')

    groups = {}
    qs = (
        Task.objects.filter(parent_task__isnull=True, batch_key__isnull=True)
        .order_by('created_by_id', 'created_at', 'pk')
    )
    for task in qs.iterator():
        match = MEMBER_TITLE_SUFFIX_RE.match((task.title or '').strip())
        if not match:
            continue
        base = match.group(1).strip()
        if not base:
            continue
        # Gom theo người giao + tiêu đề gốc + cửa sổ ~10 giây
        bucket = int(task.created_at.timestamp() // 10) if task.created_at else 0
        key = (task.created_by_id, base, bucket)
        groups.setdefault(key, []).append(task)

    for (_created_by, _base, _bucket), tasks in groups.items():
        if len(tasks) < 2:
            continue
        batch_key = uuid.uuid4()
        for task in tasks:
            source_dept_id = None
            assignment = (
                TaskAssignment.objects.filter(task_id=task.pk, assignee_id__isnull=False)
                .select_related('assignee')
                .first()
            )
            if assignment and assignment.assignee_id:
                Department = apps.get_model('accounts', 'Department')
                source_dept_id = (
                    Department.objects.filter(members__pk=assignment.assignee_id)
                    .order_by('name')
                    .values_list('pk', flat=True)
                    .first()
                )
            Task.objects.filter(pk=task.pk).update(
                batch_key=batch_key,
                source_department_id=source_dept_id,
            )


def noop_reverse(apps, schema_editor):
    pass


class Migration(migrations.Migration):

    dependencies = [
        ('accounts', '0007_user_role'),
        ('tasks', '0009_quantitative_evaluation'),
    ]

    operations = [
        migrations.AddField(
            model_name='task',
            name='batch_key',
            field=models.UUIDField(
                blank=True,
                db_index=True,
                help_text=(
                    'Các task cá nhân cùng một lần “Mỗi thành viên tự thực hiện” '
                    'chia sẻ cùng batch_key để hiển thị dạng cây.'
                ),
                null=True,
                verbose_name='Nhóm giao đồng loạt',
            ),
        ),
        migrations.AddField(
            model_name='task',
            name='source_department',
            field=models.ForeignKey(
                blank=True,
                help_text='Tổ/Nhóm mà thành viên được giao trong lần giao đồng loạt.',
                null=True,
                on_delete=django.db.models.deletion.SET_NULL,
                related_name='batch_member_tasks',
                to='accounts.department',
                verbose_name='Tổ nguồn (giao đồng loạt)',
            ),
        ),
        migrations.RunPython(retrofit_batch_keys, noop_reverse),
    ]
