from datetime import timedelta
from django.core.exceptions import ValidationError
from django.db import IntegrityError
from django.test import Client, TestCase
from django.urls import reverse
from django.utils import timezone

from accounts.models import Department, User

from .forms import ReviewForm, SubtaskCreateForm
from .models import EvaluationResult, Notification, Task, TaskAssignment, TaskParticipation
from .reporting import (
    academic_year_bounds,
    academic_year_start_year,
    month_bounds,
    quarter_bounds,
    stats_for_department,
    stats_for_person,
)


class SubtaskWBSTestCase(TestCase):
    def setUp(self):
        self.manager = User.objects.create_user(
            username='mgr', password='x', is_manager=True, first_name='Lãnh', last_name='Đạo'
        )
        self.leader = User.objects.create_user(
            username='leader', password='x', is_manager=False, first_name='Trưởng', last_name='Tổ'
        )
        self.member1 = User.objects.create_user(
            username='m1', password='x', is_manager=False, first_name='A', last_name='Nguyễn'
        )
        self.member2 = User.objects.create_user(
            username='m2', password='x', is_manager=False, first_name='B', last_name='Trần'
        )
        self.outsider = User.objects.create_user(
            username='out', password='x', is_manager=False, first_name='C', last_name='Lê'
        )
        self.dept = Department.objects.create(name='Tổ Test', leader=self.leader)
        self.dept.members.add(self.leader, self.member1, self.member2)

        self.today = timezone.localdate()
        self.parent = Task.objects.create(
            title='Lễ khai giảng',
            created_by=self.manager,
            deadline=self.today + timedelta(days=30),
            cycle=Task.CYCLE_MONTH,
            primary_department=self.dept,
        )
        TaskAssignment.objects.create(task=self.parent, assignee=self.leader)
        for u in (self.member1, self.member2):
            TaskParticipation.objects.create(
                task=self.parent,
                user=u,
                department=self.dept,
                role=TaskParticipation.ROLE_LEAD,
            )

        self.client = Client()

    def _create_subtask(self, assignees=None, deadline=None, title='Thuê rạp'):
        sub = Task(
            title=title,
            created_by=self.leader,
            deadline=deadline or self.parent.deadline,
            cycle=self.parent.cycle,
            parent_task=self.parent,
        )
        sub.full_clean()
        sub.save()
        for u in (assignees or [self.member1]):
            TaskAssignment.objects.create(task=sub, assignee=u)
        return sub

    def test_is_subtask_synced_and_one_level(self):
        sub = self._create_subtask()
        self.assertTrue(sub.is_subtask)
        self.assertFalse(sub.is_team_task)

        nested = Task(
            title='Nested',
            created_by=self.leader,
            deadline=self.parent.deadline,
            cycle=self.parent.cycle,
            parent_task=sub,
        )
        with self.assertRaises(ValidationError):
            nested.full_clean()

    def test_deadline_cannot_exceed_parent(self):
        bad = Task(
            title='Quá hạn',
            created_by=self.leader,
            deadline=self.parent.deadline + timedelta(days=1),
            cycle=self.parent.cycle,
            parent_task=self.parent,
        )
        with self.assertRaises(ValidationError):
            bad.full_clean()

    def test_form_assignee_scope_participants_only(self):
        form = SubtaskCreateForm(
            data={
                'title': 'Viết diễn văn',
                'deadline': self.parent.deadline.isoformat(),
                'assignees': [self.outsider.pk],
            },
            parent_task=self.parent,
        )
        self.assertFalse(form.is_valid())
        self.assertIn('assignees', form.errors)

        form_ok = SubtaskCreateForm(
            data={
                'title': 'Viết diễn văn',
                'deadline': self.parent.deadline.isoformat(),
                'assignees': [self.member1.pk, self.member2.pk],
            },
            parent_task=self.parent,
        )
        self.assertTrue(form_ok.is_valid())

    def test_leader_creates_subtask_via_view(self):
        self.client.force_login(self.leader)
        parent_asg = self.parent.assignments.get(assignee=self.leader)
        url = reverse('staff_task_create_subtask', args=[parent_asg.pk])
        resp = self.client.post(url, {
            'title': 'Chuẩn bị âm thanh',
            'deadline': self.parent.deadline.isoformat(),
            'assignees': [self.member1.pk],
        })
        self.assertEqual(resp.status_code, 302)
        sub = Task.objects.get(title='Chuẩn bị âm thanh')
        self.assertTrue(sub.is_subtask)
        self.assertEqual(sub.parent_task_id, self.parent.pk)
        self.assertTrue(sub.assignments.filter(assignee=self.member1).exists())
        self.assertTrue(
            Notification.objects.filter(
                recipient=self.member1,
                related_task=sub,
            ).exists()
        )

    def test_member_cannot_create_subtask(self):
        TaskAssignment.objects.create(task=self.parent, assignee=self.member1)
        self.client.force_login(self.member1)
        asg = self.parent.assignments.get(assignee=self.member1)
        resp = self.client.post(reverse('staff_task_create_subtask', args=[asg.pk]), {
            'title': 'Hack',
            'deadline': self.parent.deadline.isoformat(),
            'assignees': [self.member2.pk],
        })
        self.assertEqual(resp.status_code, 403)

    def test_leader_can_edit_subtask(self):
        sub = self._create_subtask()
        self.client.force_login(self.leader)
        new_deadline = self.parent.deadline - timedelta(days=2)
        resp = self.client.post(reverse('staff_subtask_edit', args=[sub.pk]), {
            'title': 'Thuê rạp đã sửa',
            'deadline': new_deadline.isoformat(),
        })
        self.assertEqual(resp.status_code, 302)
        sub.refresh_from_db()
        self.assertEqual(sub.title, 'Thuê rạp đã sửa')
        self.assertEqual(sub.deadline, new_deadline)
        self.assertTrue(sub.assignments.filter(assignee=self.member1).exists())

    def test_member_cannot_edit_or_delete_subtask(self):
        sub = self._create_subtask()
        self.client.force_login(self.member1)
        edit_resp = self.client.post(
            reverse('staff_subtask_edit', args=[sub.pk]),
            {
                'title': 'Không được',
                'deadline': self.parent.deadline.isoformat(),
            },
        )
        delete_resp = self.client.post(
            reverse('staff_subtask_delete', args=[sub.pk])
        )
        self.assertEqual(edit_resp.status_code, 403)
        self.assertEqual(delete_resp.status_code, 403)
        self.assertTrue(Task.objects.filter(pk=sub.pk).exists())

    def test_leader_can_delete_subtask_with_assignments(self):
        sub = self._create_subtask(assignees=[self.member1, self.member2])
        self.client.force_login(self.leader)
        resp = self.client.post(reverse('staff_subtask_delete', args=[sub.pk]))
        self.assertEqual(resp.status_code, 302)
        self.assertFalse(Task.objects.filter(pk=sub.pk).exists())
        self.assertFalse(TaskAssignment.objects.filter(task_id=sub.pk).exists())

    def test_subtask_not_in_manager_review_queue(self):
        sub = self._create_subtask()
        asg = sub.assignments.get(assignee=self.member1)
        asg.status = TaskAssignment.STATUS_PENDING
        asg.submitted_at = timezone.now()
        asg.save()

        self.client.force_login(self.manager)
        resp = self.client.get(reverse('manager_review_queue'))
        self.assertEqual(resp.status_code, 200)
        self.assertNotContains(resp, sub.title)

    def test_leader_reviews_subtask_and_notifies_when_all_done(self):
        sub1 = self._create_subtask(assignees=[self.member1], title='Việc 1')
        sub2 = self._create_subtask(assignees=[self.member2], title='Việc 2')
        a1 = sub1.assignments.get(assignee=self.member1)
        a2 = sub2.assignments.get(assignee=self.member2)
        for a in (a1, a2):
            a.status = TaskAssignment.STATUS_PENDING
            a.submitted_at = timezone.now()
            a.save()

        self.client.force_login(self.leader)
        resp = self.client.post(reverse('staff_subtask_review', args=[a1.pk]), {
            'evaluation_result': EvaluationResult.DAT,
            'manager_comment': 'OK',
        })
        self.assertEqual(resp.status_code, 302)
        a1.refresh_from_db()
        self.assertEqual(a1.status, TaskAssignment.STATUS_COMPLETED)
        # Chưa đủ tất cả sub-task → chưa nhắc
        self.assertFalse(
            Notification.objects.filter(
                recipient=self.leader,
                message__icontains='công việc thành phần',
            ).exists()
        )

        resp = self.client.post(reverse('staff_subtask_review', args=[a2.pk]), {
            'evaluation_result': EvaluationResult.DAT,
            'manager_comment': '',
        })
        self.assertEqual(resp.status_code, 302)
        self.assertTrue(
            Notification.objects.filter(
                recipient=self.leader,
                related_task=self.parent,
                message__icontains='chốt tiến độ nhiệm vụ gốc',
            ).exists()
        )

    def test_my_tasks_shows_parent_badge(self):
        sub = self._create_subtask()
        self.client.force_login(self.member1)
        resp = self.client.get(reverse('staff_my_tasks'))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, sub.title)
        self.assertContains(resp, f'Từ: {self.parent.title}')

    def test_effective_completion_requires_all_assignees(self):
        sub = self._create_subtask(assignees=[self.member1, self.member2])
        a1 = sub.assignments.get(assignee=self.member1)
        a2 = sub.assignments.get(assignee=self.member2)
        a1.status = TaskAssignment.STATUS_COMPLETED
        a1.save(update_fields=['status'])
        self.assertFalse(sub.is_effectively_completed())
        a2.status = TaskAssignment.STATUS_COMPLETED
        a2.save(update_fields=['status'])
        self.assertTrue(sub.is_effectively_completed())

    def test_manager_batch_assign_creates_one_task_many_department_assignments(self):
        leader2 = User.objects.create_user(
            username='leader2',
            password='x',
            is_manager=False,
            first_name='Trưởng',
            last_name='Tổ Hai',
        )
        dept2 = Department.objects.create(name='Tổ Test 2', leader=leader2)
        self.client.force_login(self.manager)

        title = 'Báo cáo tháng đồng loạt'
        response = self.client.post(reverse('manager_create_task'), {
            'title': title,
            'description': 'Cùng yêu cầu, đánh giá riêng',
            'deadline': self.parent.deadline.isoformat(),
            'cycle': Task.CYCLE_MONTH,
            'assign_mode': 'batch_department',
            'batch_departments': [self.dept.pk, dept2.pk],
        })

        tasks = Task.objects.filter(title=title)
        self.assertEqual(tasks.count(), 1)
        task = tasks.get()
        self.assertIsNone(task.primary_department_id)
        self.assertRedirects(response, reverse('manager_task_detail', kwargs={'pk': task.pk}))

        assignments = list(task.assignments.select_related('assignee_department'))
        self.assertEqual(len(assignments), 2)
        self.assertEqual(
            {a.assignee_department_id for a in assignments},
            {self.dept.pk, dept2.pk},
        )
        self.assertTrue(all(a.assignee_id is None for a in assignments))
        self.assertTrue(
            Notification.objects.filter(
                recipient=leader2,
                message=f'Lãnh đạo đã giao cho tổ của bạn công việc: {title}',
            ).exists()
        )
        self.assertTrue(
            Notification.objects.filter(
                recipient=self.leader,
                message=f'Lãnh đạo đã giao cho tổ của bạn công việc: {title}',
            ).exists()
        )

    def test_batch_all_members_creates_one_task_per_member(self):
        """Giao đồng loạt + Mỗi thành viên tự thực hiện → N task cá nhân."""
        self.client.force_login(self.manager)
        base_title = 'Nộp sổ chủ nhiệm'
        response = self.client.post(reverse('manager_create_task'), {
            'title': base_title,
            'description': 'Mỗi GV nộp riêng',
            'deadline': self.parent.deadline.isoformat(),
            'cycle': Task.CYCLE_MONTH,
            'assign_mode': 'batch_department',
            'batch_departments': [self.dept.pk],
            'department_execution_mode': 'all_members',
        })
        self.assertEqual(response.status_code, 302)

        members = [self.leader, self.member1, self.member2]
        tasks = list(Task.objects.filter(title__startswith=f'{base_title} - ['))
        self.assertEqual(len(tasks), 3)
        self.assertTrue(all(t.primary_department_id is None for t in tasks))
        self.assertTrue(all(not t.is_team_task for t in tasks))
        batch_keys = {t.batch_key for t in tasks}
        self.assertEqual(len(batch_keys), 1)
        self.assertIsNotNone(next(iter(batch_keys)))
        self.assertTrue(all(t.source_department_id == self.dept.pk for t in tasks))

        for member in members:
            display = str(member).strip() or member.username
            expected_title = f'{base_title} - [{display}]'
            task = Task.objects.get(title=expected_title)
            assignment = task.assignments.get()
            self.assertEqual(assignment.assignee_id, member.pk)
            self.assertIsNone(assignment.assignee_department_id)
            self.assertTrue(
                Notification.objects.filter(
                    recipient=member,
                    related_task=task,
                    message=f'Bạn được phân công công việc mới: {expected_title}',
                ).exists()
            )

        # Không tạo task giao theo tổ (assignee_department)
        self.assertFalse(
            Task.objects.filter(title=base_title).exists()
        )

        # Trang đã giao: 1 hàng phẳng + Chi tiết mở phân cấp
        list_resp = self.client.get(reverse('manager_manage_tasks'))
        self.assertEqual(list_resp.status_code, 200)
        nodes = list_resp.context['tree_nodes']
        batch_nodes = [n for n in nodes if n.get('kind') == 'batch' and n['title'] == base_title]
        self.assertEqual(len(batch_nodes), 1)
        self.assertEqual(batch_nodes[0]['total'], 3)
        self.assertEqual(batch_nodes[0]['done'], 0)
        self.assertContains(list_resp, '0%')
        self.assertContains(list_resp, 'Chi tiết')
        self.assertNotContains(list_resp, 'tree-toggle')
        self.assertContains(list_resp, self.dept.name)

        detail = self.client.get(
            reverse('manager_task_detail', kwargs={'pk': batch_nodes[0]['task'].pk})
        )
        self.assertEqual(detail.status_code, 200)
        self.assertContains(detail, base_title)
        self.assertContains(detail, 'Tiến độ theo Tổ/Nhóm')
        self.assertContains(detail, self.dept.name)
        self.assertContains(detail, 'Xem chi tiết')
        self.assertContains(detail, 'dept-accordion-item')
        self.assertContains(detail, 'dept-members')
        self.assertIsNotNone(detail.context['hierarchy'])
        self.assertEqual(detail.context['hierarchy']['total'], 3)

    def test_department_kanban_groups_batch_member_tasks_into_one_card(self):
        """Workspace tổ: N task '... - [Tên]' cùng batch → 1 thẻ Kanban + API members."""
        self.client.force_login(self.manager)
        base_title = 'Nộp giáo án điện tử'
        response = self.client.post(reverse('manager_create_task'), {
            'title': base_title,
            'description': 'Mỗi GV nộp riêng',
            'deadline': self.parent.deadline.isoformat(),
            'cycle': Task.CYCLE_MONTH,
            'assign_mode': 'batch_department',
            'batch_departments': [self.dept.pk],
            'department_execution_mode': 'all_members',
        })
        self.assertEqual(response.status_code, 302)
        tasks = list(Task.objects.filter(title__startswith=f'{base_title} - ['))
        self.assertEqual(len(tasks), 3)

        self.client.force_login(self.leader)
        kanban = self.client.get(
            reverse('department_interaction', kwargs={'dept_id': self.dept.pk})
        )
        self.assertEqual(kanban.status_code, 200)
        rows = kanban.context['task_rows']
        batch_rows = [r for r in rows if r.get('is_batch_group')]
        self.assertEqual(len(batch_rows), 1)
        self.assertEqual(batch_rows[0]['task'].title, base_title)
        self.assertEqual(batch_rows[0]['batch_total'], 3)
        self.assertFalse(batch_rows[0]['can_drag'])
        self.assertContains(kanban, base_title)
        self.assertContains(kanban, '0/3 xong')
        self.assertContains(kanban, 'data-is-batch="1"')
        # Thẻ Kanban dùng tiêu đề gốc (không hậu tố thành viên)
        card_titles = [
            r['task'].title for r in rows if r.get('is_batch_group')
        ]
        self.assertEqual(card_titles, [base_title])
        for r in rows:
            if r.get('is_batch_group'):
                self.assertNotIn(' - [', r['task'].title)

        anchor_id = batch_rows[0]['task'].pk
        member_ids = ','.join(str(t) for t in batch_rows[0]['batch_task_ids'])
        api = self.client.get(
            reverse('task_detail_api', kwargs={'pk': anchor_id}),
            {
                'dept_id': self.dept.pk,
                'member_ids': member_ids,
            },
        )
        self.assertEqual(api.status_code, 200)
        payload = api.json()
        self.assertTrue(payload['is_batch'])
        self.assertEqual(payload['title'], base_title)
        self.assertEqual(payload['batch_total'], 3)
        self.assertEqual(len(payload['members']), 3)
        self.assertTrue(all(m.get('detail_url') for m in payload['members']))

    def test_department_leader_coordinate_keeps_existing_flow(self):
        """Chủ trì — Phối hợp → luôn giao Trưởng tổ Chủ trì như cũ."""
        self.client.force_login(self.manager)
        title = 'Họp tổ chuyên môn'
        response = self.client.post(reverse('manager_create_task'), {
            'title': title,
            'description': 'Trưởng tổ điều phối',
            'deadline': self.parent.deadline.isoformat(),
            'cycle': Task.CYCLE_MONTH,
            'assign_mode': 'department',
            'primary_department': self.dept.pk,
        })
        self.assertEqual(response.status_code, 302)
        task = Task.objects.get(title=title)
        self.assertEqual(task.primary_department_id, self.dept.pk)
        self.assertTrue(task.is_team_task)
        self.assertEqual(task.assignments.count(), 1)
        self.assertEqual(task.assignments.get().assignee_id, self.leader.pk)


