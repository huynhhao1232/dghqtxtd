import re
import uuid

from django.conf import settings
from django.core.exceptions import ValidationError
from django.db import models
from django.utils import timezone


# Tiêu đề giao đồng loạt / thành viên: "Công việc gốc - [Họ tên]"
MEMBER_TITLE_SUFFIX_RE = re.compile(r'^(.+?) - \[.+\]$')


class EvaluationResult:
    """Ba mức đánh giá dùng chung cho bản nộp và đánh giá cá nhân."""

    DAT = 'DAT'
    CHO_LAM_LAI = 'CHO_LAM_LAI'
    TRE_BI_TRU_DIEM = 'TRE_BI_TRU_DIEM'
    CHOICES = [
        (DAT, 'Đạt'),
        (CHO_LAM_LAI, 'Chưa đạt - Yêu cầu làm lại'),
        (TRE_BI_TRU_DIEM, 'Chưa đạt - Yêu cầu làm lại và bị -1'),
    ]
    LABELS = dict(CHOICES)


class Task(models.Model):
    CYCLE_MONTH = 'month'
    CYCLE_QUARTER = 'quarter'
    CYCLE_SEMESTER = 'semester'
    CYCLE_CHOICES = [
        (CYCLE_MONTH, 'Tháng'),
        (CYCLE_QUARTER, 'Quý'),
        (CYCLE_SEMESTER, 'Kỳ'),
    ]

    title = models.CharField(max_length=255, verbose_name='Tên công việc')
    description = models.TextField(blank=True, verbose_name='Mô tả')
    created_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.CASCADE,
        related_name='created_tasks',
        verbose_name='Người giao',
    )
    deadline = models.DateField(verbose_name='Thời hạn')
    cycle = models.CharField(
        max_length=20,
        choices=CYCLE_CHOICES,
        default=CYCLE_MONTH,
        verbose_name='Chu kỳ',
    )
    primary_department = models.ForeignKey(
        'accounts.Department',
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='primary_tasks',
        verbose_name='Đơn vị Chủ trì',
        help_text='Tổ/Nhóm chủ trì — chịu trách nhiệm chốt tiến độ gửi Lãnh đạo.',
    )
    coordinating_departments = models.ManyToManyField(
        'accounts.Department',
        blank=True,
        related_name='coordinating_tasks',
        verbose_name='Đơn vị Phối hợp',
    )
    delegated_updater = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='delegated_update_tasks',
        verbose_name='Người được ủy quyền cập nhật',
        help_text='Thành viên tổ Chủ trì được ủy quyền cập nhật tiến độ / upload minh chứng tổng hợp.',
    )
    parent_task = models.ForeignKey(
        'self',
        on_delete=models.CASCADE,
        null=True,
        blank=True,
        related_name='subtasks',
        verbose_name='Nhiệm vụ gốc',
        help_text='Nếu có giá trị thì đây là nhiệm vụ con (sub-task).',
    )
    scope_department = models.ForeignKey(
        'accounts.Department',
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='scoped_subtasks',
        verbose_name='Tổ sở hữu nhiệm vụ con',
        help_text=(
            'Chỉ dùng cho nhiệm vụ con của luồng giao đồng loạt, '
            'để dữ liệu các tổ độc lập.'
        ),
    )
    batch_key = models.UUIDField(
        null=True,
        blank=True,
        db_index=True,
        verbose_name='Nhóm giao đồng loạt',
        help_text=(
            'Các task cá nhân cùng một lần “Mỗi thành viên tự thực hiện” '
            'chia sẻ cùng batch_key để hiển thị dạng cây.'
        ),
    )
    source_department = models.ForeignKey(
        'accounts.Department',
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='batch_member_tasks',
        verbose_name='Tổ nguồn (giao đồng loạt)',
        help_text='Tổ/Nhóm mà thành viên được giao trong lần giao đồng loạt.',
    )
    is_subtask = models.BooleanField(default=False, verbose_name='Là nhiệm vụ con')
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)
    assignees = models.ManyToManyField(
        settings.AUTH_USER_MODEL,
        through='TaskAssignment',
        through_fields=('task', 'assignee'),
        related_name='assigned_tasks',
        verbose_name='Người nhận',
    )

    class Meta:
        verbose_name = 'Công việc'
        verbose_name_plural = 'Công việc'
        ordering = ['-created_at']

    def __str__(self):
        return self.title

    @staticmethod
    def strip_member_title_suffix(title):
        """Trả về tiêu đề gốc nếu title dạng '... - [Họ tên]', ngược lại None."""
        match = MEMBER_TITLE_SUFFIX_RE.match((title or '').strip())
        if not match:
            return None
        return match.group(1).strip() or None

    @property
    def batch_display_title(self):
        """Tiêu đề nhóm (bỏ hậu tố thành viên) hoặc title gốc."""
        return self.strip_member_title_suffix(self.title) or self.title

    @classmethod
    def new_batch_key(cls):
        return uuid.uuid4()

    def save(self, *args, **kwargs):
        self.is_subtask = self.parent_task_id is not None
        super().save(*args, **kwargs)

    def clean(self):
        super().clean()
        if self.parent_task_id:
            if self.pk and self.parent_task_id == self.pk:
                raise ValidationError({'parent_task': 'Nhiệm vụ không thể là cha của chính nó.'})
            parent = self.parent_task
            if parent and parent.parent_task_id:
                raise ValidationError(
                    {'parent_task': 'Chỉ hỗ trợ phân rã một cấp — không tạo sub-task của sub-task.'}
                )
            # Ngăn vòng lặp nếu sau này mở rộng nhiều cấp
            seen = set()
            cursor = parent
            while cursor is not None:
                if self.pk and cursor.pk == self.pk:
                    raise ValidationError({'parent_task': 'Phát hiện vòng lặp cây nhiệm vụ.'})
                if cursor.pk in seen:
                    break
                seen.add(cursor.pk)
                cursor = cursor.parent_task
            if parent and self.deadline and self.deadline > parent.deadline:
                raise ValidationError(
                    {'deadline': 'Thời hạn nhiệm vụ con không được vượt quá nhiệm vụ gốc.'}
                )
            if (
                self.scope_department_id
                and parent
                and parent.pk
                and not parent.assignments.filter(
                    assignee_department_id=self.scope_department_id
                ).exists()
            ):
                raise ValidationError(
                    {
                        'scope_department': (
                            'Tổ sở hữu phải có bản phân công trên nhiệm vụ gốc.'
                        )
                    }
                )
        elif self.scope_department_id:
            raise ValidationError(
                {
                    'scope_department': (
                        'Chỉ nhiệm vụ con mới được gắn phạm vi Tổ/Nhóm.'
                    )
                }
            )

    @property
    def is_overdue(self):
        return self.deadline < timezone.localdate()

    @property
    def is_team_task(self):
        return self.primary_department_id is not None and not self.is_subtask

    @property
    def is_batch_department_task(self):
        if self.is_subtask or self.primary_department_id:
            return False
        return self.assignments.filter(
            assignee_department__isnull=False
        ).exists()

    def get_batch_department_for(self, user):
        """Tổ batch mà user đang đại diện với vai trò Trưởng tổ."""
        if not user or self.is_subtask or self.primary_department_id:
            return None
        assignment = (
            self.assignments.select_related('assignee_department')
            .filter(assignee_department__leader=user)
            .first()
        )
        return assignment.assignee_department if assignment else None

    def is_primary_leader(self, user):
        if not user or not self.primary_department_id:
            return False
        if self.primary_department.leader_id == user.id:
            return True
        # Tổ Chủ trì chưa gắn Trưởng tổ: assignee canonical được quyền điều phối
        # (thêm thành viên, phân rã, chốt tiến độ) — tránh mất UI khi leader_id trống.
        if (
            self.is_team_task
            and self.primary_department.leader_id is None
            and self.assignments.filter(
                assignee_id=user.id,
                handover_status=TaskAssignment.HANDOVER_ACTIVE,
            ).exists()
        ):
            canonical = self.get_canonical_assignment()
            return bool(canonical and canonical.assignee_id == user.id)
        return False

    def is_coordinating_leader(self, user):
        if not user or not self.is_team_task:
            return False
        if self.coordinating_departments.filter(leader_id=user.id).exists():
            return True
        # Tổ phối hợp chưa gắn Trưởng tổ: assignee thuộc tổ đó được điều phối phạm vi tổ mình.
        for dept in self.coordinating_departments.all():
            if dept.leader_id is not None:
                continue
            if self.assignments.filter(assignee_id=user.id).exists() and (
                dept.members.filter(pk=user.id).exists()
            ):
                return True
        return False

    def get_coordinating_department_for(self, user):
        """Tổ phối hợp mà user đang làm Trưởng tổ (trên task này)."""
        if not user:
            return None
        found = self.coordinating_departments.filter(leader_id=user.id).first()
        if found:
            return found
        for dept in self.coordinating_departments.all():
            if dept.leader_id is not None:
                continue
            if self.assignments.filter(assignee_id=user.id).exists() and (
                dept.members.filter(pk=user.id).exists()
            ):
                return dept
        return None

    def is_department_leader(self, user):
        """True nếu là Trưởng tổ Chủ trì hoặc Trưởng tổ Phối hợp."""
        return self.is_primary_leader(user) or self.is_coordinating_leader(user)

    def can_manage_task_members(self, user):
        """
        Ai được thêm/gỡ thành viên tham gia:
        - Trưởng tổ Chủ trì / Phối hợp (hoặc assignee điều phối khi tổ chưa có Trưởng tổ)
        - Trưởng tổ bản giao đồng loạt
        - Người tạo task (không phải Ban Giám đốc — BGĐ dùng trang quản lý)
        """
        if not user or not getattr(user, 'is_authenticated', False):
            return False
        if self.is_subtask:
            return False
        if self.is_department_leader(user):
            return True
        if self.get_batch_department_for(user) is not None:
            return True
        if (
            self.created_by_id == user.id
            and not getattr(user, 'is_director', False)
            and (self.is_team_task or self.is_batch_department_task)
        ):
            return True
        return False

    def can_manage_subtasks(self, user):
        """Trưởng tổ Chủ trì hoặc Trưởng tổ của assignment batch được phân rã."""
        if not user or not user.is_authenticated:
            return False
        if self.is_subtask:
            return False
        if self.is_team_task:
            return self.is_primary_leader(user)
        return self.get_batch_department_for(user) is not None

    def can_update_progress(self, user):
        """
        Quyền cập nhật minh chứng tổng hợp / chuyển Chờ duyệt.
        Sub-task: mỗi assignee cập nhật assignment của mình.
        Team task: Trưởng tổ Chủ trì hoặc delegated_updater.
        """
        if not user or not user.is_authenticated:
            return False
        if self.is_subtask or not self.is_team_task:
            return True
        if self.is_primary_leader(user):
            return True
        return self.delegated_updater_id == user.id

    def can_submit_coordinating_proof(self, user):
        """Trưởng tổ phối hợp nộp minh chứng cho đơn vị chủ trì (không đổi status task)."""
        return self.is_coordinating_leader(user)

    def get_canonical_assignment(self):
        """Assignment ACTIVE của Trưởng tổ Chủ trì — nộp lên Lãnh đạo."""
        if not self.is_team_task:
            return None
        active = self.assignments.filter(handover_status=TaskAssignment.HANDOVER_ACTIVE)
        leader_id = self.primary_department.leader_id if self.primary_department else None
        if leader_id:
            found = active.filter(assignee_id=leader_id).first()
            if found:
                return found
        return active.order_by('pk').first()

    def sync_team_assignment_status(self, canonical):
        """Đồng bộ trạng thái / kết quả đánh giá cho mọi assignment trên task tổ."""
        if not canonical or not self.is_team_task:
            return
        self.assignments.exclude(pk=canonical.pk).update(
            status=canonical.status,
            submitted_at=canonical.submitted_at,
            evaluation_result=canonical.evaluation_result,
            penalty_score=canonical.penalty_score,
            manager_comment=canonical.manager_comment,
            reviewed_at=canonical.reviewed_at,
        )

    def participations_for_leader(self, user):
        """TaskParticipation ACTIVE thuộc phạm vi tổ mà trưởng tổ đang quản lý."""
        qs = self.participations.filter(
            handover_status=TaskParticipation.HANDOVER_ACTIVE,
        ).select_related('user', 'department')
        if self.is_primary_leader(user):
            return qs.filter(role=TaskParticipation.ROLE_LEAD)
        coord_dept = self.get_coordinating_department_for(user)
        if coord_dept:
            return qs.filter(role=TaskParticipation.ROLE_COORD, department=coord_dept)
        batch_dept = self.get_batch_department_for(user)
        if batch_dept:
            return qs.filter(department=batch_dept)
        return qs.none()

    def participations_for_internal_evaluation(self, user):
        """Chỉ Trưởng tổ Chủ trì đánh giá tất cả cá nhân tham gia sau nghiệm thu."""
        qs = self.participations.filter(
            handover_status=TaskParticipation.HANDOVER_ACTIVE,
        ).select_related('user', 'department')
        if self.is_primary_leader(user):
            return qs.all()
        return qs.none()

    def needs_internal_evaluation(self, user):
        """
        Sau khi Lãnh đạo nghiệm thu, Trưởng tổ Chủ trì đánh giá từng cá nhân.
        """
        if self.is_subtask:
            return False
        canonical = self.get_canonical_assignment()
        if not canonical or canonical.status != TaskAssignment.STATUS_COMPLETED:
            return False
        parts = self.participations_for_internal_evaluation(user)
        return parts.exists() and parts.filter(evaluation=TaskParticipation.EVAL_NONE).exists()

    def get_effective_status(self):
        """
        Trạng thái hiệu lực của task:
        - Team: theo assignment canonical
        - Sub-task / cá nhân: ưu tiên pending > in_progress/redo > todo > completed (all)
        """
        if self.is_team_task:
            canonical = self.get_canonical_assignment()
            return canonical.status if canonical else TaskAssignment.STATUS_TODO

        statuses = list(
            self.assignments.filter(
                handover_status=TaskAssignment.HANDOVER_ACTIVE,
            ).values_list('status', flat=True)
        )
        if not statuses:
            return TaskAssignment.STATUS_TODO
        if TaskAssignment.STATUS_PENDING in statuses:
            return TaskAssignment.STATUS_PENDING
        if TaskAssignment.STATUS_REDO in statuses or TaskAssignment.STATUS_IN_PROGRESS in statuses:
            return TaskAssignment.STATUS_IN_PROGRESS
        if all(s == TaskAssignment.STATUS_COMPLETED for s in statuses):
            return TaskAssignment.STATUS_COMPLETED
        if all(s == TaskAssignment.STATUS_TODO for s in statuses):
            return TaskAssignment.STATUS_TODO
        return TaskAssignment.STATUS_IN_PROGRESS

    def is_effectively_completed(self):
        return self.get_effective_status() == TaskAssignment.STATUS_COMPLETED

    def subtask_progress(self, department=None):
        """(completed_count, total_count, percent) cho nhiệm vụ gốc."""
        subs = list(self.subtasks.all())
        if department is not None:
            subs = [
                sub for sub in subs
                if sub.scope_department_id == department.pk
            ]
        total = len(subs)
        if not total:
            return 0, 0, 0
        done = sum(1 for s in subs if s.is_effectively_completed())
        pct = int(round((done / total) * 100))
        return done, total, pct

    def all_subtasks_completed(self, department=None):
        subs = self.subtasks.all()
        if department is not None:
            subs = subs.filter(scope_department=department)
        if not subs.exists():
            return False
        return all(s.is_effectively_completed() for s in subs)

