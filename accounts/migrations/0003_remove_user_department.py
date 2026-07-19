# Remove legacy User.department FK (separate step for SQLite safety)

from django.db import migrations


class Migration(migrations.Migration):

    dependencies = [
        ('accounts', '0002_department_members_m2m_grouppost'),
    ]

    operations = [
        migrations.RemoveField(
            model_name='user',
            name='department',
        ),
    ]
