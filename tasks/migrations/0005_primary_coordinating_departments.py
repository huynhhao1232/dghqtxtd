import django.db.models.deletion
from django.conf import settings
from django.db import migrations, models


def migrate_assigned_to_primary(apps, schema_editor):
    Task = apps.get_model('tasks', 'Task')
    TaskParticipation = apps.get_model('tasks', 'TaskParticipation')
    for task in Task.objects.exclude(assigned_department_id=None).iterator():
        task.primary_department_id = task.assigned_department_id
        task.save(update_fields=['primary_department_id'])
    for p in TaskParticipation.objects.filter(department_id=None).select_related('task').iterator():
        if p.task.primary_department_id:
            p.department_id = p.task.primary_department_id
            p.role = 'lead_member'
            p.save(update_fields=['department_id', 'role'])


def noop_reverse(apps, schema_editor):
    pass


class Migration(migrations.Migration):

    dependencies = [
        ('accounts', '0003_remove_user_department'),
        ('tasks', '0004_team_task_participation'),
        migrations.swappable_dependency(settings.AUTH_USER_MODEL),
    ]

    operations = [
        migrations.AddField(
            model_name='task',
            name='primary_department',
            field=models.ForeignKey(
                blank=True,
                help_text='Tổ/Nhóm chủ trì — chịu trách nhiệm chốt tiến độ gửi Lãnh đạo.',
                null=True,
                on_delete=django.db.models.deletion.SET_NULL,
                related_name='primary_tasks',
                to='accounts.department',
                verbose_name='Đơn vị Chủ trì',
            ),
        ),
        migrations.AddField(
            model_name='task',
            name='coordinating_departments',
            field=models.ManyToManyField(
                blank=True,
                related_name='coordinating_tasks',
                to='accounts.department',
                verbose_name='Đơn vị Phối hợp',
            ),
        ),
        migrations.AddField(
            model_name='taskparticipation',
            name='department',
            field=models.ForeignKey(
                blank=True,
                null=True,
                on_delete=django.db.models.deletion.CASCADE,
                related_name='task_participations',
                to='accounts.department',
                verbose_name='Thuộc tổ',
            ),
        ),
        migrations.AddField(
            model_name='taskparticipation',
            name='role',
            field=models.CharField(
                choices=[
                    ('lead_member', 'Thành viên tổ chủ trì'),
                    ('coord_member', 'Thành viên tổ phối hợp'),
                ],
                default='lead_member',
                max_length=20,
                verbose_name='Vai trò tham gia',
            ),
        ),
        migrations.CreateModel(
            name='CoordinatingProof',
            fields=[
                ('id', models.BigAutoField(auto_created=True, primary_key=True, serialize=False, verbose_name='ID')),
                ('proof_file', models.FileField(upload_to='coord_proofs/%Y/%m/', verbose_name='File minh chứng')),
                ('notes', models.TextField(blank=True, verbose_name='Ghi chú')),
                ('submitted_at', models.DateTimeField(auto_now=True)),
                ('department', models.ForeignKey(
                    on_delete=django.db.models.deletion.CASCADE,
                    related_name='coordinating_proofs',
                    to='accounts.department',
                    verbose_name='Tổ phối hợp',
                )),
                ('task', models.ForeignKey(
                    on_delete=django.db.models.deletion.CASCADE,
                    related_name='coordinating_proofs',
                    to='tasks.task',
                    verbose_name='Công việc',
                )),
                ('uploaded_by', models.ForeignKey(
                    on_delete=django.db.models.deletion.CASCADE,
                    related_name='uploaded_coordinating_proofs',
                    to=settings.AUTH_USER_MODEL,
                    verbose_name='Người nộp',
                )),
            ],
            options={
                'verbose_name': 'Minh chứng phối hợp',
                'verbose_name_plural': 'Minh chứng phối hợp',
                'ordering': ['-submitted_at'],
                'unique_together': {('task', 'department')},
            },
        ),
        migrations.RunPython(migrate_assigned_to_primary, noop_reverse),
        migrations.RemoveField(
            model_name='task',
            name='assigned_department',
        ),
        migrations.AlterModelOptions(
            name='taskparticipation',
            options={
                'ordering': ['role', 'added_at'],
                'verbose_name': 'Thành viên tham gia',
                'verbose_name_plural': 'Thành viên tham gia',
            },
        ),
    ]
