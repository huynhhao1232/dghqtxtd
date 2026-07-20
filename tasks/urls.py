from django.urls import path

from . import views

urlpatterns = [
    # Notifications
    path('notifications/<int:pk>/read/', views.notification_read, name='notification_read'),

    # Kanban modal — chi tiết task (JSON)
    path('api/tasks/<int:pk>/', views.task_detail_api, name='task_detail_api'),

    # Staff
    path('staff/', views.staff_dashboard, name='staff_dashboard'),
    path('staff/tasks/', views.staff_my_tasks, name='staff_my_tasks'),
    path('staff/tasks/<int:pk>/', views.staff_task_detail, name='staff_task_detail'),
    path('staff/tasks/<int:pk>/handover/', views.staff_task_handover, name='staff_task_handover'),
    path('staff/tasks/<int:pk>/members/add/', views.staff_task_add_members, name='staff_task_add_members'),
    path('staff/tasks/<int:pk>/members/remove/', views.staff_task_remove_member, name='staff_task_remove_member'),
    path('staff/tasks/<int:pk>/delegate/', views.staff_task_set_delegate, name='staff_task_set_delegate'),
    path('staff/tasks/<int:pk>/subtasks/create/', views.staff_task_create_subtask, name='staff_task_create_subtask'),
    path('staff/subtasks/<int:pk>/edit/', views.staff_subtask_edit, name='staff_subtask_edit'),
    path('staff/subtasks/<int:pk>/delete/', views.staff_subtask_delete, name='staff_subtask_delete'),
    path('staff/subtasks/<int:pk>/review/', views.staff_subtask_review, name='staff_subtask_review'),

    # Manager (RBAC: manager_required → 403 nếu nhân viên truy cập)
    path('manager/', views.manager_dashboard, name='manager_dashboard'),
    path('manager/tasks/create/', views.manager_create_task, name='manager_create_task'),
    path('manager/tasks/manage/', views.manager_manage_tasks, name='manager_manage_tasks'),
    path('manager/tasks/<int:pk>/', views.manager_task_detail, name='manager_task_detail'),
    path(
        'manager/tasks/<int:pk>/assignments/<int:assignment_pk>/handover/',
        views.manager_task_handover,
        name='manager_task_handover',
    ),
    path(
        'manager/tasks/<int:pk>/add-performers/',
        views.manager_task_add_performers,
        name='manager_task_add_performers',
    ),
    path(
        'manager/tasks/<int:pk>/assignments/<int:assignment_pk>/review/',
        views.manager_assignment_review,
        name='manager_assignment_review',
    ),
    path('manager/tasks/<int:pk>/edit/', views.manager_task_edit, name='manager_task_edit'),
    path('manager/tasks/<int:pk>/extend/', views.manager_task_extend, name='manager_task_extend'),
    path('manager/tasks/<int:pk>/delete/', views.manager_task_delete, name='manager_task_delete'),
    path('manager/review/', views.manager_review_queue, name='manager_review_queue'),
    path('manager/review/<int:pk>/', views.manager_review_task, name='manager_review_task'),
    path('manager/statistics/', views.manager_statistics, name='manager_statistics'),
    path('manager/statistics/export/', views.manager_export_excel, name='manager_export_excel'),
]
