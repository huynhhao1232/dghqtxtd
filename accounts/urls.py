from django.urls import path

from . import views

urlpatterns = [
    path('login/', views.login_view, name='login'),
    path('logout/', views.logout_view, name='logout'),
    path('profile/', views.profile_view, name='profile'),
    # Khu vực tương tác nhóm / Workspace Kanban
    path(
        'departments/<int:dept_id>/interaction/',
        views.department_interaction,
        name='department_interaction',
    ),
    path(
        'departments/<int:dept_id>/tasks/status/',
        views.update_task_status_api,
        name='update_task_status_api',
    ),
    # Manager org management
    path('manager/departments/', views.manager_departments, name='manager_departments'),
    path(
        'manager/departments/<int:dept_id>/members/add/',
        views.department_add_member,
        name='department_add_member',
    ),
    path(
        'manager/departments/<int:dept_id>/members/remove/',
        views.department_remove_member,
        name='department_remove_member',
    ),
    path('manager/staff/', views.manager_staff_list, name='manager_staff'),
    path(
        'manager/staff/import-template/',
        views.manager_staff_import_template,
        name='manager_staff_import_template',
    ),
]