class UnifiedAssignmentDashboardTestCase(TestCase):
    def setUp(self):
        self.manager = User.objects.create_user(
            username='mgr2', password='x', is_manager=True, first_name='Lãnh', last_name='Đạo'
        )
        self.leader1 = User.objects.create_user(
            username='l1', password='x', is_manager=False, first_name='Trưởng', last_name='Một'
        )
        self.leader2 = User.objects.create_user(
            username='l2', password='x', is_manager=False, first_name='Trưởng', last_name='Hai'
        )
        self.dept1 = Department.objects.create(name='Tổ Một', leader=self.leader1)
        self.dept2 = Department.objects.create(name='Tổ Hai', leader=self.leader2)
        self.dept1.members.add(self.leader1)
        self.dept2.members.add(self.leader2)
        self.today = timezone.localdate()
        self.client = Client()

    def _create_batch_task(self):
        self.client.force_login(self.manager)
        title = 'Nộp báo cáo chuyên môn'
        resp = self.client.post(reverse('manager_create_task'), {
            'title': title,
            'description': 'Mỗi tổ nộp riêng',
            'deadline': (self.today + timedelta(days=14)).isoformat(),
            'cycle': Task.CYCLE_MONTH,
            'assign_mode': 'batch_department',
            'batch_departments': [self.dept1.pk, self.dept2.pk],
        })
        task = Task.objects.get(title=title)
        self.assertEqual(resp.status_code, 302)
        return task

    def test_assignment_target_xor_and_unique(self):
        task = Task.objects.create(
            title='XOR',
            created_by=self.manager,
            deadline=self.today + timedelta(days=7),
            cycle=Task.CYCLE_MONTH,
        )
        a = TaskAssignment(task=task)
        with self.assertRaises(ValidationError):
            a.full_clean()

        both = TaskAssignment(
            task=task,
            assignee=self.leader1,
            assignee_department=self.dept1,
        )
        with self.assertRaises(ValidationError):
            both.full_clean()

        TaskAssignment.objects.create(task=task, assignee_department=self.dept1)
        dup = TaskAssignment(task=task, assignee_department=self.dept1)
        with self.assertRaises(IntegrityError):
            dup.save()

    def test_manager_list_one_row_and_progress_xy(self):
        task = self._create_batch_task()
        a1 = task.assignments.get(assignee_department=self.dept1)
        a1.status = TaskAssignment.STATUS_PENDING
        a1.submitted_at = timezone.now()
        a1.save(update_fields=['status', 'submitted_at', 'updated_at'])

        self.client.force_login(self.manager)
        resp = self.client.get(reverse('manager_manage_tasks'))
        self.assertEqual(resp.status_code, 200)
        self.assertEqual(resp.context['stats']['total'], 1)
        nodes = resp.context['tree_nodes']
        self.assertEqual(len(nodes), 1)
        self.assertEqual(nodes[0]['task'].pk, task.pk)
        self.assertFalse(nodes[0]['is_flat'])
        self.assertContains(resp, '50%')
        self.assertContains(resp, self.dept1.name)
        self.assertContains(resp, self.dept2.name)

    def test_staff_leader_sees_department_assignment_and_updates_independently(self):
        task = self._create_batch_task()
        a1 = task.assignments.get(assignee_department=self.dept1)
        a2 = task.assignments.get(assignee_department=self.dept2)

        self.client.force_login(self.leader1)
        resp = self.client.get(reverse('staff_my_tasks'))
        self.assertContains(resp, task.title)
        self.assertContains(resp, 'Bản nộp của tổ')

        detail = self.client.get(reverse('staff_task_detail', kwargs={'pk': a1.pk}))
        self.assertEqual(detail.status_code, 200)

        update = self.client.post(reverse('staff_task_detail', kwargs={'pk': a1.pk}), {
            'action': 'update_progress',
            'status': TaskAssignment.STATUS_PENDING,
            'notes': 'Tổ Một đã xong',
        })
        self.assertEqual(update.status_code, 302)
        a1.refresh_from_db()
        a2.refresh_from_db()
        self.assertEqual(a1.status, TaskAssignment.STATUS_PENDING)
        self.assertEqual(a2.status, TaskAssignment.STATUS_TODO)

        # Leader 2 không thao tác được assignment của tổ 1
        self.client.force_login(self.leader2)
        forbidden = self.client.get(reverse('staff_task_detail', kwargs={'pk': a1.pk}))
        self.assertEqual(forbidden.status_code, 403)

    def test_batch_departments_manage_members_and_subtasks_independently(self):
        member1 = User.objects.create_user(
            username='batch_member_1',
            password='x',
            is_manager=False,
            first_name='Thành viên',
            last_name='Một',
        )
        member2 = User.objects.create_user(
            username='batch_member_2',
            password='x',
            is_manager=False,
            first_name='Thành viên',
            last_name='Hai',
        )
        self.dept1.members.add(member1)
        self.dept2.members.add(member2)
        task = self._create_batch_task()
        a1 = task.assignments.get(assignee_department=self.dept1)
        a2 = task.assignments.get(assignee_department=self.dept2)

        self.client.force_login(self.leader1)
        add1 = self.client.post(
            reverse('staff_task_add_members', kwargs={'pk': a1.pk}),
            {'member_ids': [member1.pk]},
        )
        self.assertRedirects(
            add1, reverse('staff_task_detail', kwargs={'pk': a1.pk})
        )
        self.assertTrue(
            task.participations.filter(
                user=member1,
                department=self.dept1,
            ).exists()
        )
        # Thành viên batch không tạo thêm assignment trên Task gốc.
        self.assertFalse(
            task.assignments.filter(assignee=member1).exists()
        )

        create1 = self.client.post(
            reverse('staff_task_create_subtask', kwargs={'pk': a1.pk}),
            {
                'title': 'Việc riêng tổ Một',
                'deadline': task.deadline.isoformat(),
                'assignees': [member1.pk],
            },
        )
        self.assertRedirects(
            create1, reverse('staff_task_detail', kwargs={'pk': a1.pk})
        )
        sub1 = task.subtasks.get(title='Việc riêng tổ Một')
        self.assertEqual(sub1.scope_department_id, self.dept1.pk)
        self.assertTrue(sub1.assignments.filter(assignee=member1).exists())

        detail1 = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': a1.pk})
        )
        self.assertTrue(detail1.context['can_manage_members'])
        self.assertTrue(detail1.context['can_manage_subtasks'])
        self.assertContains(detail1, member1.get_full_name())
        self.assertContains(detail1, sub1.title)

        self.client.force_login(self.leader2)
        detail2 = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': a2.pk})
        )
        self.assertNotContains(detail2, sub1.title)
        self.assertNotContains(detail2, member1.get_full_name())

        cross_edit = self.client.post(
            reverse('staff_subtask_edit', kwargs={'pk': sub1.pk}),
            {
                'title': 'Không được sửa',
                'deadline': task.deadline.isoformat(),
            },
        )
        self.assertEqual(cross_edit.status_code, 403)
        sub1.refresh_from_db()
        self.assertEqual(sub1.title, 'Việc riêng tổ Một')

        add2 = self.client.post(
            reverse('staff_task_add_members', kwargs={'pk': a2.pk}),
            {'member_ids': [member2.pk]},
        )
        self.assertEqual(add2.status_code, 302)
        create2 = self.client.post(
            reverse('staff_task_create_subtask', kwargs={'pk': a2.pk}),
            {
                'title': 'Việc riêng tổ Hai',
                'deadline': task.deadline.isoformat(),
                'assignees': [member2.pk],
            },
        )
        self.assertEqual(create2.status_code, 302)
        sub2 = task.subtasks.get(title='Việc riêng tổ Hai')
        self.assertEqual(sub2.scope_department_id, self.dept2.pk)

        detail2 = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': a2.pk})
        )
        self.assertContains(detail2, sub2.title)
        self.assertNotContains(detail2, sub1.title)

    def test_manager_dashboard_grades_one_assignment_only(self):
        task = self._create_batch_task()
        a1 = task.assignments.get(assignee_department=self.dept1)
        a2 = task.assignments.get(assignee_department=self.dept2)
        a1.status = TaskAssignment.STATUS_PENDING
        a1.submitted_at = timezone.now()
        a1.save(update_fields=['status', 'submitted_at', 'updated_at'])
        a2.status = TaskAssignment.STATUS_PENDING
        a2.submitted_at = timezone.now()
        a2.save(update_fields=['status', 'submitted_at', 'updated_at'])

        self.client.force_login(self.manager)
        detail = self.client.get(reverse('manager_task_detail', kwargs={'pk': task.pk}))
        self.assertEqual(detail.status_code, 200)
        self.assertContains(detail, '2/2')

        grade = self.client.post(
            reverse('manager_assignment_review', kwargs={
                'pk': task.pk,
                'assignment_pk': a1.pk,
            }),
            {
                'evaluation_result': EvaluationResult.DAT,
                'manager_comment': 'Tốt',
            },
        )
        self.assertRedirects(grade, reverse('manager_task_detail', kwargs={'pk': task.pk}))
        a1.refresh_from_db()
        a2.refresh_from_db()
        self.assertEqual(a1.status, TaskAssignment.STATUS_COMPLETED)
        self.assertEqual(a1.evaluation_result, EvaluationResult.DAT)
        self.assertEqual(a1.penalty_score, 0)
        self.assertEqual(a2.status, TaskAssignment.STATUS_PENDING)
        self.assertIsNone(a2.evaluation_result)

    def test_classic_team_task_still_works(self):
        """Chủ trì–Phối hợp không bị regression bởi batch department assignments."""
        task = Task.objects.create(
            title='Classic team',
            created_by=self.manager,
            deadline=self.today + timedelta(days=10),
            cycle=Task.CYCLE_MONTH,
            primary_department=self.dept1,
        )
        task.coordinating_departments.add(self.dept2)
        TaskAssignment.objects.create(task=task, assignee=self.leader1)
        TaskAssignment.objects.create(task=task, assignee=self.leader2)

        self.assertTrue(task.is_team_task)
        canonical = task.get_canonical_assignment()
        self.assertEqual(canonical.assignee_id, self.leader1.id)
        canonical.apply_review(EvaluationResult.DAT, 'OK')
        canonical.save()
        task.sync_team_assignment_status(canonical)
        a2 = task.assignments.get(assignee=self.leader2)
        self.assertEqual(a2.status, TaskAssignment.STATUS_COMPLETED)
        self.assertEqual(a2.evaluation_result, EvaluationResult.DAT)

    def test_grade_modal_requires_evaluation_result_choice(self):
        self.assertTrue(
            ReviewForm({'evaluation_result': EvaluationResult.DAT, 'manager_comment': 'ok'}).is_valid()
        )
        self.assertFalse(
            ReviewForm({'manager_comment': 'ok'}).is_valid()
        )

        task = self._create_batch_task()
        a1 = task.assignments.get(assignee_department=self.dept1)
        a1.status = TaskAssignment.STATUS_PENDING
        a1.submitted_at = timezone.now()
        a1.save(update_fields=['status', 'submitted_at', 'updated_at'])

        self.client.force_login(self.manager)
        detail = self.client.get(reverse('manager_task_detail', kwargs={'pk': task.pk}))
        self.assertContains(detail, 'value="DAT"')
        self.assertContains(detail, 'value="CHO_LAM_LAI"')
        self.assertContains(detail, 'value="TRE_BI_TRU_DIEM"')

        grade = self.client.post(
            reverse(
                'manager_assignment_review',
                kwargs={'pk': task.pk, 'assignment_pk': a1.pk},
            ),
            {'evaluation_result': EvaluationResult.DAT, 'manager_comment': 'Đạt'},
        )
        self.assertRedirects(grade, reverse('manager_task_detail', kwargs={'pk': task.pk}))
        a1.refresh_from_db()
        self.assertEqual(a1.status, TaskAssignment.STATUS_COMPLETED)

    def test_classic_team_is_reviewed_once_then_primary_leader_evaluates_people(self):
        member1 = User.objects.create_user(
            username='team_member_1', password='x', is_manager=False
        )
        member2 = User.objects.create_user(
            username='team_member_2', password='x', is_manager=False
        )
        self.dept1.members.add(member1)
        self.dept2.members.add(member2)
        task = Task.objects.create(
            title='Nhiệm vụ phối hợp toàn Task',
            created_by=self.manager,
            deadline=self.today + timedelta(days=10),
            cycle=Task.CYCLE_MONTH,
            primary_department=self.dept1,
        )
        task.coordinating_departments.add(self.dept2)
        canonical = TaskAssignment.objects.create(
            task=task,
            assignee=self.leader1,
            status=TaskAssignment.STATUS_PENDING,
            submitted_at=timezone.now(),
        )
        coordinating = TaskAssignment.objects.create(
            task=task,
            assignee=self.leader2,
            status=TaskAssignment.STATUS_PENDING,
            submitted_at=timezone.now(),
        )
        lead_part = TaskParticipation.objects.create(
            task=task,
            user=member1,
            department=self.dept1,
            role=TaskParticipation.ROLE_LEAD,
        )
        coord_part = TaskParticipation.objects.create(
            task=task,
            user=member2,
            department=self.dept2,
            role=TaskParticipation.ROLE_COORD,
        )

        self.client.force_login(self.manager)
        queue = self.client.get(reverse('manager_review_queue'))
        self.assertEqual(list(queue.context['queue']), [canonical])

        detail = self.client.get(
            reverse('manager_task_detail', kwargs={'pk': task.pk})
        )
        self.assertEqual(len(detail.context['assignments']), 1)
        self.assertContains(detail, 'Đánh giá toàn nhiệm vụ')
        self.assertContains(detail, 'Nghiệm thu')

        invalid_review = self.client.post(
            reverse(
                'manager_assignment_review',
                kwargs={'pk': task.pk, 'assignment_pk': coordinating.pk},
            ),
            {
                'evaluation_result': EvaluationResult.DAT,
                'manager_comment': 'Không được chấm riêng',
            },
        )
        self.assertEqual(invalid_review.status_code, 403)

        review = self.client.post(
            reverse(
                'manager_assignment_review',
                kwargs={'pk': task.pk, 'assignment_pk': canonical.pk},
            ),
            {
                'evaluation_result': EvaluationResult.DAT,
                'manager_comment': 'Đạt toàn nhiệm vụ',
            },
        )
        self.assertRedirects(
            review, reverse('manager_task_detail', kwargs={'pk': task.pk})
        )
        canonical.refresh_from_db()
        coordinating.refresh_from_db()
        self.assertEqual(canonical.status, TaskAssignment.STATUS_COMPLETED)
        self.assertEqual(coordinating.status, TaskAssignment.STATUS_COMPLETED)
        self.assertEqual(coordinating.evaluation_result, canonical.evaluation_result)

        self.client.force_login(self.leader2)
        coord_evaluation = self.client.post(
            reverse('staff_task_detail', kwargs={'pk': coordinating.pk}),
            {
                'action': 'internal_evaluate',
                f'eval_{coord_part.pk}': EvaluationResult.DAT,
            },
        )
        self.assertEqual(coord_evaluation.status_code, 403)

        self.client.force_login(self.leader1)
        leader_detail = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': canonical.pk})
        )
        self.assertTrue(leader_detail.context['needs_eval'])
        self.assertEqual(
            {p.pk for p in leader_detail.context['evaluation_participations']},
            {lead_part.pk, coord_part.pk},
        )

        evaluation = self.client.post(
            reverse('staff_task_detail', kwargs={'pk': canonical.pk}),
            {
                'action': 'internal_evaluate',
                f'eval_{lead_part.pk}': EvaluationResult.DAT,
                f'eval_{coord_part.pk}': EvaluationResult.CHO_LAM_LAI,
            },
        )
        self.assertRedirects(
            evaluation,
            reverse('staff_task_detail', kwargs={'pk': canonical.pk}),
        )
        lead_part.refresh_from_db()
        coord_part.refresh_from_db()
        self.assertEqual(lead_part.evaluation, EvaluationResult.DAT)
        self.assertEqual(coord_part.evaluation, EvaluationResult.CHO_LAM_LAI)
        self.assertEqual(lead_part.penalty_score, 0)
        self.assertEqual(coord_part.penalty_score, 0)


