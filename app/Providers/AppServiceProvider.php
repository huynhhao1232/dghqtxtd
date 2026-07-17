<?php

namespace App\Providers;

use App\Models\Department;
use App\Models\TaskAssignment;
use App\Models\TaskNotification;
use App\Observers\TaskAssignmentObserver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TaskAssignment::observe(TaskAssignmentObserver::class);

        View::composer('*', function ($view): void {
            $user = Auth::user();

            if (! $user) {
                $view->with([
                    'unread_notification_count' => 0,
                    'recent_notifications' => collect(),
                    'sidebar_departments' => collect(),
                ]);

                return;
            }

            $notifications = TaskNotification::query()
                ->where('recipient_id', $user->id);

            $sidebarDepartments = Department::query()
                ->where(function ($query) use ($user): void {
                    $query->where('leader_id', $user->id)
                        ->orWhereHas('members', fn ($members) => $members->where('users.id', $user->id));
                })
                ->orderBy('name')
                ->get();

            $view->with([
                'unread_notification_count' => (clone $notifications)->where('is_read', false)->count(),
                'recent_notifications' => (clone $notifications)
                    ->with('relatedTask')
                    ->latest()
                    ->limit(8)
                    ->get(),
                'sidebar_departments' => $sidebarDepartments,
            ]);
        });
    }
}
