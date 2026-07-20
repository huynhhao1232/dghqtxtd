from django import forms
from django.utils import timezone

from accounts.models import Department, User

from .models import (
    CoordinatingProof,
    EvaluationResult,
    Task,
    TaskAttachment,
    TaskAssignment,
    TaskParticipation,
)

EVALUATION_RADIO_CHOICES = [
    (EvaluationResult.DAT, 'Đạt (Ghi nhận hoàn thành)'),
    (EvaluationResult.CHO_LAM_LAI, 'Chưa đạt (Yêu cầu làm lại)'),
    (
        EvaluationResult.TRE_BI_TRU_DIEM,
        'Chưa đạt (Làm lại & Trừ 1 điểm thi đua)',
    ),
]

INPUT_CLASS = (
    'w-full px-4 py-2.5 border border-gray-300 rounded-xl bg-white '
    'hover:border-blue-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 '
    'outline-none transition'
)

PRIMARY_SELECT_CLASS = (
    'w-full px-4 py-2.5 border-2 border-blue-400 rounded-xl bg-white '
    'hover:border-blue-500 focus:ring-2 focus:ring-blue-500 focus:border-blue-600 '
    'outline-none transition'
)


class MultipleFileInput(forms.ClearableFileInput):
    allow_multiple_selected = True


class MultipleFileField(forms.FileField):
    """Cho phép nhận nhiều file từ input multiple."""

    def clean(self, data, initial=None):
        if not data:
            if self.required:
                return super().clean(data, initial)
            return []
        if not isinstance(data, (list, tuple)):
            data = [data]
        result = []
        single_clean = super().clean
        for item in data:
            if item:
                result.append(single_clean(item, initial))
        return result


