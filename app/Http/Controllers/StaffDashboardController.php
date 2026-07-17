<?php

namespace App\Http\Controllers;

use App\Models\TaskAssignment;
use App\Services\ReportingService;
use App\Support\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffDashboardController extends Controller
{
    public function index(Request $request, ReportingService $reporting): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->is_manager) {
            return redirect()->route('manager_dashboard');
        }

        $assignments = $this->accessibleAssignments($user->id);
        $inProgress = (clone $assignments)
            ->whereIn('status', [TaskStatus::TODO, TaskStatus::IN_PROGRESS, TaskStatus::REDO])
            ->count();
        $overdue = (clone $assignments)
            ->where('status', '!=', TaskStatus::COMPLETED)
            ->whereHas('task', fn (Builder $query) => $query->whereDate('deadline', '<', today()))
            ->count();

        $departmentPeriodCards = $reporting->ledDepartmentsFor($user)
            ->map(fn ($department) => [
                'department' => $department,
                'stats' => $reporting->departmentPeriodCards($department),
            ]);

        return view('staff.dashboard', [
            'inProgress' => $inProgress,
            'overdue' => $overdue,
            'personPeriodCards' => $reporting->personPeriodCards($user),
            'departmentPeriodCards' => $departmentPeriodCards,
            'recentTasks' => (clone $assignments)->latest('updated_at')->limit(5)->get(),
        ]);
    }

    private function accessibleAssignments(int $userId): Builder
    {
        return TaskAssignment::query()
            ->with([
                'task.createdBy',
                'task.primaryDepartment.leader',
                'task.delegatedUpdater',
                'task.parentTask',
                'assignee',
                'assigneeDepartment.leader',
            ])
            ->where(function (Builder $query) use ($userId) {
                $query->where('assignee_id', $userId)
                    ->orWhereHas(
                        'assigneeDepartment',
                        fn (Builder $department) => $department->where('leader_id', $userId)
                    );
            });
    }
}
