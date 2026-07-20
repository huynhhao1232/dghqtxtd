from django.contrib.auth import get_user_model
from django.test import Client, TestCase
from django.urls import reverse

from .forms import StaffEditForm

User = get_user_model()


class StaffEditRoleTestCase(TestCase):
    def setUp(self):
        self.director = User.objects.create_user(
            username='director1',
            password='pass12345',
            role=User.ROLE_DIRECTOR,
            is_manager=True,
            first_name='Giám đốc',
        )
        self.director2 = User.objects.create_user(
            username='director2',
            password='pass12345',
            role=User.ROLE_DIRECTOR,
            is_manager=True,
            first_name='Phó GĐ',
        )
        self.dept_user = User.objects.create_user(
            username='totruong',
            password='pass12345',
            role=User.ROLE_DEPARTMENT,
            first_name='Trưởng tổ',
        )
        self.staff_user = User.objects.create_user(
            username='giaovien',
            password='pass12345',
            role=User.ROLE_STAFF,
            first_name='Giáo viên',
        )
        self.client = Client()

    def test_edit_form_changes_staff_to_department_and_syncs_is_manager(self):
        form = StaffEditForm(
            data={
                'role': User.ROLE_DEPARTMENT,
                'full_name': 'Giáo viên A',
                'email': '',
                'phone': '',
                'position': 'GV',
                'account_status': User.ACCOUNT_ACTIVE,
            },
            instance=self.staff_user,
            actor=self.director,
        )
        self.assertTrue(form.is_valid(), form.errors)
        user = form.save()
        user.refresh_from_db()
        self.assertEqual(user.role, User.ROLE_DEPARTMENT)
        self.assertFalse(user.is_manager)

    def test_edit_form_promotes_to_director_sets_is_manager(self):
        form = StaffEditForm(
            data={
                'role': User.ROLE_DIRECTOR,
                'full_name': 'Trưởng tổ',
                'email': '',
                'phone': '',
                'position': '',
                'account_status': User.ACCOUNT_ACTIVE,
            },
            instance=self.dept_user,
            actor=self.director,
        )
        self.assertTrue(form.is_valid(), form.errors)
        user = form.save()
        user.refresh_from_db()
        self.assertEqual(user.role, User.ROLE_DIRECTOR)
        self.assertTrue(user.is_manager)

    def test_cannot_demote_self_from_director(self):
        form = StaffEditForm(
            data={
                'role': User.ROLE_STAFF,
                'full_name': 'Giám đốc',
                'email': '',
                'phone': '',
                'position': '',
                'account_status': User.ACCOUNT_ACTIVE,
            },
            instance=self.director,
            actor=self.director,
        )
        self.assertFalse(form.is_valid())
        self.assertIn('role', form.errors)

    def test_cannot_demote_last_active_director(self):
        self.director2.account_status = User.ACCOUNT_INACTIVE
        self.director2.save()
        form = StaffEditForm(
            data={
                'role': User.ROLE_DEPARTMENT,
                'full_name': 'Giám đốc',
                'email': '',
                'phone': '',
                'position': '',
                'account_status': User.ACCOUNT_ACTIVE,
            },
            instance=self.director,
            actor=self.director2,
        )
        self.assertFalse(form.is_valid())
        self.assertIn('role', form.errors)

    def test_manager_staff_edit_post_updates_role(self):
        self.client.login(username='director1', password='pass12345')
        url = reverse('manager_staff')
        resp = self.client.post(
            url,
            {
                'action': 'edit',
                'staff_id': self.staff_user.pk,
                'role': User.ROLE_DEPARTMENT,
                'full_name': 'Giáo viên A',
                'email': 'gv@example.com',
                'phone': '',
                'position': 'GV',
                'account_status': User.ACCOUNT_ACTIVE,
            },
        )
        self.assertEqual(resp.status_code, 302)
        self.staff_user.refresh_from_db()
        self.assertEqual(self.staff_user.role, User.ROLE_DEPARTMENT)
        self.assertFalse(self.staff_user.is_manager)

    def test_edit_page_includes_role_field(self):
        self.client.login(username='director1', password='pass12345')
        resp = self.client.get(
            reverse('manager_staff'),
            {'action': 'edit', 'staff_id': self.dept_user.pk},
        )
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, 'name="role"')
        self.assertContains(resp, 'Tổ chuyên môn')
        self.assertContains(resp, 'name="account_status"')