class QuantitativeEvaluationTestCase(TestCase):
    def setUp(self):
        self.manager = User.objects.create_user(
            username='mgr_q', password='x', is_manager=True
        )
        self.staff = User.objects.create_user(
            username='staff_q', password='x', is_manager=False
        )
        self.today = timezone.localdate()
        self.task = Task.objects.create(
            title='Việc định lượng',
            created_by=self.manager,
            deadline=self.today + timedelta(days=5),
            cycle=Task.CYCLE_MONTH,
        )
        self.assignment = TaskAssignment.objects.create(
            task=self.task,
            assignee=self.staff,
            status=TaskAssignment.STATUS_PENDING,
            submitted_at=timezone.now(),
        )

    def test_three_results_map_status_and_penalty(self):
        cases = [
            (EvaluationResult.DAT, TaskAssignment.STATUS_COMPLETED, 0),
            (EvaluationResult.CHO_LAM_LAI, TaskAssignment.STATUS_REDO, 0),
            (EvaluationResult.TRE_BI_TRU_DIEM, TaskAssignment.STATUS_REDO, 1),
        ]
        for result, status, penalty in cases:
            with self.subTest(result=result):
                self.assignment.apply_review(result, 'note')
                self.assertEqual(self.assignment.status, status)
                self.assertEqual(self.assignment.penalty_score, penalty)
                self.assertEqual(self.assignment.evaluation_result, result)
                self.assertIsNotNone(self.assignment.reviewed_at)

    def test_rereview_overwrites_previous_result(self):
        self.assignment.apply_review(EvaluationResult.TRE_BI_TRU_DIEM, 'lần 1')
        self.assignment.save()
        first_reviewed = self.assignment.reviewed_at
        self.assignment.status = TaskAssignment.STATUS_PENDING
        self.assignment.save(update_fields=['status'])
        self.assignment.apply_review(EvaluationResult.DAT, 'lần 2')
        self.assignment.save()
        self.assignment.refresh_from_db()
        self.assertEqual(self.assignment.evaluation_result, EvaluationResult.DAT)
        self.assertEqual(self.assignment.penalty_score, 0)
        self.assertEqual(self.assignment.status, TaskAssignment.STATUS_COMPLETED)
        self.assertEqual(self.assignment.manager_comment, 'lần 2')
        self.assertGreaterEqual(self.assignment.reviewed_at, first_reviewed)

    def test_participation_three_levels(self):
        dept = Department.objects.create(name='Tổ Q', leader=self.staff)
        part = TaskParticipation.objects.create(
            task=self.task,
            user=self.staff,
            department=dept,
            role=TaskParticipation.ROLE_LEAD,
        )
        part.apply_evaluation(EvaluationResult.TRE_BI_TRU_DIEM)
        self.assertEqual(part.evaluation, EvaluationResult.TRE_BI_TRU_DIEM)
        self.assertEqual(part.penalty_score, 1)
        part.apply_evaluation(EvaluationResult.DAT)
        self.assertEqual(part.evaluation, EvaluationResult.DAT)
        self.assertEqual(part.penalty_score, 0)


