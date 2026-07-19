from django.contrib import admin

from .models import (
    CoordinatingProof,
    Notification,
    Task,
    TaskAssignment,
    TaskAttachment,
    TaskParticipation,
)


class TaskAssignmentInline(admin.TabularInline):
    model = TaskAssignment
    extra = 0
    autocomplete_fields = ('assignee', 'assignee_department')
    fields = (
        'assignee',
        'assignee_department',
        'status',
        'evaluation_result',
        'penalty_score',
    )


class TaskAttachmentInline(admin.TabularInline):
    model = TaskAttachment
    extra = 0
    readonly_fields = ('original_name', 'file_size', 'uploaded_at')


class TaskParticipationInline(admin.TabularInline):
    model = TaskParticipation
    extra = 0
    autocomplete_fields = ('user', 'department')
    fields = ('user', 'department', 'role', 'evaluation', 'penalty_score')


class CoordinatingProofInline(admin.TabularInline):
    model = CoordinatingProof
    extra = 0
    autocomplete_fields = ('department', 'uploaded_by')


@admin.register(Task)
class TaskAdmin(admin.ModelAdmin):
    list_display = (
        'title',
        'created_by',
        'primary_department',
        'scope_department',
        'parent_task',
        'is_subtask',
        'delegated_updater',
        'deadline',
        'cycle',
        'created_at',
    )
    list_filter = (
        'cycle',
        'deadline',
        'primary_department',
        'scope_department',
        'is_subtask',
    )
    search_fields = ('title', 'description')
    autocomplete_fields = (
        'created_by',
        'primary_department',
        'scope_department',
        'delegated_updater',
        'parent_task',
    )
    filter_horizontal = ('coordinating_departments',)
    inlines = [
        TaskAssignmentInline,
        TaskParticipationInline,
        CoordinatingProofInline,
        TaskAttachmentInline,
    ]


@admin.register(TaskAssignment)
class TaskAssignmentAdmin(admin.ModelAdmin):
    list_display = (
        'task',
        'target_display_name',
        'assignee',
        'assignee_department',
        'status',
        'evaluation_result',
        'penalty_score',
        'submitted_at',
        'reviewed_at',
    )
    list_filter = ('status', 'evaluation_result')
    search_fields = (
        'task__title',
        'assignee__username',
        'assignee__first_name',
        'assignee_department__name',
    )
    autocomplete_fields = ('task', 'assignee', 'assignee_department')
    readonly_fields = ('target_display_name',)


@admin.register(TaskParticipation)
class TaskParticipationAdmin(admin.ModelAdmin):
    list_display = (
        'task',
        'user',
        'department',
        'role',
        'evaluation',
        'penalty_score',
        'added_at',
    )
    list_filter = ('role', 'evaluation')
    search_fields = ('task__title', 'user__username', 'user__first_name')
    autocomplete_fields = ('task', 'user', 'department')


@admin.register(CoordinatingProof)
class CoordinatingProofAdmin(admin.ModelAdmin):
    list_display = ('task', 'department', 'uploaded_by', 'submitted_at')
    search_fields = ('task__title', 'department__name')
    autocomplete_fields = ('task', 'department', 'uploaded_by')


@admin.register(TaskAttachment)
class TaskAttachmentAdmin(admin.ModelAdmin):
    list_display = ('original_name', 'task', 'file_size', 'uploaded_at')
    search_fields = ('original_name', 'task__title')


@admin.register(Notification)
class NotificationAdmin(admin.ModelAdmin):
    list_display = ('recipient', 'message', 'is_read', 'created_at')
    list_filter = ('is_read',)
    search_fields = ('message', 'recipient__username')
