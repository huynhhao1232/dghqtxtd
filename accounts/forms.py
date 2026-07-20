from django import forms
from django.contrib.auth import authenticate
from django.contrib.auth.forms import AuthenticationForm, PasswordChangeForm

from .models import Department, GroupPost, User


class LoginForm(AuthenticationForm):
    """Form đăng nhập với thông báo lỗi rõ ràng (sai mật khẩu / bị khóa)."""

    username = forms.CharField(
        label='Tên đăng nhập',
        widget=forms.TextInput(
            attrs={
                'class': 'w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition',
                'placeholder': 'Tên đăng nhập',
                'autofocus': True,
            }
        ),
    )
    password = forms.CharField(
        label='Mật khẩu',
        strip=False,
        widget=forms.PasswordInput(
            attrs={
                'class': 'w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition',
                'placeholder': 'Mật khẩu',
            }
        ),
    )

    error_messages = {
        'invalid_login': 'Sai tên đăng nhập hoặc mật khẩu. Vui lòng thử lại.',
        'inactive': 'Tài khoản của bạn đã bị khóa. Liên hệ quản trị viên.',
    }

    def clean(self):
        username = self.cleaned_data.get('username')
        password = self.cleaned_data.get('password')

        if username and password:
            try:
                user_obj = User.objects.get(username=username)
                if not user_obj.is_active:
                    raise forms.ValidationError(
                        self.error_messages['inactive'],
                        code='inactive',
                    )
            except User.DoesNotExist:
                pass

            self.user_cache = authenticate(
                self.request,
                username=username,
                password=password,
            )
            if self.user_cache is None:
                raise forms.ValidationError(
                    self.error_messages['invalid_login'],
                    code='invalid_login',
                )
        return self.cleaned_data


class ProfileUpdateForm(forms.ModelForm):
    class Meta:
        model = User
        fields = ('avatar', 'phone', 'email')
        widgets = {
            'phone': forms.TextInput(
                attrs={
                    'class': 'w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none',
                    'placeholder': 'Số điện thoại',
                }
            ),
            'email': forms.EmailInput(
                attrs={
                    'class': 'w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none',
                    'placeholder': 'Email',
                }
            ),
            'avatar': forms.ClearableFileInput(
                attrs={
                    'class': 'block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-blue-50 file:text-primary hover:file:bg-blue-100',
                    'accept': 'image/*',
                }
            ),
        }


class StyledPasswordChangeForm(PasswordChangeForm):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        field_class = (
            'w-full px-4 py-2 border border-gray-300 rounded-lg '
            'focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none'
        )
        for field in self.fields.values():
            field.widget.attrs['class'] = field_class


INPUT_CLASS = (
    'w-full px-4 py-2.5 border border-gray-300 rounded-xl bg-white '
    'hover:border-blue-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 '
    'outline-none transition'
)


class DepartmentForm(forms.ModelForm):
    class Meta:
        model = Department
        fields = ('name', 'description', 'leader', 'badge_color')
        widgets = {
            'name': forms.TextInput(attrs={'class': INPUT_CLASS, 'placeholder': 'VD: Tổ Toán-Tin'}),
            'description': forms.Textarea(
                attrs={'class': INPUT_CLASS, 'rows': 3, 'placeholder': 'Mô tả ngắn về tổ/nhóm...'}
            ),
            'leader': forms.Select(attrs={'class': INPUT_CLASS}),
            'badge_color': forms.Select(attrs={'class': INPUT_CLASS}),
        }

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.fields['leader'].queryset = User.objects.filter(is_active=True).order_by(
            'last_name', 'first_name'
        )
        self.fields['leader'].required = False
        self.fields['leader'].empty_label = '— Chưa chỉ định —'

    def save(self, commit=True):
        dept = super().save(commit=commit)
        if commit:
            dept.ensure_leader_is_member()
            if dept.leader_id:
                User.objects.filter(pk=dept.leader_id).exclude(
                    role=User.ROLE_DIRECTOR,
                ).update(role=User.ROLE_DEPARTMENT)
        return dept


EDIT_INPUT_CLASS = (
    'w-full px-4 py-3 border border-gray-300 rounded-lg bg-white '
    'placeholder:text-gray-400 hover:border-blue-300 '
    'focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition'
)