class TaskAttachment(models.Model):
    """File đính kèm khi lãnh đạo giao việc (nhiều file / mỗi file giới hạn dung lượng)."""

    MAX_SIZE_MB = 5
    MAX_SIZE_BYTES = MAX_SIZE_MB * 1024 * 1024
    MAX_COUNT = 10

    task = models.ForeignKey(
        Task,
        on_delete=models.CASCADE,
        related_name='attachments',
        verbose_name='Công việc',
    )
    file = models.FileField(
        upload_to='task_attachments/%Y/%m/',
        verbose_name='File',
    )
    original_name = models.CharField(max_length=255, blank=True, verbose_name='Tên file gốc')
    file_size = models.PositiveIntegerField(default=0, verbose_name='Dung lượng (bytes)')
    uploaded_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        verbose_name = 'File đính kèm'
        verbose_name_plural = 'File đính kèm'
        ordering = ['uploaded_at']

    def __str__(self):
        return self.original_name or self.file.name

    @property
    def display_name(self):
        return self.original_name or self.file.name.split('/')[-1]

    @property
    def size_display(self):
        size = self.file_size or 0
        if size < 1024:
            return f'{size} B'
        if size < 1024 * 1024:
            return f'{size / 1024:.1f} KB'
        return f'{size / (1024 * 1024):.1f} MB'