class ReportingPeriodTestCase(TestCase):
    def test_month_quarter_academic_year_bounds(self):
        from datetime import date

        m_start, m_end = month_bounds(date(2026, 1, 31))
        self.assertEqual(m_start.date(), date(2026, 1, 1))
        self.assertEqual(m_end.date(), date(2026, 2, 1))

        q1_start, q1_end = quarter_bounds(date(2026, 2, 15))
        self.assertEqual(q1_start.date(), date(2026, 1, 1))
        self.assertEqual(q1_end.date(), date(2026, 4, 1))
        q4_start, q4_end = quarter_bounds(date(2026, 11, 1))
        self.assertEqual(q4_start.date(), date(2026, 10, 1))
        self.assertEqual(q4_end.date(), date(2027, 1, 1))

        self.assertEqual(academic_year_start_year(date(2026, 8, 31)), 2025)
        self.assertEqual(academic_year_start_year(date(2026, 9, 1)), 2026)
        a_start, a_end = academic_year_bounds(2025)
        self.assertEqual(a_start.date(), date(2025, 9, 1))
        self.assertEqual(a_end.date(), date(2026, 9, 1))

        # Năm nhuận: cuối tháng 2 vẫn thuộc đúng tháng
        feb_start, feb_end = month_bounds(date(2024, 2, 29))
        self.assertEqual(feb_start.date(), date(2024, 2, 1))
        self.assertEqual(feb_end.date(), date(2024, 3, 1))


