# Generated manually for FK → M2M members migration (part 1)

import django.db.models.deletion
from django.conf import settings
from django.db import migrations, models


def copy_fk_members_to_m2m(apps, schema_editor):
    """Chuyển User.department (FK) sang Department.members (M2M)."""
    User = apps.get_model('accounts', 'User')
    Department = apps.get_model('accounts', 'Department')
    Through = Department.members.through

    pairs = set()
    for user in User.objects.exclude(department_id=None).iterator():
        pairs.add((user.department_id, user.id))

    for dept in Department.objects.exclude(leader_id=None).iterator():
        pairs.add((dept.id, dept.leader_id))

    Through.objects.bulk_create(
        [Through(department_id=d, user_id=u) for d, u in pairs],
        ignore_conflicts=True,
    )


def noop_reverse(apps, schema_editor):
    pass


class Migration(migrations.Migration):

    dependencies = [
        ('accounts', '0001_initial'),
    ]

    operations = [
        migrations.AlterField(
            model_name='user',
            name='department',
            field=models.ForeignKey(
                blank=True,
                null=True,
                on_delete=django.db.models.deletion.SET_NULL,
                related_name='legacy_fk_members',
                to='accounts.department',
                verbose_name='Phòng ban / Tổ chuyên môn',
            ),
        ),
        migrations.AddField(
            model_name='department',
            name='members',
            field=models.ManyToManyField(
                blank=True,
                related_name='my_departments',
                to=settings.AUTH_USER_MODEL,
                verbose_name='Thành viên',
            ),
        ),
        migrations.CreateModel(
            name='GroupPost',
            fields=[
                ('id', models.BigAutoField(auto_created=True, primary_key=True, serialize=False, verbose_name='ID')),
                ('content', models.TextField(verbose_name='Nội dung thảo luận')),
                ('created_at', models.DateTimeField(auto_now_add=True)),
                ('author', models.ForeignKey(
                    on_delete=django.db.models.deletion.CASCADE,
                    related_name='group_posts',
                    to=settings.AUTH_USER_MODEL,
                    verbose_name='Người đăng',
                )),
                ('department', models.ForeignKey(
                    on_delete=django.db.models.deletion.CASCADE,
                    related_name='posts',
                    to='accounts.department',
                    verbose_name='Tổ/Nhóm',
                )),
            ],
            options={
                'verbose_name': 'Bài đăng nhóm',
                'verbose_name_plural': 'Bài đăng nhóm',
                'ordering': ['-created_at'],
            },
        ),
        migrations.RunPython(copy_fk_members_to_m2m, noop_reverse),
    ]
