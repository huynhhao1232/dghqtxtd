from django.contrib import admin
from django.contrib.auth.admin import UserAdmin as DjangoUserAdmin

from .models import Department, GroupPost, User


@admin.register(Department)
class DepartmentAdmin(admin.ModelAdmin):
    list_display = ('name', 'leader', 'badge_color', 'created_at')
    search_fields = ('name', 'description')
    autocomplete_fields = ('leader',)
    filter_horizontal = ('members',)


@admin.register(GroupPost)
class GroupPostAdmin(admin.ModelAdmin):
    list_display = ('department', 'author', 'created_at')
    list_filter = ('department',)
    search_fields = ('content', 'author__username', 'author__first_name')
    autocomplete_fields = ('department', 'author')


@admin.register(User)
class UserAdmin(DjangoUserAdmin):
    list_display = (
        'username',
        'email',
        'first_name',
        'last_name',
        'position',
        'role',
        'account_status',
        'is_manager',
        'is_active',
    )
    list_editable = ('role',)
    list_filter = ('role', 'account_status', 'is_manager', 'is_active')
    search_fields = ('username', 'first_name', 'last_name', 'email')
    fieldsets = DjangoUserAdmin.fieldsets + (
        (
            'Thông tin tổ chức',
            {
                'fields': (
                    'role',
                    'account_status',
                    'is_manager',
                    'position',
                    'phone',
                    'avatar',
                )
            },
        ),
    )
    add_fieldsets = DjangoUserAdmin.add_fieldsets + (
        (
            'Thông tin tổ chức',
            {
                'fields': (
                    'role',
                    'account_status',
                    'is_manager',
                    'position',
                    'phone',
                )
            },
        ),
    )

    def save_model(self, request, obj, form, change):
        # Keep legacy is_manager in sync with app role (also enforced in User.save).
        obj.is_manager = obj.role == User.ROLE_DIRECTOR
        super().save_model(request, obj, form, change)