class TaskParticipation(models.Model):
    """Thành viên tham gia công việc tổ (do Trưởng tổ thêm) và kết quả đánh giá nội bộ."""

    ROLE_LEAD = 'lead_member'
    ROLE_COORD = 'coord_member'
    ROLE_CHOICES = [
        (ROLE_LEAD, 'Thành viên tổ chủ trì'),
        (ROLE_COORD, 'Thành viên tổ phối hợp'),
    ]

    EVAL_NONE = 'none'
    EVAL_DAT = EvaluationResult.DAT
    EVAL_CHO_LAM_LAI = EvaluationResult.CHO_LAM_LAI
    EVAL_TRE_BI_TRU_DIEM = EvaluationResult.TRE_BI_TRU_DIEM
    # Alias tương thích ngược với template/code cũ (map sang 3 mức mới).
    EVAL_PASS = EVAL_DAT
    EVAL_FAIL = EVAL_CHO_LAM_LAI
    EVALUATION_CHOICES = [
        (EVAL_NONE, 'Chưa đánh giá'),
        *EvaluationResult.CHOICES,
    ]

    HANDOVER_ACTIVE = 'ACTIVE'
    HANDOVER_HANDED_OVER = 'HANDED_OVER'
    HANDOVER_STATUS_CHOICES = [
        (HANDOVER_ACTIVE, 'Đang phụ trách'),
        (HANDOVER_HANDED_OVER, 'Đã bàn giao'),
    ]

    task = models.ForeignKey(
        Task,
        on_delete=models.CASCADE,
        related_name='participations',
        verbose_name='Công việc',
    )
    user = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.CASCADE,
        related_name='task_participations',
        verbose_name='Thành viên',
    )
    department = models.ForeignKey(
        'accounts.Department',
        on_delete=models.CASCADE,
        related_name='task_participations',
        verbose_name='Thuộc tổ',
        null=True,
        blank=True,
    )
    role = models.CharField(
        max_length=20,
        choices=ROLE_CHOICES,
        default=ROLE_LEAD,
        verbose_name='Vai trò tham gia',
    )
    evaluation = models.CharField(
        max_length=20,
        choices=EVALUATION_CHOICES,
        default=EVAL_NONE,
        verbose_name='Đánh giá nội bộ',
    )
    penalty_score = models.PositiveSmallIntegerField(
        default=0,
        verbose_name='Điểm trừ thi đua',
        help_text='0 hoặc 1 — tự động ghi 1 khi chọn mức trừ điểm.',
    )
    extended_with_penalty = models.BooleanField(
        default=False,
        verbose_name='Đã gia hạn kèm trừ điểm',
        help_text='True khi người giao gia hạn quá hạn kèm trừ −1.',
    )
    overdue_penalty = models.PositiveSmallIntegerField(
        default=0,
        verbose_name='Điểm trừ do gia hạn quá hạn',
        help_text='0 hoặc 1 — ghi khi gia hạn kèm trừ điểm; không bị ghi đè khi đánh giá.',
    )
    added_at = models.DateTimeField(auto_now_add=True)
    evaluated_at = models.DateTimeField(null=True, blank=True)
    handover_status = models.CharField(
        max_length=20,
        choices=HANDOVER_STATUS_CHOICES,
        default=HANDOVER_ACTIVE,
        db_index=True,
        verbose_name='Trạng thái bàn giao',
    )
    handed_over_to = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='received_participations',
        verbose_name='Bàn giao cho',
    )

    class Meta:
        verbose_name = 'Thành viên tham gia'
        verbose_name_plural = 'Thành viên tham gia'
        unique_together = ('task', 'user')
        ordering = ['role', 'added_at']
        constraints = [
            models.CheckConstraint(
                condition=models.Q(penalty_score__in=[0, 1]),
                name='taskparticipation_penalty_0_or_1',
            ),
            models.CheckConstraint(
                condition=models.Q(overdue_penalty__in=[0, 1]),
                name='taskparticipation_overdue_penalty_0_or_1',
            ),
        ]

    def __str__(self):
        return f'{self.task.title} · {self.user}'

    @property
    def is_evaluated(self):
        return self.evaluation in (
            self.EVAL_DAT,
            self.EVAL_CHO_LAM_LAI,
            self.EVAL_TRE_BI_TRU_DIEM,
        )

    def apply_evaluation(self, result):
        """Ghi đè kết quả đánh giá cá nhân theo 3 mức."""
        if result not in EvaluationResult.LABELS:
            raise ValidationError('Kết quả đánh giá không hợp lệ.')
        self.evaluation = result
        self.penalty_score = 1 if result == EvaluationResult.TRE_BI_TRU_DIEM else 0
        self.evaluated_at = timezone.now()

    def apply_overdue_extend_penalty(self):
        """Ghi −1 do gia hạn quá hạn (idempotent — không cộng dồn)."""
        self.extended_with_penalty = True
        self.overdue_penalty = 1

    @property
    def total_penalty(self):
        return int(self.penalty_score or 0) + int(self.overdue_penalty or 0)


