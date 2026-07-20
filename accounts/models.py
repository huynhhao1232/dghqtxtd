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
        """Số thành viên còn đăng nhập được (ACTIVE + ON_LEAVE)."""
        return self.members.filter(is_active=True).exclude(
            account_status=User.ACCOUNT_INACTIVE,
        ).count()

    def assignable_members(self):
        """Thành viên có thể nhận việc mới (ACTIVE, không nghỉ phép / khóa)."""
        return User.bulk_assignable_queryset(self.members.all())

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
        if getattr(user, 'is_director', False) or getattr(user, 'is_manager', False):
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


class User(AbstractUser):
    """Custom user với phân quyền theo role (Ban Giám đốc / Tổ chuyên môn / GV)."""

    ROLE_DIRECTOR = 'director'
    ROLE_DEPARTMENT = 'department'
    ROLE_STAFF = 'staff'
    ROLE_CHOICES = [
        (ROLE_DIRECTOR, 'Ban Giám đốc'),
        (ROLE_DEPARTMENT, 'Tổ chuyên môn'),
        (ROLE_STAFF, 'Giáo viên / Nhân viên'),
    ]

    ACCOUNT_ACTIVE = 'ACTIVE'
    ACCOUNT_ON_LEAVE = 'ON_LEAVE'
    ACCOUNT_INACTIVE = 'INACTIVE'
    ACCOUNT_STATUS_CHOICES = [
        (ACCOUNT_ACTIVE, 'Đang hoạt động'),
        (ACCOUNT_ON_LEAVE, 'Nghỉ phép'),
        (ACCOUNT_INACTIVE, 'Ngưng hoạt động'),
    ]

    is_manager = models.BooleanField(
        default=False,
        verbose_name='Là lãnh đạo',
        help_text='Legacy: True khi role=director (portal quản lý). Không dùng làm role app.',
    )
    role = models.CharField(
        max_length=20,
        choices=ROLE_CHOICES,
        default=ROLE_STAFF,
        verbose_name='Vai trò',
        help_text='director=Ban Giám đốc, department=Tổ chuyên môn, staff=Giáo viên/NV.',
        db_index=True,
    )
    account_status = models.CharField(
        max_length=20,
        choices=ACCOUNT_STATUS_CHOICES,
        default=ACCOUNT_ACTIVE,
        db_index=True,
        verbose_name='Trạng thái tài khoản',
        help_text=(
            'ACTIVE=nhận việc bình thường; ON_LEAVE=đăng nhập được nhưng không nhận giao mới; '
            'INACTIVE=khóa đăng nhập (đồng bộ is_active=False).'
        ),
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

    def save(self, *args, **kwargs):
        # Bridge legacy is_manager ↔ Ban Giám đốc; keep flags consistent.
        if self.is_manager and self.role != self.ROLE_DEPARTMENT:
            self.role = self.ROLE_DIRECTOR
        self.is_manager = self.role == self.ROLE_DIRECTOR
        # Sync is_active với account_status (INACTIVE khóa login; ON_LEAVE vẫn đăng nhập được).
        if self.account_status == self.ACCOUNT_INACTIVE:
            self.is_active = False
        elif self.account_status in (self.ACCOUNT_ACTIVE, self.ACCOUNT_ON_LEAVE):
            self.is_active = True
        elif not self.is_active:
            self.account_status = self.ACCOUNT_INACTIVE
        super().save(*args, **kwargs)

    @property
    def role_label(self):
        return dict(self.ROLE_CHOICES).get(self.role, self.role)

    @property
    def account_status_label(self):
        return dict(self.ACCOUNT_STATUS_CHOICES).get(self.account_status, self.account_status)

    # Naming: AbstractUser already has boolean is_staff (Django admin access).
    # Use is_director / is_department / is_staff_member for app RBAC — never shadow is_staff.
    @property
    def is_director(self):
        return self.role == self.ROLE_DIRECTOR

    @property
    def is_department(self):
        return self.role == self.ROLE_DEPARTMENT

    @property
    def is_staff_member(self):
        """App role Giáo viên/NV (role=='staff'). Not Django's is_staff."""
        return self.role == self.ROLE_STAFF

    @property
    def is_on_leave(self):
        return self.account_status == self.ACCOUNT_ON_LEAVE

    @property
    def is_bulk_assignable(self):
        """True nếu được đưa vào giao đồng loạt / mở rộng thành viên."""
        return (
            self.is_active
            and self.account_status == self.ACCOUNT_ACTIVE
            and not self.is_director
        )

    def can_assign_tasks(self):
        return self.role in (self.ROLE_DIRECTOR, self.ROLE_DEPARTMENT)

    @classmethod
    def bulk_assignable_queryset(cls, qs=None):
        """Lọc user ACTIVE (không nghỉ phép / khóa) — dùng cho bulk assign."""
        base = qs if qs is not None else cls.objects.all()
        return (
            base.filter(is_active=True, account_status=cls.ACCOUNT_ACTIVE)
            .exclude(role=cls.ROLE_DIRECTOR)
            .order_by('last_name', 'first_name', 'username')
        )

    @classmethod
    def assignable_queryset_for(cls, actor):
        """Người có thể được giao việc theo role của actor (loại ON_LEAVE / INACTIVE)."""
        base = cls.bulk_assignable_queryset()
        if actor is None or not getattr(actor, 'is_authenticated', False):
            return cls.objects.none()
        if actor.is_director:
            return base
        if actor.is_department:
            return base.filter(role__in=[cls.ROLE_DEPARTMENT, cls.ROLE_STAFF])
        return cls.objects.none()

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
