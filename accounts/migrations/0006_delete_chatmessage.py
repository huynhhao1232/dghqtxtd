# Generated manually to remove ChatMessage (group chat feature).

from django.db import migrations


class Migration(migrations.Migration):

    dependencies = [
        ('accounts', '0005_chatmessage'),
    ]

    operations = [
        migrations.DeleteModel(
            name='ChatMessage',
        ),
    ]