class CoordinatingProof(models.Model):
    """Minh chứng do Tổ phối hợp nộp cho Đơn vị chủ trì (không đổi status task)."""

    MAX_SIZE_MB = 5
    MAX_SIZE_BYTES = MAX_SIZE_MB * 1024 * 1024

    task = models.ForeignKey(
        Task,
        on_delete=models.CASCADE,
        related_name='coordinating_proofs',
        verbose_name='Công việc',
    )
    department = models.ForeignKey(
        'accounts.Department',
        on_delete=models.CASCADE,
        related_name='coordinating_proofs',
        verbose_name='Tổ phối hợp',
    )
    uploaded_by = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.CASCADE,
        related_name='uploaded_coordinating_proofs',
        verbose_name='Người nộp',
    )
    proof_file = models.FileField(
        upload_to='coord_proofs/%Y/%m/',
        verbose_name='File minh chứng',
    )
    notes = models.TextField(blank=True, verbose_name='Ghi chú')
    submitted_at = models.DateTimeField(auto_now=True)

    class Meta:
        verbose_name = 'Minh chứng phối hợp'
        verbose_name_plural = 'Minh chứng phối hợp'
        unique_together = ('task', 'department')
        ordering = ['-submitted_at']

    def __str__(self):
        return f'{self.task.title} ← {self.department.name}'

    @property
    def display_name(self):
        if not self.proof_file:
            return ''
        return self.proof_file.name.split('/')[-1]