class StaffEditForm(forms.ModelForm):
    role = forms.ChoiceField(
        choices=User.ROLE_CHOICES,
        label='Vai trò',
        widget=forms.Select(attrs={'class': EDIT_INPUT_CLASS}),
    )
    full_name = forms.CharField(
        label='Họ và tên',
        max_length=150,
        widget=forms.TextInput(
            attrs={'class': EDIT_INPUT_CLASS, 'placeholder': 'VD: Nguyễn Văn A'}
        ),
    )
    departments = forms.ModelMultipleChoiceField(
        label='Tổ / Nhóm',
        queryset=Department.objects.all(),
        required=False,
        widget=forms.MultipleHiddenInput,
    )

    class Meta:
        model = User
        fields = ('role', 'email', 'phone', 'position', 'is_active')
        widgets = {
            'email': forms.EmailInput(
                attrs={'class': EDIT_INPUT_CLASS, 'placeholder': 'email@example.com'}
            ),
            'phone': forms.TextInput(
                attrs={'class': EDIT_INPUT_CLASS, 'placeholder': 'Số điện thoại'}
            ),
            'position': forms.TextInput(
                attrs={'class': EDIT_INPUT_CLASS, 'placeholder': 'VD: Giáo viên'}
            ),
            'is_active': forms.CheckboxInput(
                attrs={'class': 'sr-only peer', 'id': 'id_is_active'}
            ),
        }

    field_order = ('role', 'full_name', 'email', 'phone', 'departments', 'position', 'is_active')

    def __init__(self, *args, actor=None, **kwargs):
        self.actor = actor
        super().__init__(*args, **kwargs)
        self.order_fields(self.field_order)
        self.fields['departments'].queryset = Department.objects.all()
        if self.instance and self.instance.pk:
            from .vietnamese import user_display_full_name
            self.fields['full_name'].initial = user_display_full_name(self.instance)
            self.fields['departments'].initial = self.instance.my_departments.all()
            self.fields['role'].initial = self.instance.role

    def clean_role(self):
        role = self.cleaned_data['role']
        user = self.instance
        if not user or not user.pk:
            return role
        was_director = user.role == User.ROLE_DIRECTOR
        becoming_non_director = role != User.ROLE_DIRECTOR
        if was_director and becoming_non_director:
            actor = self.actor
            if actor is not None and actor.pk == user.pk:
                raise forms.ValidationError(
                    'Bạn không thể tự hạ vai trò Ban Giám đốc của chính mình.'
                )
            other_directors = (
                User.objects.filter(role=User.ROLE_DIRECTOR, is_active=True)
                .exclude(pk=user.pk)
                .exists()
            )
            if not other_directors:
                raise forms.ValidationError(
                    'Không thể hạ vai trò Ban Giám đốc cuối cùng còn hoạt động.'
                )
        return role

    def save(self, commit=True):
        user = super().save(commit=False)
        full_name = self.cleaned_data['full_name'].strip()
        user.first_name = full_name
        user.last_name = ''
        role = self.cleaned_data.get('role', user.role)
        user.role = role
        user.is_manager = role == User.ROLE_DIRECTOR
        if commit:
            user.save()
            user.my_departments.set(self.cleaned_data.get('departments') or [])
        return user


