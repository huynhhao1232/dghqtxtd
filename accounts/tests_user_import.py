from io import BytesIO

from django.contrib.auth import get_user_model
from django.test import Client, TestCase
from django.urls import reverse
from openpyxl import Workbook

from .user_import import import_users_from_rows, parse_role, read_import_file

User = get_user_model()


class UserImportTestCase(TestCase):
    def test_parse_role_vietnamese(self):
        self.assertEqual(parse_role('Giáo viên / Nhân viên'), User.ROLE_STAFF)
        self.assertEqual(parse_role('Tổ chuyên môn'), User.ROLE_DEPARTMENT)
        self.assertEqual(parse_role('Ban Giám đốc'), User.ROLE_DIRECTOR)

    def test_import_users_from_rows_creates_accounts(self):
        rows = [
            {
                '_line': 2,
                'full_name': 'Nguyễn Văn A',
                'username': 'nvana',
                'password': 'MatKhau@123',
                'role': 'staff',
                'position': 'Giáo viên',
            },
            {
                '_line': 3,
                'full_name': 'Trần Thị B',
                'username': 'ttb',
                'password': 'MatKhau@123',
                'role': 'Tổ chuyên môn',
                'position': 'Trưởng tổ',
            },
        ]
        outcome = import_users_from_rows(rows)
        self.assertEqual(outcome.created, 2)
        user_a = User.objects.get(username='nvana')
        self.assertEqual(user_a.first_name, 'Nguyễn Văn A')
        self.assertEqual(user_a.position, 'Giáo viên')
        self.assertEqual(user_a.role, User.ROLE_STAFF)
        user_b = User.objects.get(username='ttb')
        self.assertEqual(user_b.role, User.ROLE_DEPARTMENT)

    def test_import_skips_duplicate_username(self):
        User.objects.create_user(username='dup', password='MatKhau@123', first_name='Cũ')
        outcome = import_users_from_rows([
            {
                '_line': 2,
                'full_name': 'Mới',
                'username': 'dup',
                'password': 'MatKhau@123',
                'role': 'staff',
                'position': '',
            },
        ])
        self.assertEqual(outcome.created, 0)
        self.assertEqual(outcome.skipped, 1)

    def test_read_import_file_xlsx(self):
        wb = Workbook()
        ws = wb.active
        ws.append(['Họ và tên', 'Username', 'Mật khẩu', 'Vai trò', 'Chức vụ'])
        ws.append(['Test User', 'testuser', 'MatKhau@123', 'staff', 'GV'])
        buf = BytesIO()
        wb.save(buf)
        buf.seek(0)
        buf.name = 'users.xlsx'
        rows = read_import_file(buf)
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]['username'], 'testuser')

    def test_manager_staff_import_template_download(self):
        director = User.objects.create_user(
            username='bgd',
            password='MatKhau@123',
            role=User.ROLE_DIRECTOR,
            is_manager=True,
        )
        client = Client()
        client.login(username='bgd', password='MatKhau@123')
        resp = client.get(reverse('manager_staff_import_template'))
        self.assertEqual(resp.status_code, 200)
        self.assertIn(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            resp['Content-Type'],
        )

    def test_manager_staff_import_post(self):
        director = User.objects.create_user(
            username='bgd2',
            password='MatKhau@123',
            role=User.ROLE_DIRECTOR,
            is_manager=True,
        )
        wb = Workbook()
        ws = wb.active
        ws.append(['Họ và tên', 'Username', 'Mật khẩu', 'Vai trò', 'Chức vụ'])
        ws.append(['Import One', 'import1', 'MatKhau@123', 'Giáo viên / Nhân viên', 'GV'])
        buf = BytesIO()
        wb.save(buf)
        buf.seek(0)
        buf.name = 'import.xlsx'

        client = Client()
        client.login(username='bgd2', password='MatKhau@123')
        resp = client.post(
            reverse('manager_staff'),
            {'action': 'import', 'file': buf},
        )
        self.assertEqual(resp.status_code, 302)
        self.assertTrue(User.objects.filter(username='import1').exists())
