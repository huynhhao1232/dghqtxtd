<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DepartmentInteractionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ManagerDashboardController;
use App\Http\Controllers\ManagerReviewController;
use App\Http\Controllers\ManagerStatisticsController;
use App\Http\Controllers\ManagerTaskController;
use App\Http\Controllers\NotificationApiController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StaffAdminController;
use App\Http\Controllers\StaffDashboardController;
use App\Http\Controllers\StaffTaskController;
use App\Http\Controllers\TaskAssigneeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', HomeController::class)->name('home');
    Route::match(['get', 'post'], '/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('notification_read');
    Route::get('/api/notifications', [NotificationApiController::class, 'index'])
        ->name('notifications_api');
    Route::patch('/api/notifications/{notification}/mark-as-read', [NotificationApiController::class, 'markAsRead'])
        ->name('notifications_mark_as_read_api');

    Route::match(['get', 'post'], '/departments/{department}/interaction', DepartmentInteractionController::class)
        ->name('department_interaction');

    // Staff
    Route::get('/staff/', [StaffDashboardController::class, 'index'])->name('staff_dashboard');
    Route::get('/staff/tasks/', [StaffTaskController::class, 'myTasks'])->name('staff_my_tasks');
    Route::match(['get', 'post'], '/staff/tasks/{pk}/', [StaffTaskController::class, 'detail'])
        ->whereNumber('pk')->name('staff_task_detail');
    Route::post('/staff/tasks/{pk}/members/add/', [StaffTaskController::class, 'addMembers'])
        ->whereNumber('pk')->name('staff_task_add_members');
    Route::post('/staff/tasks/{pk}/members/remove/', [StaffTaskController::class, 'removeMember'])
        ->whereNumber('pk')->name('staff_task_remove_member');
    Route::post('/staff/tasks/{pk}/delegate/', [StaffTaskController::class, 'setDelegate'])
        ->whereNumber('pk')->name('staff_task_set_delegate');
    Route::post('/staff/tasks/{pk}/subtasks/create/', [StaffTaskController::class, 'createSubtask'])
        ->whereNumber('pk')->name('staff_task_create_subtask');
    Route::post('/staff/subtasks/{pk}/edit/', [StaffTaskController::class, 'editSubtask'])
        ->whereNumber('pk')->name('staff_subtask_edit');
    Route::post('/staff/subtasks/{pk}/delete/', [StaffTaskController::class, 'deleteSubtask'])
        ->whereNumber('pk')->name('staff_subtask_delete');
    Route::post('/staff/subtasks/{pk}/review/', [StaffTaskController::class, 'reviewSubtask'])
        ->whereNumber('pk')->name('staff_subtask_review');

    // Manager
    Route::middleware('manager')->group(function (): void {
        Route::get('/manager/', [ManagerDashboardController::class, 'index'])->name('manager_dashboard');
        Route::match(['get', 'post'], '/manager/tasks/create/', [ManagerTaskController::class, 'create'])
            ->name('manager_create_task');
        Route::get('/manager/tasks/manage/', [ManagerTaskController::class, 'manage'])->name('manager_manage_tasks');
        Route::get('/manager/tasks/{task}/', [ManagerTaskController::class, 'show'])->name('manager_task_detail');
        Route::get('/manager/tasks/{task}/export/', [ManagerTaskController::class, 'exportExcel'])
            ->name('tasks.export_excel');
        Route::post('/manager/tasks/{task}/assignments/{assignment}/review/', [ManagerTaskController::class, 'assignmentReview'])
            ->name('manager_assignment_review');
        Route::post('/manager/tasks/{task}/edit/', [ManagerTaskController::class, 'edit'])->name('manager_task_edit');
        Route::post('/manager/tasks/{task}/extend/', [ManagerTaskController::class, 'extend'])->name('manager_task_extend');
        Route::post('/manager/tasks/{task}/delete/', [ManagerTaskController::class, 'destroy'])->name('manager_task_delete');
        Route::get('/manager/review/', [ManagerReviewController::class, 'queue'])->name('manager_review_queue');
        Route::match(['get', 'post'], '/manager/review/{assignment}/', [ManagerReviewController::class, 'review'])
            ->name('manager_review_task');
        Route::get('/manager/statistics/', [ManagerStatisticsController::class, 'index'])->name('manager_statistics');
        Route::get('/manager/statistics/export/', [ManagerStatisticsController::class, 'export'])
            ->name('manager_export_excel');

        Route::match(['get', 'post'], '/manager/departments/', [DepartmentController::class, 'index'])
            ->name('manager_departments');
        Route::post('/manager/departments/{department}/members/add/', [DepartmentController::class, 'addMember'])
            ->name('department_add_member');
        Route::post('/manager/departments/{department}/members/remove/', [DepartmentController::class, 'removeMember'])
            ->name('department_remove_member');
        Route::get('/api/departments/{department}/members', [DepartmentController::class, 'members'])
            ->name('department_members_api');
        Route::get('/api/users/search', [DepartmentController::class, 'searchMemberCandidates'])
            ->name('department_member_search_api');
        Route::post('/api/departments/{department}/members', [DepartmentController::class, 'addMember'])
            ->name('department_member_add_api');
        Route::delete('/api/departments/{department}/members/{user}', [DepartmentController::class, 'destroyMember'])
            ->name('department_member_destroy_api');
        Route::get('/api/tasks/{task}/assignees', [TaskAssigneeController::class, 'index'])
            ->name('task_assignees_api');
        Route::get('/api/search/assignees', [TaskAssigneeController::class, 'search'])
            ->name('task_assignees_search_api');
        Route::post('/api/tasks/{task}/assignees', [TaskAssigneeController::class, 'store'])
            ->name('task_assignees_store_api');
        Route::delete('/api/tasks/{task}/assignees', [TaskAssigneeController::class, 'destroy'])
            ->name('task_assignees_destroy_api');

        Route::get('/manager/staff/', [StaffAdminController::class, 'index'])->name('manager_staff');
        Route::post('/manager/staff/create/', [StaffAdminController::class, 'create'])->name('manager_staff_create');
        Route::get('/manager/staff/{user}/data/', [StaffAdminController::class, 'showApi'])
            ->name('manager_staff_api_show');
        Route::patch('/manager/staff/{user}/', [StaffAdminController::class, 'updateApi'])
            ->name('manager_staff_api_update');
        Route::post('/manager/staff/{user}/edit/', [StaffAdminController::class, 'edit'])->name('manager_staff_edit');
        Route::post('/manager/staff/{user}/toggle/', [StaffAdminController::class, 'toggleActive'])
            ->name('manager_staff_toggle');
    });
});
