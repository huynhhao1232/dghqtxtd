from django.db import migrations, models


def forwards_map_roles(apps, schema_editor):
    User = apps.get_model('accounts', 'User')
    Department = apps.get_model('accounts', 'Department')

    leader_ids = set(
        Department.objects.exclude(leader_id=None).values_list('leader_id', flat=True)
    )

    for user in User.objects.all().iterator():
        if user.is_manager:
            user.role = 'director'
        elif user.id in leader_ids:
            user.role = 'department'
        else:
            user.role = 'staff'
        user.save(update_fields=['role'])


def backwards_clear_roles(apps, schema_editor):
    User = apps.get_model('accounts', 'User')
    User.objects.update(role='staff')


class Migration(migrations.Migration):

    dependencies = [
        ('accounts', '0006_delete_chatmessage'),
    ]

    operations = [
        migrations.AddField(
            model_name='user',
            name='role',
            field=models.CharField(
                choices=[
                    ('director', 'Ban Giám đốc'),
                    ('department', 'Tổ chuyên môn'),
                    ('staff', 'Giáo viên / Nhân viên'),
                ],
                db_index=True,
                default='staff',
                help_text='director=Ban Giám đốc, department=Tổ chuyên môn, staff=Giáo viên/NV.',
                max_length=20,
                verbose_name='Vai trò',
            ),
        ),
        migrations.AlterField(
            model_name='user',
            name='is_manager',
            field=models.BooleanField(
                default=False,
                help_text='Legacy: True khi role=director (portal quản lý). Không dùng làm role app.',
                verbose_name='Là lãnh đạo',
            ),
        ),
        migrations.RunPython(forwards_map_roles, backwards_clear_roles),
    ]