class StaffCreateForm(forms.ModelForm):
    """Form tạo tài khoản mới (Lãnh đạo tạo viên chức / lãnh đạo khác)."""

    ROLE_STAFF = User.ROLE_STAFF
    ROLE_DEPARTMENT = User.ROLE_DEPARTMENT
    ROLE_DIRECTOR = User.ROLE_DIRECTOR
    # Giữ alias cũ cho template/code gọi ROLE_MANAGER
    ROLE_MANAGER = User.ROLE_DIRECTOR
    ROLE_CHOICES = User.ROLE_CHOICES

    role = forms.ChoiceField(
        choices=ROLE_CHOICES,
        initial=ROLE_STAFF,
        label='Vai trò',
        widget=forms.Select(attrs={'class': INPUT_CLASS}),
    )
    full_name = forms.CharField(
        label='Họ và tên',
        max_length=150,
        widget=forms.TextInput(
            attrs={'class': INPUT_CLASS, 'placeholder': 'VD: Nguyễn Văn A'}
        ),
    )
    departments = forms.ModelMultipleChoiceField(
        label='Tổ / Nhóm',
        queryset=Department.objects.all(),
        required=False,
        widget=forms.SelectMultiple(
            attrs={
                'class': INPUT_CLASS + ' min-h-[7.5rem]',
                'size': '4',
            }
        ),
        help_text='Giữ Ctrl/Cmd để chọn nhiều tổ.',
    )
    password1 = forms.CharField(
        label='Mật khẩu',
        strip=False,
        widget=forms.PasswordInput(
            attrs={'class': INPUT_CLASS, 'placeholder': 'Tối thiểu 8 ký tự', 'autocomplete': 'new-password'}
        ),
    )
    password2 = forms.CharField(
        label='Xác nhận mật khẩu',
        strip=False,
        widget=forms.PasswordInput(
            attrs={'class': INPUT_CLASS, 'placeholder': 'Nhập lại mật khẩu', 'autocomplete': 'new-password'}
        ),
    )

    class Meta:
        model = User
        fields = (
            'username',
            'email',
            'phone',
            'position',
        )
        widgets = {
            'username': forms.TextInput(
                attrs={'class': INPUT_CLASS, 'placeholder': 'VD: nguyenvana', 'autocomplete': 'off'}
            ),
            'email': forms.EmailInput(attrs={'class': INPUT_CLASS, 'placeholder': 'email@example.com'}),
            'phone': forms.TextInput(attrs={'class': INPUT_CLASS, 'placeholder': 'Số điện thoại'}),
            'position': forms.TextInput(attrs={'class': INPUT_CLASS, 'placeholder': 'VD: Giáo viên'}),
        }

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.fields['departments'].queryset = Department.objects.all()
        self.fields['username'].help_text = 'Chỉ chữ, số và @/./+/-/_'

    def clean_username(self):
        username = self.cleaned_data['username'].strip()
        if User.objects.filter(username__iexact=username).exists():
            raise forms.ValidationError('Tên đăng nhập đã tồn tại.')
        return username

    def clean_full_name(self):
        name = self.cleaned_data['full_name'].strip()
        if not name:
            raise forms.ValidationError('Vui lòng nhập họ và tên.')
        return name

    def clean_password2(self):
        password1 = self.cleaned_data.get('password1')
        password2 = self.cleaned_data.get('password2')
        if password1 and password2 and password1 != password2:
            raise forms.ValidationError('Mật khẩu xác nhận không khớp.')
        return password2

    def clean(self):
        cleaned = super().clean()
        password1 = cleaned.get('password1')
        if password1:
            from django.contrib.auth.password_validation import validate_password
            from django.core.exceptions import ValidationError as DjangoValidationError

            temp_user = User(
                username=cleaned.get('username') or '',
                email=cleaned.get('email') or '',
                first_name=cleaned.get('full_name') or '',
            )
            try:
                validate_password(password1, user=temp_user)
            except DjangoValidationError as exc:
                self.add_error('password1', exc)
        return cleaned

    def save(self, commit=True):
        user = super().save(commit=False)
        user.first_name = self.cleaned_data['full_name'].strip()
        user.last_name = ''
        user.set_password(self.cleaned_data['password1'])
        role = self.cleaned_data.get('role', self.ROLE_STAFF)
        user.role = role
        user.is_manager = role == User.ROLE_DIRECTOR
        user.is_staff = True  # Django admin flag — unrelated to app role staff
        user.is_active = True
        if commit:
            user.save()
            user.my_departments.set(self.cleaned_data.get('departments') or [])
        return user


class GroupPostForm(forms.ModelForm):
    class Meta:
        model = GroupPost
        fields = ('content',)
        widgets = {
            'content': forms.Textarea(
                attrs={
                    'rows': 3,
                    'placeholder': 'Chia sẻ thông tin, trao đổi trong tổ...',
                    'class': (
                        'w-full px-4 py-3 border border-gray-200 rounded-xl bg-white '
                        'hover:border-blue-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 '
                        'outline-none transition resize-none text-sm'
                    ),
                }
            ),
        }

    def clean_content(self):
        content = (self.cleaned_data.get('content') or '').strip()
        if not content:
            raise forms.ValidationError('Vui lòng nhập nội dung trước khi đăng.')
        if len(content) > 5000:
            raise forms.ValidationError('Nội dung tối đa 5000 ký tự.')
        return content
