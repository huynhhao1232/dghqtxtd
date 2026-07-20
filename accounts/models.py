from django.conf import settings
from django.contrib.auth.models import AbstractUser
from django.db import models


class Department(models.Model):
    """Tổ / Nhóm / Phòng ban — một viên chức có thể thuộc nhiều tổ (M2M)."""

    BADGE_COLORS = [
        ('blue', 'Xanh dương'),
        ('emerald', 'Xanh lá'),
        ('amber', 'Vàng'),
        ('rose', 'Hồng'),
        ('violet', 'Tím'),
        ('cyan', 'Xanh cyan'),
        ('orange', 'Cam'),
        ('slate', 'Xám'),
    ]

    name = models.CharField(max_length=150, unique=True, verbose_name='Tên tổ')
    description = models.TextField(blank=True, verbose_name='Mô tả')
    leader = models.ForeignKey(
        'User',
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='led_departments',
        verbose_name='Trưởng tổ',
    )
    members = models.ManyToManyField(
        settings.AUTH_USER_MODEL,
        related_name='my_departments',
        blank=True,
        verbose_name='Thành viên',
    )
    badge_color = models.CharField(
        max_length=20,
        choices=BADGE_COLORS,
        default='blue',
        verbose_name='Màu badge',
    )
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        verbose_name = 'Tổ / Nhóm'
        verbose_name_plural = 'Tổ / Nhóm'
        ordering = ['name']

    def __str__(self):
        return self.name

    @property
    def member_count(self):
        return self.members.filter(is_active=True).count()

    @property
    def badge_classes(self):
        mapping = {
            'blue': 'bg-blue-50 text-blue-700 ring-blue-600/20',
            'emerald': 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            'amber': 'bg-amber-50 text-amber-800 ring-amber-600/20',
            'rose': 'bg-rose-50 text-rose-700 ring-rose-600/20',
            'violet': 'bg-violet-50 text-violet-700 ring-violet-600/20',
            'cyan': 'bg-cyan-50 text-cyan-700 ring-cyan-600/20',
            'orange': 'bg-orange-50 text-orange-700 ring-orange-600/20',
            'slate': 'bg-slate-100 text-slate-700 ring-slate-500/20',
        }
        return mapping.get(self.badge_color, mapping['blue'])

    def user_can_access(self, user):
        """Thành viên nhóm, Trưởng tổ, hoặc Lãnh đạo cấp cao."""
        if not user or not user.is_authenticated:
            return False
        if getattr(user, 'is_manager', False):
            return True
        if self.leader_id == user.id:
            return True
        return self.members.filter(pk=user.pk).exists()

    def ensure_leader_is_member(self):
        if self.leader_id:
            self.members.add(self.leader)


class GroupPost(models.Model):
    """Bảng tin tương tác nội bộ trong Tổ/Nhóm."""

    department = models.ForeignKey(
        Department,
        on_delete=models.CASCADE,
        related_name='posts',
        verbose_name='Tổ/Nhóm',
    )
    author = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.CASCADE,
        related_name='group_posts',
        verbose_name='Người đăng',
    )
    content = models.TextField(verbose_name='Nội dung thảo luận')
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        verbose_name = 'Bài đăng nhóm'
        verbose_name_plural = 'Bài đăng nhóm'
        ordering = ['-created_at']

    def __str__(self):
        preview = (self.content or '')[:40]
        return f'{self.department}: {preview}'


class ChatMessage(models.Model):
    """Tin nhắn chat nhóm thời gian thực trong Tổ/Nhóm."""

    department = models.ForeignKey(
        Department,
        on_delete=models.CASCADE,
        related_name='chat_messages',
        verbose_name='Tổ/Nhóm',
    )
    user = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.CASCADE,
        related_name='chat_messages',
        verbose_name='Người gửi',
    )
    text = models.TextField(verbose_name='Nội dung')
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        verbose_name = 'Tin nhắn chat nhóm'
        verbose_name_plural = 'Tin nhắn chat nhóm'
        ordering = ['created_at']

    def __str__(self):
        preview = (self.text or '')[:40]
        return f'{self.department}: {preview}'

    def to_chat_dict(self):
        return {
            'id': self.pk,
            'user_id': self.user_id,
            'username': str(self.user),
            'text': self.text,
            'created_at': self.created_at.isoformat(),
        }


class User(AbstractUser):
    """Custom user với phân quyền Lãnh đạo / Nhân viên."""

    is_manager = models.BooleanField(
        default=False,
        verbose_name='Là lãnh đạo',
        help_text='Đánh dấu nếu tài khoản thuộc nhóm Lãnh đạo/Quản lý.',
    )
    position = models.CharField(
        max_length=150,
        blank=True,
        verbose_name='Chức vụ',
    )
    phone = models.CharField(
        max_length=20,
        blank=True,
        verbose_name='Số điện thoại',
    )
    avatar = models.ImageField(
        upload_to='avatars/',
        blank=True,
        null=True,
        verbose_name='Ảnh đại diện',
    )

    class Meta:
        verbose_name = 'Người dùng'
        verbose_name_plural = 'Người dùng'
        ordering = ['first_name', 'last_name', 'username']

    def __str__(self):
        from .vietnamese import user_display_full_name
        return user_display_full_name(self)

    @property
    def role_label(self):
        return 'Quản lý' if self.is_manager else 'Nhân viên'

    @property
    def avatar_url(self):
        if self.avatar:
            return self.avatar.url
        name = str(self)
        return f'https://ui-avatars.com/api/?name={name}&background=1d4ed8&color=fff'

    @property
    def department_name(self):
        names = list(self.my_departments.values_list('name', flat=True)[:3])
        if not names:
            return '—'
        return ', '.join(names)

    @property
    def primary_department(self):
        """Tổ đầu tiên (tương thích hiển thị cũ)."""
        return self.my_departments.order_by('name').first()

    @property
    def full_name_vn(self):
        from .vietnamese import user_display_full_name
        return user_display_full_name(self)

    @property
    def is_group_leader(self):
        """True nếu user là Trưởng tổ của ít nhất một Tổ/Nhóm."""
        return self.led_departments.exists()

    def leads_department(self, department):
        if not department:
            return False
        return department.leader_id == self.id