class TaskAssignForm(forms.ModelForm):
    """Form giao việc với lọc người nhận theo role của người giao.

    Naming note: AbstractUser.is_staff là cờ Django admin — RBAC app dùng
    user.is_director / user.is_department / user.is_staff_member.
    """

    ASSIGN_INDIVIDUAL = 'individual'
    ASSIGN_DEPARTMENT = 'department'
    ASSIGN_BATCH_DEPARTMENT = 'batch_department'
    ASSIGN_MODE_CHOICES = [
        (ASSIGN_INDIVIDUAL, 'Giao cho cá nhân'),
        (ASSIGN_DEPARTMENT, 'Giao cho Tổ/Nhóm (Chủ trì — Phối hợp)'),
        (ASSIGN_BATCH_DEPARTMENT, 'Giao đồng loạt nhiều Tổ/Nhóm'),
    ]

    assign_mode = forms.ChoiceField(
        choices=ASSIGN_MODE_CHOICES,
        initial=ASSIGN_INDIVIDUAL,
        widget=forms.RadioSelect,
        label='Hình thức giao việc',
    )
    assignees = forms.ModelMultipleChoiceField(
        queryset=User.objects.none(),
        required=False,
        label='Người nhận',
        widget=forms.MultipleHiddenInput,
    )
    primary_department = forms.ModelChoiceField(
        queryset=Department.objects.all(),
        required=False,
        empty_label='— Chọn đơn vị Chủ trì —',
        label='Đơn vị Chủ trì',
        widget=forms.Select(attrs={'class': PRIMARY_SELECT_CLASS, 'id': 'id_primary_department'}),
    )
    coordinating_departments = forms.ModelMultipleChoiceField(
        queryset=Department.objects.all(),
        required=False,
        label='Đơn vị Phối hợp',
        widget=forms.SelectMultiple(
            attrs={
                'class': INPUT_CLASS + ' min-h-[6.5rem]',
                'id': 'id_coordinating_departments',
                'size': '4',
            }
        ),
        help_text='Giữ Ctrl/Cmd để chọn nhiều tổ. Không chọn trùng với đơn vị Chủ trì.',
    )
    batch_departments = forms.ModelMultipleChoiceField(
        queryset=Department.objects.all(),
        required=False,
        label='Các Tổ/Nhóm nhận việc độc lập',
        widget=forms.MultipleHiddenInput,
        help_text=(
            'Một nhiệm vụ chung, mỗi Tổ/Nhóm có bản nộp minh chứng và đánh giá độc lập.'
        ),
    )
    attachments = MultipleFileField(
        required=False,
        label='File đính kèm',
        widget=MultipleFileInput(
            attrs={
                'id': 'id_attachments',
                'class': 'hidden',
                'multiple': True,
            }
        ),
    )

    class Meta:
        model = Task
        fields = (
            'title',
            'description',
            'deadline',
            'cycle',
            'primary_department',
        )
        widgets = {
            'title': forms.TextInput(
                attrs={
                    'class': INPUT_CLASS,
                    'placeholder': 'VD: Soạn đề kiểm tra giữa kỳ',
                }
            ),
            'description': forms.Textarea(
                attrs={
                    'class': INPUT_CLASS,
                    'rows': 4,
                    'placeholder': 'Mô tả chi tiết yêu cầu, tiêu chí nghiệm thu...',
                }
            ),
            'deadline': forms.DateInput(
                attrs={
                    'type': 'date',
                    'class': INPUT_CLASS + ' pl-10',
                    'id': 'id_deadline',
                }
            ),
            'cycle': forms.Select(attrs={'class': INPUT_CLASS}),
        }

    def __init__(self, *args, user=None, **kwargs):
        self.user = user
        super().__init__(*args, **kwargs)
        dept_qs = Department.objects.select_related('leader').all()
        assignee_qs = User.assignable_queryset_for(user).prefetch_related('my_departments')
        self.fields['assignees'].queryset = assignee_qs
        self.fields['primary_department'].queryset = dept_qs
        self.fields['coordinating_departments'].queryset = dept_qs
        self.fields['batch_departments'].queryset = dept_qs

    @staticmethod
    def _validate_dept_leader(dept, label):
        if not dept.leader_id:
            raise forms.ValidationError(
                f'{label} "{dept.name}" chưa có Trưởng tổ. Vui lòng chỉ định Trưởng tổ trước.'
            )
        leader = dept.leader
        if not leader.is_active or leader.is_director:
            raise forms.ValidationError(
                f'Trưởng tổ của {label.lower()} "{dept.name}" không hợp lệ '
                f'(cần tài khoản Tổ chuyên môn / Giáo viên đang hoạt động).'
            )
        return leader

    def clean(self):
        cleaned = super().clean()
        mode = cleaned.get('assign_mode')
        assignees = cleaned.get('assignees')
        primary = cleaned.get('primary_department')
        coords = cleaned.get('coordinating_departments') or []
        batch_depts = cleaned.get('batch_departments') or []

        if mode == self.ASSIGN_INDIVIDUAL:
            if not assignees:
                raise forms.ValidationError('Vui lòng chọn ít nhất một nhân viên nhận việc.')
            cleaned['primary_department'] = None
            cleaned['coordinating_departments'] = Department.objects.none()
            cleaned['batch_departments'] = Department.objects.none()
        elif mode == self.ASSIGN_DEPARTMENT:
            if not primary:
                raise forms.ValidationError('Vui lòng chọn Đơn vị Chủ trì.')
            primary_leader = self._validate_dept_leader(primary, 'Đơn vị Chủ trì')
            coord_leaders = []
            for dept in coords:
                if dept.pk == primary.pk:
                    raise forms.ValidationError(
                        f'Không thể chọn "{dept.name}" vừa là Chủ trì vừa là Phối hợp.'
                    )
                coord_leaders.append(self._validate_dept_leader(dept, 'Đơn vị Phối hợp'))
            # Giao assignment cho Trưởng tổ Chủ trì + các Trưởng tổ Phối hợp
            leader_ids = [primary_leader.pk] + [u.pk for u in coord_leaders]
            cleaned['assignees'] = User.objects.filter(pk__in=leader_ids)
            cleaned['coordinating_departments'] = coords
            cleaned['batch_departments'] = Department.objects.none()
        elif mode == self.ASSIGN_BATCH_DEPARTMENT:
            if not batch_depts:
                raise forms.ValidationError(
                    'Vui lòng chọn ít nhất một Tổ/Nhóm để giao đồng loạt.'
                )
            for dept in batch_depts:
                self._validate_dept_leader(dept, 'Tổ/Nhóm nhận việc')
            # View sẽ tạo một Task gốc + assignment theo assignee_department cho mỗi tổ.
            cleaned['assignees'] = User.objects.none()
            cleaned['primary_department'] = None
            cleaned['coordinating_departments'] = Department.objects.none()

        files = cleaned.get('attachments') or []
        if len(files) > TaskAttachment.MAX_COUNT:
            raise forms.ValidationError(
                f'Chỉ được đính kèm tối đa {TaskAttachment.MAX_COUNT} file.'
            )
        for f in files:
            if f.size > TaskAttachment.MAX_SIZE_BYTES:
                raise forms.ValidationError(
                    f'File "{f.name}" vượt quá {TaskAttachment.MAX_SIZE_MB}MB '
                    f'({f.size / (1024 * 1024):.1f}MB).'
                )
        cleaned['attachments'] = files
        return cleaned


