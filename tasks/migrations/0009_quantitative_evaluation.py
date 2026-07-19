from decimal import Decimal

import django.db.models.deletion
from django.db import migrations, models


def forwards_map_evaluation(apps, schema_editor):
    TaskAssignment = apps.get_model('tasks', 'TaskAssignment')
    TaskParticipation = apps.get_model('tasks', 'TaskParticipation')

    for assignment in TaskAssignment.objects.all().iterator():
        coeff = assignment.coefficient
        if coeff is None:
            assignment.evaluation_result = None
            assignment.penalty_score = 0
            assignment.save(update_fields=['evaluation_result', 'penalty_score'])
            continue

        coeff = Decimal(str(coeff))
        if coeff == Decimal('1.00'):
            assignment.evaluation_result = 'DAT'
            assignment.penalty_score = 0
            assignment.status = 'completed'
        elif coeff == Decimal('0.70'):
            assignment.evaluation_result = 'TRE_BI_TRU_DIEM'
            assignment.penalty_score = 1
            assignment.status = 'redo'
        else:
            assignment.evaluation_result = 'CHO_LAM_LAI'
            assignment.penalty_score = 0
            assignment.status = 'redo'
        assignment.save(
            update_fields=[
                'evaluation_result',
                'penalty_score',
                'status',
            ]
        )

    for part in TaskParticipation.objects.all().iterator():
        if part.evaluation == 'pass':
            part.evaluation = 'DAT'
            part.penalty_score = 0
        elif part.evaluation == 'fail':
            part.evaluation = 'CHO_LAM_LAI'
            part.penalty_score = 0
        else:
            part.evaluation = 'none'
            part.penalty_score = 0
        part.save(update_fields=['evaluation', 'penalty_score'])


def backwards_map_evaluation(apps, schema_editor):
    TaskAssignment = apps.get_model('tasks', 'TaskAssignment')
    TaskParticipation = apps.get_model('tasks', 'TaskParticipation')
    Task = apps.get_model('tasks', 'Task')

    for assignment in TaskAssignment.objects.select_related('task').iterator():
        result = assignment.evaluation_result
        base = Decimal(str(getattr(assignment.task, 'base_score', Decimal('10.00')) or Decimal('10.00')))
        if result == 'DAT':
            assignment.coefficient = Decimal('1.00')
            assignment.final_score = base
            assignment.status = 'completed'
        elif result == 'TRE_BI_TRU_DIEM':
            assignment.coefficient = Decimal('0.70')
            assignment.final_score = (base * Decimal('0.70')).quantize(Decimal('0.01'))
            assignment.status = 'completed'
        elif result == 'CHO_LAM_LAI':
            assignment.coefficient = Decimal('0.00')
            assignment.final_score = Decimal('0.00')
            assignment.status = 'redo'
        else:
            assignment.coefficient = None
            assignment.final_score = None
        assignment.save(update_fields=['coefficient', 'final_score', 'status'])

    for part in TaskParticipation.objects.all().iterator():
        if part.evaluation == 'DAT':
            part.evaluation = 'pass'
        elif part.evaluation in ('CHO_LAM_LAI', 'TRE_BI_TRU_DIEM'):
            part.evaluation = 'fail'
        else:
            part.evaluation = 'none'
        part.save(update_fields=['evaluation'])


class Migration(migrations.Migration):

    dependencies = [
        ('tasks', '0008_batch_subtask_scope'),
    ]

    operations = [
        migrations.AddField(
            model_name='taskassignment',
            name='evaluation_result',
            field=models.CharField(
                blank=True,
                choices=[
                    ('DAT', 'Đạt'),
                    ('CHO_LAM_LAI', 'Chưa đạt - Yêu cầu làm lại'),
                    ('TRE_BI_TRU_DIEM', 'Chưa đạt - Yêu cầu làm lại và bị -1'),
                ],
                max_length=20,
                null=True,
                verbose_name='Kết quả đánh giá',
            ),
        ),
        migrations.AddField(
            model_name='taskassignment',
            name='penalty_score',
            field=models.PositiveSmallIntegerField(
                default=0,
                help_text='0 hoặc 1 — tự động ghi 1 khi chọn mức trừ điểm.',
                verbose_name='Điểm trừ thi đua',
            ),
        ),
        migrations.AddField(
            model_name='taskparticipation',
            name='penalty_score',
            field=models.PositiveSmallIntegerField(
                default=0,
                help_text='0 hoặc 1 — tự động ghi 1 khi chọn mức trừ điểm.',
                verbose_name='Điểm trừ thi đua',
            ),
        ),
        migrations.AlterField(
            model_name='taskparticipation',
            name='evaluation',
            field=models.CharField(
                choices=[
                    ('none', 'Chưa đánh giá'),
                    ('DAT', 'Đạt'),
                    ('CHO_LAM_LAI', 'Chưa đạt - Yêu cầu làm lại'),
                    ('TRE_BI_TRU_DIEM', 'Chưa đạt - Yêu cầu làm lại và bị -1'),
                    # Keep legacy choices temporarily for data migration reads
                    ('pass', 'Đạt (cũ)'),
                    ('fail', 'Không đạt (cũ)'),
                ],
                default='none',
                max_length=20,
                verbose_name='Đánh giá nội bộ',
            ),
        ),
        migrations.RunPython(forwards_map_evaluation, backwards_map_evaluation),
        migrations.AlterField(
            model_name='taskparticipation',
            name='evaluation',
            field=models.CharField(
                choices=[
                    ('none', 'Chưa đánh giá'),
                    ('DAT', 'Đạt'),
                    ('CHO_LAM_LAI', 'Chưa đạt - Yêu cầu làm lại'),
                    ('TRE_BI_TRU_DIEM', 'Chưa đạt - Yêu cầu làm lại và bị -1'),
                ],
                default='none',
                max_length=20,
                verbose_name='Đánh giá nội bộ',
            ),
        ),
        migrations.RemoveField(
            model_name='taskassignment',
            name='coefficient',
        ),
        migrations.RemoveField(
            model_name='taskassignment',
            name='final_score',
        ),
        migrations.RemoveField(
            model_name='task',
            name='base_score',
        ),
        migrations.AddConstraint(
            model_name='taskassignment',
            constraint=models.CheckConstraint(
                condition=models.Q(('penalty_score__in', [0, 1])),
                name='taskassignment_penalty_0_or_1',
            ),
        ),
        migrations.AddConstraint(
            model_name='taskparticipation',
            constraint=models.CheckConstraint(
                condition=models.Q(('penalty_score__in', [0, 1])),
                name='taskparticipation_penalty_0_or_1',
            ),
        ),
    ]