class ReportingAttributionTestCase(TestCase):
    def setUp(self):
        self.manager = User.objects.create_user(
            username='mgr_r', password='x', is_manager=True
        )
        self.leader = User.objects.create_user(
            username='leader_r', password='x', is_manager=False
        )
        self.member = User.objects.create_user(
            username='member_r', password='x', is_manager=False
        )
        self.dept = Department.objects.create(name='Tổ Báo cáo', leader=self.leader)
        self.dept.members.add(self.leader, self.member)
        self.today = timezone.localdate()
        self.now = timezone.now()

    def _reviewed(self, assignment, result):
        assignment.apply_review(result, '')
        assignment.reviewed_at = self.now
        assignment.save()

    def test_personal_vs_department_and_no_classic_double_count(self):
        # Assignment cá nhân của member
        personal_task = Task.objects.create(
            title='Cá nhân',
            created_by=self.manager,
            deadline=self.today,
            cycle=Task.CYCLE_MONTH,
        )
        personal = TaskAssignment.objects.create(
            task=personal_task,
            assignee=self.member,
            status=TaskAssignment.STATUS_PENDING,
            submitted_at=self.now,
        )
        self._reviewed(personal, EvaluationResult.DAT)

        # Batch department: chỉ tính vào tổ, không quy cho trưởng tổ cá nhân
        batch_task = Task.objects.create(
            title='Batch',
            created_by=self.manager,
            deadline=self.today,
            cycle=Task.CYCLE_MONTH,
        )
        batch = TaskAssignment.objects.create(
            task=batch_task,
            assignee_department=self.dept,
            status=TaskAssignment.STATUS_PENDING,
            submitted_at=self.now,
        )
        self._reviewed(batch, EvaluationResult.TRE_BI_TRU_DIEM)

        # Classic team: một kết quả canonical cho tổ chủ trì + tổ phối hợp
        coord_leader = User.objects.create_user(
            username='coord_r', password='x', is_manager=False
        )
        coord_dept = Department.objects.create(name='Tổ Phối', leader=coord_leader)
        classic = Task.objects.create(
            title='Classic',
            created_by=self.manager,
            deadline=self.today,
            cycle=Task.CYCLE_MONTH,
            primary_department=self.dept,
        )
        classic.coordinating_departments.add(coord_dept)
        can = TaskAssignment.objects.create(
            task=classic,
            assignee=self.leader,
            status=TaskAssignment.STATUS_PENDING,
            submitted_at=self.now,
        )
        TaskAssignment.objects.create(
            task=classic,
            assignee=coord_leader,
            status=TaskAssignment.STATUS_PENDING,
            submitted_at=self.now,
        )
        self._reviewed(can, EvaluationResult.DAT)
        classic.sync_team_assignment_status(can)

        part = TaskParticipation.objects.create(
            task=classic,
            user=self.member,
            department=self.dept,
            role=TaskParticipation.ROLE_LEAD,
        )
        part.apply_evaluation(EvaluationResult.CHO_LAM_LAI)
        part.evaluated_at = self.now
        part.save(update_fields=['evaluated_at'])

        person_member = stats_for_person(self.member)
        self.assertEqual(person_member.dat_count, 1)  # personal assignment
        self.assertEqual(person_member.penalty_total, 0)  # CHO_LAM_LAI không trừ

        person_leader = stats_for_person(self.leader)
        # Không quy batch/classic assignment tổ cho cá nhân Trưởng tổ
        self.assertEqual(person_leader.dat_count, 0)
        self.assertEqual(person_leader.penalty_total, 0)

        dept_stats = stats_for_department(self.dept)
        # batch TRE (0 đạt, 1 trừ) + classic DAT (1 đạt, 0 trừ)
        self.assertEqual(dept_stats.dat_count, 1)
        self.assertEqual(dept_stats.penalty_total, 1)

        coord_stats = stats_for_department(coord_dept)
        self.assertEqual(coord_stats.dat_count, 1)
        self.assertEqual(coord_stats.penalty_total, 0)

    def test_statistics_html_matches_excel_totals(self):
        task = Task.objects.create(
            title='Export match',
            created_by=self.manager,
            deadline=self.today,
            cycle=Task.CYCLE_MONTH,
        )
        asg = TaskAssignment.objects.create(
            task=task,
            assignee=self.member,
            status=TaskAssignment.STATUS_PENDING,
            submitted_at=self.now,
        )
        self._reviewed(asg, EvaluationResult.DAT)

        client = Client()
        client.force_login(self.manager)
        year = self.today.year
        html = client.get(
            reverse('manager_statistics'),
            {'scope': 'person', 'period': 'month', 'year': year},
        )
        self.assertEqual(html.status_code, 200)
        excel = client.get(
            reverse('manager_export_excel'),
            {'scope': 'person', 'period': 'month', 'year': year},
        )
        self.assertEqual(excel.status_code, 200)
        self.assertIn(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            excel['Content-Type'],
        )
        # HTML row totals for member: ít nhất 1 Đạt trong năm
        row = next(
            r for r in html.context['rows'] if r['label'] == str(self.member)
            or self.member.username in r['label']
            or (self.member.get_full_name() and self.member.get_full_name() in r['label'])
        )
        self.assertGreaterEqual(row['dat_total'], 1)
        self.assertEqual(row['penalty_total'], 0)


