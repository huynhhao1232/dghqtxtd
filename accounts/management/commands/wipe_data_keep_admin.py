"""Xóa toàn bộ dữ liệu nghiệp vụ, chỉ giữ tài khoản admin."""

from django.contrib.auth import get_user_model
from django.core.management.base import BaseCommand, CommandError
from django.db import transaction

from accounts.models import Department
from tasks.models import Notification, Task

User = get_user_model()


class Command(BaseCommand):
    help = (
        'Xóa tasks, tổ/nhóm, viên chức (trừ admin). '
        'Dùng --username để chỉ định tài khoản giữ lại (mặc định: admin).'
    )

    def add_arguments(self, parser):
        parser.add_argument(
            '--username',
            default='admin',
            help='Username tài khoản giữ lại (mặc định: admin)',
        )
        parser.add_argument(
            '--noinput',
            action='store_true',
            help='Không hỏi xác nhận',
        )

    def handle(self, *args, **options):
        keep_username = (options['username'] or 'admin').strip()
        admin = User.objects.filter(username__iexact=keep_username).first()
        if not admin:
            raise CommandError(f'Không tìm thấy user "{keep_username}".')

        if not options['noinput']:
            self.stdout.write(
                self.style.WARNING(
                    f'Sẽ XÓA toàn bộ dữ liệu trên DB này, chỉ giữ user "{admin.username}".'
                )
            )
            confirm = input('Gõ YES để tiếp tục: ')
            if confirm.strip() != 'YES':
                self.stdout.write(self.style.ERROR('Đã hủy.'))
                return

        with transaction.atomic():
            notif_count = Notification.objects.count()
            Notification.objects.all().delete()

            task_count = Task.objects.count()
            Task.objects.all().delete()

            dept_count = Department.objects.count()
            Department.objects.all().delete()

            user_qs = User.objects.exclude(pk=admin.pk)
            user_count = user_qs.count()
            user_qs.delete()

        self.stdout.write(self.style.SUCCESS('Đã xóa dữ liệu:'))
        self.stdout.write(f'  - {task_count} công việc (+ assignment/participation liên quan)')
        self.stdout.write(f'  - {notif_count} thông báo')
        self.stdout.write(f'  - {dept_count} tổ/nhóm')
        self.stdout.write(f'  - {user_count} tài khoản (giữ lại: {admin.username})')