# Alias tương thích ngược với tên cũ
TaskCreateForm = TaskAssignForm


class StaffTaskUpdateForm(forms.ModelForm):
    """Trưởng tổ Chủ trì / người ủy quyền / assignee sub-task: cập nhật tiến độ & minh chứng."""

    class Meta:
        model = TaskAssignment
        fields = ('status', 'notes', 'proof_file')
        widgets = {
            'status': forms.RadioSelect,
            'notes': forms.Textarea(
                attrs={
                    'class': INPUT_CLASS,
                    'rows': 4,
                    'placeholder': 'Ghi chú tiến độ...',
                }
            ),
            'proof_file': forms.ClearableFileInput(
                attrs={
                    'class': 'hidden',
                    'id': 'id_proof_file',
                }
            ),
        }

    def __init__(self, *args, is_primary_leader=False, is_subtask=False, **kwargs):
        super().__init__(*args, **kwargs)
        if is_subtask:
            pending_label = 'Gửi Trưởng tổ nghiệm thu'
        elif is_primary_leader:
            pending_label = 'Chốt tiến độ — gửi Lãnh đạo nghiệm thu'
        else:
            pending_label = 'Chờ duyệt'
        self.fields['status'].choices = [
            (TaskAssignment.STATUS_TODO, 'Chưa làm'),
            (TaskAssignment.STATUS_IN_PROGRESS, 'Đang làm'),
            (TaskAssignment.STATUS_PENDING, pending_label),
        ]
        self.fields['notes'].widget.attrs.update({
            'class': (
                'w-full px-4 py-2.5 rounded-lg border border-gray-300 bg-white '
                'focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors'
            ),
            'rows': 4,
            'placeholder': 'Ghi chú tiến độ, mô tả công việc đã làm...',
        })
        if self.instance and self.instance.status == TaskAssignment.STATUS_REDO:
            self.initial['status'] = TaskAssignment.STATUS_IN_PROGRESS

    def clean_proof_file(self):
        proof = self.cleaned_data.get('proof_file')
        if proof and hasattr(proof, 'size') and proof.size > TaskAssignment.PROOF_MAX_SIZE_BYTES:
            raise forms.ValidationError(
                f'File minh chứng vượt quá {TaskAssignment.PROOF_MAX_SIZE_MB}MB '
                f'({proof.size / (1024 * 1024):.1f}MB).'
            )
        return proof