class TaskAssignment(models.Model):
    STATUS_TODO = 'todo'
    STATUS_IN_PROGRESS = 'in_progress'
    STATUS_PENDING = 'pending_review'
    STATUS_COMPLETED = 'completed'
    STATUS_REDO = 'redo'
    STATUS_CHOICES = [
        (STATUS_TODO, 'Chưa làm'),
        (STATUS_IN_PROGRESS, 'Đang làm'),
        (STATUS_PENDING, 'Chờ duyệt'),
        (STATUS_COMPLETED, 'Hoàn thành'),
        (STATUS_REDO, 'Yêu cầu làm lại'),
    ]

    HANDOVER_ACTIVE = 'ACTIVE'
    HANDOVER_HANDED_OVER = 'HANDED_OVER'
    HANDOVER_STATUS_CHOICES = [
        (HANDOVER_ACTIVE, 'Đang phụ trách'),
        (HANDOVER_HANDED_OVER, 'Đã bàn giao'),
    ]

    EVAL_DAT = EvaluationResult.DAT
    EVAL_CHO_LAM_LAI = EvaluationResult.CHO_LAM_LAI
    EVAL_TRE_BI_TRU_DIEM = EvaluationResult.TRE_BI_TRU_DIEM
    EVALUATION_RESULT_CHOICES = EvaluationResult.CHOICES

    PROOF_MAX_SIZE_MB = 5
    PROOF_MAX_SIZE_BYTES = PROOF_MAX_SIZE_MB * 1024 * 1024

    task = models.ForeignKey(
        Task,
        on_delete=models.CASCADE,
        related_name='assignments',
    )
    assignee = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.CASCADE,
        related_name='assignments',
        null=True,
        blank=True,
        verbose_name='Người nhận (cá nhân)',
    )
    assignee_department = models.ForeignKey(
        'accounts.Department',
        on_delete=models.CASCADE,
        related_name='department_assignments',
        null=True,
        blank=True,
        verbose_name='Tổ/Nhóm nhận việc',
        help_text='Dùng cho giao đồng loạt — Trưởng tổ đại diện cập nhật/nộp minh chứng.',
    )
    status = models.CharField(
        max_length=20,
        choices=STATUS_CHOICES,
        default=STATUS_TODO,
        verbose_name='Trạng thái',
    )
    handover_status = models.CharField(
        max_length=20,
        choices=HANDOVER_STATUS_CHOICES,
        default=HANDOVER_ACTIVE,
        db_index=True,
        verbose_name='Trạng thái bàn giao',
        help_text='ACTIVE=đang phụ trách; HANDED_OVER=đã bàn giao (không còn nhận việc này).',
    )
    handed_over_to = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        related_name='received_assignments',
        verbose_name='Bàn giao cho',
    )
    notes = models.TextField(blank=True, verbose_name='Ghi chú')
    proof_file = models.FileField(
        upload_to='proofs/%Y/%m/',
        blank=True,
        null=True,
        verbose_name='File minh chứng',
    )
    evaluation_result = models.CharField(
        max_length=20,
        choices=EVALUATION_RESULT_CHOICES,
        null=True,
        blank=True,
        verbose_name='Kết quả đánh giá',
    )
    penalty_score = models.PositiveSmallIntegerField(
        default=0,
        verbose_name='Điểm trừ thi đua',
        help_text='0 hoặc 1 — tự động ghi 1 khi chọn mức trừ điểm.',
    )
    extended_with_penalty = models.BooleanField(
        default=False,
        verbose_name='Đã gia hạn kèm trừ điểm',
        help_text='True khi người giao gia hạn quá hạn kèm trừ −1.',
    )
    overdue_penalty = models.PositiveSmallIntegerField(
        default=0,
        verbose_name='Điểm trừ do gia hạn quá hạn',
        help_text='0 hoặc 1 — ghi khi gia hạn kèm trừ điểm; không bị ghi đè khi đánh giá.',
    )
    manager_comment = models.TextField(blank=True, verbose_name='Nhận xét lãnh đạo')
    submitted_at = models.DateTimeField(null=True, blank=True)
    reviewed_at = models.DateTimeField(null=True, blank=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        verbose_name = 'Phân công'
        verbose_name_plural = 'Phân công'
        ordering = ['-updated_at']
        constraints = [
            models.CheckConstraint(
                condition=(
                    models.Q(assignee__isnull=False, assignee_department__isnull=True)
                    | models.Q(assignee__isnull=True, assignee_department__isnull=False)
                ),
                name='taskassignment_assignee_xor_department',
            ),
            models.UniqueConstraint(
                fields=['task', 'assignee'],
                condition=models.Q(assignee__isnull=False),
                name='uniq_task_assignee_user',
            ),
            models.UniqueConstraint(
                fields=['task', 'assignee_department'],
                condition=models.Q(assignee_department__isnull=False),
                name='uniq_task_assignee_department',
            ),
            models.CheckConstraint(
                condition=models.Q(penalty_score__in=[0, 1]),
                name='taskassignment_penalty_0_or_1',
            ),
            models.CheckConstraint(
                condition=models.Q(overdue_penalty__in=[0, 1]),
                name='taskassignment_overdue_penalty_0_or_1',
            ),
        ]

    def __str__(self):
        return f'{self.task.title} → {self.target_display_name}'

    def clean(self):
        super().clean()
        has_user = self.assignee_id is not None
        has_dept = self.assignee_department_id is not None
        if has_user == has_dept:
            raise ValidationError(
                'Mỗi bản phân công phải gắn đúng một đối tượng: cá nhân hoặc Tổ/Nhóm.'
            )

    @property
    def is_department_target(self):
        return self.assignee_department_id is not None

    @property
    def acting_user(self):
        """User được phép thao tác trên bản phân công này."""
        if self.assignee_id:
            return self.assignee
        if self.assignee_department_id:
            return self.assignee_department.leader
        return None

    @property
    def target_display_name(self):
        if self.is_department_target and self.assignee_department_id:
            return self.assignee_department.name
        if self.assignee_id:
            return str(self.assignee)
        return '—'

    @property
    def target_avatar_url(self):
        user = self.acting_user
        if user:
            return user.avatar_url
        if self.is_department_target and self.assignee_department_id:
            name = self.assignee_department.name
            return f'https://ui-avatars.com/api/?name={name}&background=0f766e&color=fff'
        return 'https://ui-avatars.com/api/?name=?&background=64748b&color=fff'

    @property
    def proof_display_name(self):
        if not self.proof_file:
            return ''
        return self.proof_file.name.split('/')[-1]

    @property
    def is_overdue(self):
        if self.status in (self.STATUS_COMPLETED,):
            return False
        return self.task.deadline < timezone.localdate()

    @property
    def evaluation_result_label(self):
        if not self.evaluation_result:
            return '—'
        return EvaluationResult.LABELS.get(self.evaluation_result, self.evaluation_result)

    @property
    def total_penalty(self):
        """Tổng điểm trừ: đánh giá + gia hạn quá hạn (mỗi phần tối đa 1)."""
        return int(self.penalty_score or 0) + int(self.overdue_penalty or 0)

    def apply_overdue_extend_penalty(self):
        """Ghi −1 do gia hạn quá hạn (idempotent — không cộng dồn)."""
        self.extended_with_penalty = True
        self.overdue_penalty = 1

    def apply_review(self, evaluation_result, comment=''):
        """
        Ghi nhận kết quả đánh giá 3 mức (ghi đè kết quả cũ nếu duyệt lại).
        DAT → hoàn thành; hai mức chưa đạt → yêu cầu làm lại; mức đỏ → penalty=1.
        overdue_penalty (gia hạn) không bị ghi đè.
        """
        if evaluation_result not in EvaluationResult.LABELS:
            raise ValidationError('Kết quả đánh giá không hợp lệ.')
        self.evaluation_result = evaluation_result
        self.penalty_score = (
            1 if evaluation_result == EvaluationResult.TRE_BI_TRU_DIEM else 0
        )
        self.manager_comment = comment
        self.reviewed_at = timezone.now()
        if evaluation_result == EvaluationResult.DAT:
            self.status = self.STATUS_COMPLETED
        else:
            self.status = self.STATUS_REDO


class Notification(models.Model):
    recipient = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.CASCADE,
        related_name='notifications',
    )
    message = models.CharField(max_length=500)
    is_read = models.BooleanField(default=False)
    related_task = models.ForeignKey(
        Task,
        on_delete=models.CASCADE,
        null=True,
        blank=True,
        related_name='notifications',
    )
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        verbose_name = 'Thông báo'
        verbose_name_plural = 'Thông báo'
        ordering = ['-created_at']

    def __str__(self):
        return f'{self.recipient}: {self.message[:40]}'
