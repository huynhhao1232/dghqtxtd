from datetime import timedelta

from django.core.management.base import BaseCommand
from django.utils import timezone

from accounts.models import Department, User
from tasks.models import Task, TaskAssignment


class Command(BaseCommand):
    help = 'Tạo dữ liệu demo: tổ nhóm, lãnh đạo, nhân viên và task mẫu'

    def handle(self, *args, **options):
        dept_toan, _ = Department.objects.get_or_create(
            name='Tổ Toán-Tin',
            defaults={
                'description': 'Tổ chuyên môn Toán và Tin học',
                'badge_color': 'blue',
            },
        )
        dept_van, _ = Department.objects.get_or_create(
            name='Tổ Văn',
            defaults={
                'description': 'Tổ chuyên môn Ngữ văn',
                'badge_color': 'violet',
            },
        )
        dept_bgh, _ = Department.objects.get_or_create(
            name='Ban Giám hiệu',
            defaults={
                'description': 'Ban lãnh đạo nhà trường',
                'badge_color': 'amber',
            },
        )

        manager, created = User.objects.get_or_create(
            username='lanhdao',
            defaults={
                'first_name': 'Văn',
                'last_name': 'Nguyễn',
                'email': 'lanhdao@example.com',
                'is_manager': True,
                'is_staff': True,
                'position': 'Phó Hiệu trưởng',
                'phone': '0901000001',
            },
        )
        if created or not manager.has_usable_password():
            manager.set_password('Demo@123')
            manager.save()
        dept_bgh.leader = manager
        dept_bgh.save(update_fields=['leader'])
        dept_bgh.members.add(manager)

        staff_specs = [
            {
                'username': 'nhanvien1',
                'first_name': 'Thị A',
                'last_name': 'Trần',
                'email': 'nv1@example.com',
                'departments': [dept_toan],
                'position': 'Giáo viên',
                'phone': '0901000002',
                'is_leader': True,
                'lead_dept': dept_toan,
            },
            {
                'username': 'nhanvien2',
                'first_name': 'Văn B',
                'last_name': 'Lê',
                'email': 'nv2@example.com',
                'departments': [dept_van],
                'position': 'Giáo viên',
                'phone': '0901000003',
                'is_leader': True,
                'lead_dept': dept_van,
            },
            {
                'username': 'nhanvien3',
                'first_name': 'Minh C',
                'last_name': 'Phạm',
                'email': 'nv3@example.com',
                # Demo: viên chức thuộc 2 tổ
                'departments': [dept_toan, dept_van],
                'position': 'Giáo viên',
                'phone': '0901000004',
                'is_leader': False,
                'lead_dept': None,
            },
        ]
        staff_users = []
        for spec in staff_specs:
            is_leader = spec.pop('is_leader')
            lead_dept = spec.pop('lead_dept')
            departments = spec.pop('departments')
            user, created = User.objects.get_or_create(
                username=spec['username'],
                defaults={
                    **{k: v for k, v in spec.items() if k != 'username'},
                    'is_manager': False,
                    'is_staff': True,
                },
            )
            if not created:
                user.position = spec['position']
                user.save(update_fields=['position'])
            if created or not user.has_usable_password():
                user.set_password('Demo@123')
                user.save()
            user.my_departments.set(departments)
            if is_leader and lead_dept:
                lead_dept.leader = user
                lead_dept.save(update_fields=['leader'])
                lead_dept.members.add(user)
            staff_users.append(user)

        admin, created = User.objects.get_or_create(
            username='admin',
            defaults={
                'first_name': 'Admin',
                'last_name': 'System',
                'email': 'admin@example.com',
                'is_manager': True,
                'is_staff': True,
                'is_superuser': True,
                'position': 'Quản trị viên',
            },
        )
        if created or not admin.has_usable_password():
            admin.set_password('Admin@123')
            admin.save()
        dept_bgh.members.add(admin)

        if not Task.objects.exists():
            today = timezone.localdate()
            task = Task.objects.create(
                title='Soạn đề kiểm tra giữa kỳ',
                description='Soạn đề và đáp án, nộp file PDF.',
                created_by=manager,
                deadline=today + timedelta(days=7),
                cycle=Task.CYCLE_MONTH,
                primary_department=dept_toan,
            )
            task.coordinating_departments.add(dept_van)
            leader = dept_toan.leader
            if leader:
                TaskAssignment.objects.create(task=task, assignee=leader)
            if dept_van.leader_id:
                TaskAssignment.objects.create(task=task, assignee=dept_van.leader)
            else:
                member = dept_toan.members.filter(is_manager=False).first()
                if member and not leader:
                    TaskAssignment.objects.create(task=task, assignee=member)

            task2 = Task.objects.create(
                title='Báo cáo chuyên môn tháng',
                description='Nộp báo cáo hoạt động chuyên môn.',
                created_by=manager,
                deadline=today - timedelta(days=2),
                cycle=Task.CYCLE_MONTH,
            )
            TaskAssignment.objects.create(
                task=task2,
                assignee=staff_users[0],
                status=TaskAssignment.STATUS_IN_PROGRESS,
            )

        self.stdout.write(self.style.SUCCESS('Đã tạo dữ liệu demo.'))
        self.stdout.write('  Lãnh đạo : lanhdao / Demo@123')
        self.stdout.write('  Nhân viên: nhanvien1 / Demo@123 (Tổ Toán-Tin — Trưởng tổ)')
        self.stdout.write('  Nhân viên: nhanvien2 / Demo@123 (Tổ Văn — Trưởng tổ)')
        self.stdout.write('  Nhân viên: nhanvien3 / Demo@123 (Tổ Toán-Tin + Tổ Văn)')
        self.stdout.write('  Admin    : admin / Admin@123')