class CoordinatingProofForm(forms.ModelForm):
    """Trưởng tổ Phối hợp nộp minh chứng cho Đơn vị chủ trì."""

    class Meta:
        model = CoordinatingProof
        fields = ('notes', 'proof_file')
        widgets = {
            'notes': forms.Textarea(
                attrs={
                    'class': INPUT_CLASS,
                    'rows': 3,
                    'placeholder': 'Ghi chú gửi Đơn vị chủ trì...',
                }
            ),
            'proof_file': forms.ClearableFileInput(
                attrs={
                    'class': 'hidden',
                    'id': 'id_coord_proof_file',
                }
            ),
        }

    def clean_proof_file(self):
        proof = self.cleaned_data.get('proof_file')
        if not proof and not (self.instance and self.instance.proof_file):
            raise forms.ValidationError('Vui lòng chọn file minh chứng.')
        if proof and hasattr(proof, 'size') and proof.size > CoordinatingProof.MAX_SIZE_BYTES:
            raise forms.ValidationError(
                f'File vượt quá {CoordinatingProof.MAX_SIZE_MB}MB '
                f'({proof.size / (1024 * 1024):.1f}MB).'
            )
        return proof


class AddMembersForm(forms.Form):
    """Trưởng tổ chọn thành viên trong tổ mình để thêm vào task."""

    member_ids = forms.ModelMultipleChoiceField(
        queryset=User.objects.none(),
        required=True,
        widget=forms.MultipleHiddenInput,
        error_messages={'required': 'Vui lòng chọn ít nhất một thành viên.'},
    )

    def __init__(self, *args, department=None, exclude_ids=None, **kwargs):
        super().__init__(*args, **kwargs)
        qs = User.objects.filter(
            is_active=True,
        ).exclude(role=User.ROLE_DIRECTOR)
        if department:
            qs = qs.filter(my_departments=department)
        if exclude_ids:
            qs = qs.exclude(pk__in=exclude_ids)
        self.fields['member_ids'].queryset = qs.prefetch_related('my_departments')


class InternalEvaluationForm(forms.Form):
    """Trưởng tổ Chủ trì đánh giá từng cá nhân sau khi Task được nghiệm thu."""

    def __init__(self, *args, participations=None, **kwargs):
        super().__init__(*args, **kwargs)
        self.participations = list(participations or [])
        for p in self.participations:
            self.fields[f'eval_{p.pk}'] = forms.ChoiceField(
                choices=EVALUATION_RADIO_CHOICES,
                required=True,
                error_messages={'required': f'Chưa đánh giá {p.user}.'},
            )

    def save(self):
        for p in self.participations:
            value = self.cleaned_data[f'eval_{p.pk}']
            p.apply_evaluation(value)
            p.save(update_fields=['evaluation', 'penalty_score', 'evaluated_at'])


class ReviewForm(forms.Form):
    evaluation_result = forms.ChoiceField(
        label='Kết quả đánh giá',
        choices=EVALUATION_RADIO_CHOICES,
        widget=forms.RadioSelect,
        required=True,
        error_messages={'required': 'Vui lòng chọn một trong ba kết quả đánh giá.'},
    )
    manager_comment = forms.CharField(
        label='Nhận xét',
        required=False,
        widget=forms.Textarea(
            attrs={
                'class': (
                    'w-full px-4 py-2.5 rounded-lg border border-gray-300 bg-white '
                    'focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors'
                ),
                'rows': 4,
                'placeholder': 'Nhận xét của lãnh đạo về chất lượng bài nộp...',
            }
        ),
    )


class TaskEditForm(forms.ModelForm):
    """Lãnh đạo sửa tiêu đề, mô tả, thời hạn của công việc đã giao."""

    class Meta:
        model = Task
        fields = ('title', 'description', 'deadline')
        widgets = {
            'title': forms.TextInput(
                attrs={
                    'class': INPUT_CLASS,
                    'placeholder': 'Tên công việc',
                }
            ),
            'description': forms.Textarea(
                attrs={
                    'class': INPUT_CLASS,
                    'rows': 4,
                    'placeholder': 'Mô tả chi tiết công việc...',
                }
            ),
            'deadline': forms.DateInput(
                attrs={
                    'class': INPUT_CLASS,
                    'type': 'date',
                }
            ),
        }

    def clean_deadline(self):
        deadline = self.cleaned_data.get('deadline')
        if not deadline:
            raise forms.ValidationError('Vui lòng chọn thời hạn.')
        return deadline


