from django.contrib import admin
from django.contrib.auth.admin import UserAdmin as DjangoUserAdmin

from .models import ChatMessage, Department, GroupPost, User


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


@admin.register(ChatMessage)
class ChatMessageAdmin(admin.ModelAdmin):
    list_display = ('department', 'user', 'created_at')
    list_filter = ('department',)
    search_fields = ('text', 'user__username', 'user__first_name')
    autocomplete_fields = ('department', 'user')
    readonly_fields = ('created_at',)

@admin.register(User)
class UserAdmin(DjangoUserAdmin):
    list_display = (
        'username',
        'email',
        'first_name',
        'last_name',
        'position',
        'is_manager',
        'is_active',
    )
    list_filter = ('is_manager', 'is_active')
    search_fields = ('username', 'first_name', 'last_name', 'email')
    fieldsets = DjangoUserAdmin.fieldsets + (
        (
            'Thông tin tổ chức',
            {
                'fields': (
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
                    'is_manager',
                    'position',
                    'phone',
                )
            },
        ),
    )