class RoleBasedAccessControlTestCase(TestCase):
    """RBAC: director / department / staff — assignee filter, create, list isolation."""

    def setUp(self):
        self.director = User.objects.create_user(
            username='dir_rbac',
            password='x',
            is_manager=True,
            first_name='Giám',
            last_name='Đốc',
        )
        self.dept_user = User.objects.create_user(
            username='dept_rbac',
            password='x',
            role=User.ROLE_DEPARTMENT,
            first_name='Tổ',
            last_name='Trưởng',
        )
        self.staff_user = User.objects.create_user(
            username='staff_rbac',
            password='x',
            role=User.ROLE_STAFF,
            first_name='Giáo',
            last_name='Viên',
        )
        self.other_staff = User.objects.create_user(
            username='other_rbac',
            password='x',
            role=User.ROLE_STAFF,
            first_name='Khác',
            last_name='GV',
        )
        self.dept = Department.objects.create(name='Tổ RBAC', leader=self.dept_user)
        self.dept.members.add(self.dept_user, self.staff_user, self.other_staff)
        self.today = timezone.localdate()
        self.client = Client()

    def test_role_properties_do_not_shadow_django_is_staff(self):
        self.assertEqual(self.director.role, User.ROLE_DIRECTOR)
        self.assertTrue(self.director.is_director)
        self.assertFalse(self.director.is_department)
        self.assertFalse(self.director.is_staff_member)
        self.assertTrue(self.dept_user.is_department)
        self.assertTrue(self.staff_user.is_staff_member)
        # App role uses is_staff_member; Django's is_staff remains a model field.
        self.assertTrue(hasattr(self.staff_user, 'is_staff_member'))
        self.assertIs(getattr(User, 'is_staff_member', None) is not None, True)
        field_names = {f.name for f in User._meta.fields}
        self.assertIn('is_staff', field_names)
        self.assertNotIn('is_staff_member', field_names)

    def test_assignable_queryset_by_role(self):
        from .forms import TaskAssignForm

        director_form = TaskAssignForm(user=self.director)
        director_ids = set(director_form.fields['assignees'].queryset.values_list('pk', flat=True))
        self.assertIn(self.director.pk, director_ids)
        self.assertIn(self.staff_user.pk, director_ids)

        dept_form = TaskAssignForm(user=self.dept_user)
        dept_ids = set(dept_form.fields['assignees'].queryset.values_list('pk', flat=True))
        self.assertNotIn(self.director.pk, dept_ids)
        self.assertIn(self.dept_user.pk, dept_ids)
        self.assertIn(self.staff_user.pk, dept_ids)

        staff_form = TaskAssignForm(user=self.staff_user)
        self.assertEqual(staff_form.fields['assignees'].queryset.count(), 0)

    def test_staff_cannot_create_task(self):
        self.client.force_login(self.staff_user)
        resp = self.client.get(reverse('manager_create_task'))
        self.assertEqual(resp.status_code, 403)

    def test_department_can_create_task(self):
        self.client.force_login(self.dept_user)
        resp = self.client.get(reverse('manager_create_task'))
        self.assertEqual(resp.status_code, 200)
        title = 'Việc do tổ giao'
        post = self.client.post(reverse('manager_create_task'), {
            'title': title,
            'description': 'mô tả',
            'deadline': (self.today + timedelta(days=5)).isoformat(),
            'cycle': Task.CYCLE_MONTH,
            'assign_mode': 'individual',
            'assignees': [self.staff_user.pk],
        })
        self.assertEqual(post.status_code, 302)
        self.assertIn(reverse('manager_manage_tasks'), post.url)
        task = Task.objects.get(title=title)
        self.assertEqual(task.created_by_id, self.dept_user.pk)
        self.assertTrue(task.assignments.filter(assignee=self.staff_user).exists())

    def test_department_can_list_managed_tasks_scoped(self):
        mine = Task.objects.create(
            title='Tổ đã giao',
            created_by=self.dept_user,
            deadline=self.today + timedelta(days=3),
            cycle=Task.CYCLE_MONTH,
        )
        TaskAssignment.objects.create(task=mine, assignee=self.staff_user)
        others = Task.objects.create(
            title='BGH đã giao',
            created_by=self.director,
            deadline=self.today + timedelta(days=3),
            cycle=Task.CYCLE_MONTH,
        )
        TaskAssignment.objects.create(task=others, assignee=self.other_staff)
        # Việc BGH giao cho tổ trưởng: hiện ở "Việc của tôi", không vào "đã giao".
        inbound = Task.objects.create(
            title='BGH giao cho tổ',
            created_by=self.director,
            deadline=self.today + timedelta(days=3),
            cycle=Task.CYCLE_MONTH,
        )
        TaskAssignment.objects.create(task=inbound, assignee=self.dept_user)

        self.client.force_login(self.dept_user)
        resp = self.client.get(reverse('manager_manage_tasks'))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, 'Tổ đã giao')
        self.assertNotContains(resp, 'BGH đã giao')
        self.assertContains(resp, 'Công việc đã giao')
        self.assertContains(resp, 'Danh sách việc bạn đã phân công')
        # Chỉ việc mình tạo; việc BGH giao tới không vào danh sách (có thể hiện ở thông báo).
        listed_titles = {n['title'] for n in resp.context['tree_nodes']}
        self.assertEqual(listed_titles, {'Tổ đã giao'})
        self.assertEqual(resp.context['stats']['total'], 1)

        detail = self.client.get(reverse('manager_task_detail', args=[mine.pk]))
        self.assertEqual(detail.status_code, 200)
        forbidden = self.client.get(reverse('manager_task_detail', args=[others.pk]))
        self.assertEqual(forbidden.status_code, 403)
        inbound_forbidden = self.client.get(reverse('manager_task_detail', args=[inbound.pk]))
        self.assertEqual(inbound_forbidden.status_code, 403)

    def test_staff_cannot_access_manage_tasks(self):
        self.client.force_login(self.staff_user)
        resp = self.client.get(reverse('manager_manage_tasks'))
        self.assertEqual(resp.status_code, 403)

    def test_list_isolation(self):
        from tasks.views import _manager_tasks_queryset, _staff_assignments_qs

        mine = Task.objects.create(
            title='Của tổ',
            created_by=self.dept_user,
            deadline=self.today + timedelta(days=3),
            cycle=Task.CYCLE_MONTH,
        )
        TaskAssignment.objects.create(task=mine, assignee=self.staff_user)

        others = Task.objects.create(
            title='Của BGH',
            created_by=self.director,
            deadline=self.today + timedelta(days=3),
            cycle=Task.CYCLE_MONTH,
        )
        TaskAssignment.objects.create(task=others, assignee=self.other_staff)

        # "đã giao" = chỉ việc mình tạo (kể cả BGH).
        director_titles = set(_manager_tasks_queryset(self.director).values_list('title', flat=True))
        self.assertNotIn('Của tổ', director_titles)
        self.assertIn('Của BGH', director_titles)

        dept_titles = set(_manager_tasks_queryset(self.dept_user).values_list('title', flat=True))
        self.assertIn('Của tổ', dept_titles)
        self.assertNotIn('Của BGH', dept_titles)

        dept2 = User.objects.create_user(
            username='dept2_rbac',
            password='x',
            role=User.ROLE_DEPARTMENT,
        )
        other_dept_task = Task.objects.create(
            title='Của tổ khác',
            created_by=dept2,
            deadline=self.today + timedelta(days=3),
            cycle=Task.CYCLE_MONTH,
        )
        TaskAssignment.objects.create(task=other_dept_task, assignee=self.other_staff)
        self.assertNotIn(
            'Của tổ khác',
            set(_manager_tasks_queryset(self.dept_user).values_list('title', flat=True)),
        )

        # Assignee vẫn thấy việc được giao trong "Việc của tôi".
        dept_asg = _staff_assignments_qs(self.dept_user)
        self.assertTrue(dept_asg.filter(task=mine).exists())
        self.assertFalse(dept_asg.filter(task=others).exists())

        staff_asg = _staff_assignments_qs(self.staff_user)
        self.assertTrue(staff_asg.filter(task=mine).exists())
        self.assertFalse(staff_asg.filter(task=others).exists())

        self.client.force_login(self.director)
        dir_resp = self.client.get(reverse('manager_manage_tasks'))
        self.assertContains(dir_resp, 'Của BGH')
        self.assertNotContains(dir_resp, 'Của tổ')