class SubtaskCreateForm(forms.Form):
    """Trưởng tổ tạo nhiệm vụ con trong phạm vi tổ mình."""

    title = forms.CharField(
        label='Tiêu đề',
        max_length=255,
        widget=forms.TextInput(
            attrs={
                'class': INPUT_CLASS,
                'placeholder': 'VD: Chuẩn bị âm thanh',
                'id': 'id_subtask_title',
            }
        ),
    )
    deadline = forms.DateField(
        label='Thời hạn',
        widget=forms.DateInput(
            attrs={
                'class': INPUT_CLASS,
                'type': 'date',
                'id': 'id_subtask_deadline',
            }
        ),
    )
    assignees = forms.ModelMultipleChoiceField(
        queryset=User.objects.none(),
        label='Người thực hiện',
        widget=forms.MultipleHiddenInput,
        error_messages={'required': 'Vui lòng chọn ít nhất một người thực hiện.'},
    )

    def __init__(self, *args, parent_task=None, department=None, **kwargs):
        self.parent_task = parent_task
        self.department = department
        super().__init__(*args, **kwargs)
        qs = User.objects.none()
        if parent_task:
            participations = parent_task.participations.all()
            if department is not None:
                participations = participations.filter(department=department)
            participant_ids = participations.values_list('user_id', flat=True)
            qs = User.objects.filter(
                pk__in=participant_ids,
                is_active=True,
            ).exclude(role=User.ROLE_DIRECTOR).order_by('last_name', 'first_name')
            self.fields['deadline'].widget.attrs['max'] = parent_task.deadline.isoformat()
            self.fields['deadline'].initial = parent_task.deadline
        self.fields['assignees'].queryset = qs

    def clean_deadline(self):
        deadline = self.cleaned_data.get('deadline')
        if not deadline:
            raise forms.ValidationError('Vui lòng chọn thời hạn.')
        if self.parent_task and deadline > self.parent_task.deadline:
            raise forms.ValidationError(
                f'Thời hạn không được vượt quá hạn nhiệm vụ gốc '
                f'({self.parent_task.deadline.strftime("%d/%m/%Y")}).'
            )
        return deadline

    def clean_assignees(self):
        assignees = self.cleaned_data.get('assignees')
        if not assignees:
            raise forms.ValidationError('Vui lòng chọn ít nhất một người thực hiện.')
        return assignees


class SubtaskEditForm(forms.ModelForm):
    """Trưởng tổ chỉnh sửa thông tin cơ bản của nhiệm vụ con."""

    class Meta:
        model = Task
        fields = ('title', 'deadline')
        widgets = {
            'title': forms.TextInput(attrs={'class': INPUT_CLASS}),
            'deadline': forms.DateInput(
                attrs={'class': INPUT_CLASS, 'type': 'date'}
            ),
        }

    def __init__(self, *args, parent_task=None, **kwargs):
        self.parent_task = parent_task
        super().__init__(*args, **kwargs)
        if parent_task:
            self.fields['deadline'].widget.attrs['max'] = (
                parent_task.deadline.isoformat()
            )

    def clean_deadline(self):
        deadline = self.cleaned_data.get('deadline')
        if self.parent_task and deadline > self.parent_task.deadline:
            raise forms.ValidationError(
                f'Thời hạn không được vượt quá hạn nhiệm vụ gốc '
                f'({self.parent_task.deadline.strftime("%d/%m/%Y")}).'
            )
        return deadline


class SubtaskReviewForm(forms.Form):
    """Trưởng tổ nghiệm thu / yêu cầu làm lại nhiệm vụ con."""

    evaluation_result = forms.ChoiceField(
        label='Kết quả',
        choices=EVALUATION_RADIO_CHOICES,
        widget=forms.RadioSelect,
        required=True,
        initial=EvaluationResult.DAT,
        error_messages={'required': 'Vui lòng chọn một trong ba kết quả đánh giá.'},
    )
    manager_comment = forms.CharField(
        label='Nhận xét',
        required=False,
        widget=forms.Textarea(
            attrs={
                'class': INPUT_CLASS,
                'rows': 3,
                'placeholder': 'Nhận xét của Trưởng tổ...',
            }
        ),
    )