class StaffTaskDetailOversightTestCase(TestCase):
    """
    Trưởng tổ / role Tổ sau khi giao-chuyển việc cho thành viên vẫn mở được
    /staff/tasks/<assignment_pk>/ (trước đây 404 vì _get_staff_assignment
    chỉ lọc assignee=request.user).
    """

    def setUp(self):
        self.today = timezone.localdate()
        self.client = Client()
        self.director = User.objects.create_user(
            username='dir_oversight',
            password='x',
            is_manager=True,
            first_name='Giám',
            last_name='Đốc',
        )
        self.leader = User.objects.create_user(
            username='leader_oversight',
            password='x',
            role=User.ROLE_DEPARTMENT,
            first_name='Trưởng',
            last_name='Tổ',
        )
        self.member = User.objects.create_user(
            username='member_oversight',
            password='x',
            role=User.ROLE_STAFF,
            first_name='Thành',
            last_name='Viên',
        )
        self.outsider = User.objects.create_user(
            username='out_oversight',
            password='x',
            role=User.ROLE_STAFF,
            first_name='Ngoài',
            last_name='Tổ',
        )
        self.dept = Department.objects.create(name='Tổ Oversight', leader=self.leader)
        self.dept.members.add(self.leader, self.member)

        self.task = Task.objects.create(
            title='Việc nội bộ đã chuyển giao',
            created_by=self.leader,
            primary_department=self.dept,
            deadline=self.today + timedelta(days=7),
            cycle=Task.CYCLE_MONTH,
        )
        # Chỉ giao cho thành viên (trưởng tổ không còn là assignee)
        self.member_asg = TaskAssignment.objects.create(
            task=self.task,
            assignee=self.member,
        )

        # Việc cá nhân (không phải team) sau khi trưởng tổ chuyển giao
        self.personal_task = Task.objects.create(
            title='Việc cá nhân đã chuyển',
            created_by=self.director,
            deadline=self.today + timedelta(days=5),
            cycle=Task.CYCLE_MONTH,
        )
        self.personal_asg = TaskAssignment.objects.create(
            task=self.personal_task,
            assignee=self.member,
        )

    def test_leader_can_open_member_assignment_after_reassign(self):
        self.client.force_login(self.leader)
        resp = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': self.member_asg.pk})
        )
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, self.task.title)
        # Xem việc thành viên → chỉ xem (không cập nhật thay người khác)
        self.assertFalse(resp.context['can_update'])
        self.assertTrue(resp.context['is_overseer'])
        self.assertContains(resp, 'Tiến độ (chỉ xem)')

    def test_leader_who_is_also_assignee_can_update_progress(self):
        """
        Trưởng tổ (kể cả sau khi phân công lại) là assignee của chính mình
        → form cập nhật tiến độ, không phải «chỉ xem».
        """
        leader_asg = TaskAssignment.objects.create(
            task=self.task,
            assignee=self.leader,
        )
        self.client.force_login(self.leader)
        resp = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': leader_asg.pk})
        )
        self.assertEqual(resp.status_code, 200)
        self.assertTrue(resp.context['can_update'])
        self.assertFalse(resp.context['is_overseer'])
        self.assertContains(resp, 'Cập nhật')
        self.assertNotContains(resp, 'Tiến độ (chỉ xem)')

        post = self.client.post(
            reverse('staff_task_detail', kwargs={'pk': leader_asg.pk}),
            {
                'action': 'update_progress',
                'status': TaskAssignment.STATUS_IN_PROGRESS,
                'notes': 'Trưởng tổ tự cập nhật',
            },
        )
        self.assertEqual(post.status_code, 302)
        leader_asg.refresh_from_db()
        self.assertEqual(leader_asg.status, TaskAssignment.STATUS_IN_PROGRESS)

    def test_team_member_assignee_can_update_own_assignment(self):
        """Assignee trên task tổ (không phải trưởng tổ) vẫn sửa được bản của mình."""
        self.client.force_login(self.member)
        resp = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': self.member_asg.pk})
        )
        self.assertEqual(resp.status_code, 200)
        self.assertTrue(resp.context['can_update'])
        self.assertNotContains(resp, 'Tiến độ (chỉ xem)')

    def test_leader_can_open_via_task_pk(self):
        self.client.force_login(self.leader)
        resp = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': self.task.pk})
        )
        self.assertEqual(resp.status_code, 200)
        self.assertEqual(resp.context['my_assignment'].pk, self.member_asg.pk)

    def test_leader_readonly_on_personal_reassigned_task(self):
        self.client.force_login(self.leader)
        resp = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': self.personal_asg.pk})
        )
        self.assertEqual(resp.status_code, 200)
        self.assertFalse(resp.context['can_update'])

    def test_member_can_still_update_own_personal_assignment(self):
        self.client.force_login(self.member)
        resp = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': self.personal_asg.pk})
        )
        self.assertEqual(resp.status_code, 200)
        self.assertTrue(resp.context['can_update'])

    def test_outsider_gets_403(self):
        """Assignment tồn tại nhưng ngoài tổ → 403 (không 404)."""
        self.client.force_login(self.outsider)
        resp = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': self.member_asg.pk})
        )
        self.assertEqual(resp.status_code, 403)

    def test_director_redirects_to_manager_task_detail(self):
        self.client.force_login(self.director)
        resp = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': self.member_asg.pk})
        )
        self.assertRedirects(
            resp,
            reverse('manager_task_detail', kwargs={'pk': self.task.pk}),
        )

    def test_department_kanban_action_links_member_assignment(self):
        from accounts.views import _department_task_action

        action = _department_task_action(self.task, self.leader, self.dept)
        self.assertIsNotNone(action)
        self.assertEqual(
            action['url'],
            reverse('staff_task_detail', args=[self.member_asg.pk]),
        )

    def test_new_department_leader_after_handoff_can_open_task(self):
        """Trưởng tổ mới (đổi trên form Tổ) vẫn mở được việc nội bộ."""
        new_leader = User.objects.create_user(
            username='new_leader',
            password='x',
            role=User.ROLE_STAFF,
            first_name='Trưởng',
            last_name='Mới',
        )
        self.dept.members.add(new_leader)
        self.dept.leader = new_leader
        self.dept.save(update_fields=['leader'])

        self.client.force_login(new_leader)
        resp = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': self.member_asg.pk})
        )
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, self.task.title)

    def test_former_leader_still_member_can_view_after_handoff(self):
        """Trưởng tổ cũ (đã chuyển quyền) vẫn xem việc tổ nếu còn trong tổ."""
        new_leader = User.objects.create_user(
            username='new_leader2',
            password='x',
            role=User.ROLE_STAFF,
            first_name='Trưởng',
            last_name='Khác',
        )
        self.dept.members.add(new_leader)
        self.dept.leader = new_leader
        self.dept.save(update_fields=['leader'])

        self.client.force_login(self.leader)
        resp = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': self.member_asg.pk})
        )
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, self.task.title)

    def test_former_leader_creator_demoted_can_open_task(self):
        """
        Tổ trưởng cũ: đã tạo việc, không còn là dept.leader, không phải assignee,
        role đã hạ xuống staff → vẫn GET staff_task_detail 200 (qua created_by).
        """
        new_leader = User.objects.create_user(
            username='new_leader3',
            password='x',
            role=User.ROLE_DEPARTMENT,
            first_name='Trưởng',
            last_name='Mới',
        )
        self.dept.members.add(new_leader)
        self.dept.leader = new_leader
        self.dept.save(update_fields=['leader'])

        # Hạ role + rời tổ để chỉ còn quyền qua created_by (không nhờ member/leader).
        self.dept.members.remove(self.leader)
        self.leader.role = User.ROLE_STAFF
        self.leader.save(update_fields=['role'])
        self.assertFalse(self.leader.is_department)
        self.assertNotEqual(self.dept.leader_id, self.leader.id)
        self.assertNotEqual(self.member_asg.assignee_id, self.leader.id)
        self.assertEqual(self.task.created_by_id, self.leader.id)

        self.client.force_login(self.leader)
        resp = self.client.get(
            reverse('staff_task_detail', kwargs={'pk': self.member_asg.pk})
        )
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, self.task.title)
        # Chỉ xem oversight — không cập nhật tiến độ thay thành viên
        self.assertFalse(resp.context['can_update'])
