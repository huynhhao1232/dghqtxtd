<?php

namespace App\Http\Controllers;

use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\ReportingService;
use App\Support\TaskStatus;
use Illuminate\View\View;

class ManagerDashboardController extends Controller
{
    public function index(ReportingService $reporting): View
    {
        $assignments = TaskAssignment::query();
        $total = (clone $assignments)->count();
        $completed = (clone $assignments)->where('status', TaskStatus::COMPLETED)->count();

        return view('manager.dashboard', [
            'periodStats' => $reporting->systemPeriodCards(),
            'completionRate' => $total ? round($completed / $total * 100, 1) : 0,
            'totalTasks' => $total,
            'completedTasks' => $completed,
            'pendingCount' => (clone $assignments)->where('status', TaskStatus::PENDING)
                ->whereHas('task', fn ($q) => $q->where('is_subtask', false))->count(),
            'staffCount' => User::where('is_manager', false)->where('is_active', true)->count(),
            'overdueList' => TaskAssignment::with(['task', 'assignee', 'assigneeDepartment.leader'])
                ->where('status', '!=', TaskStatus::COMPLETED)
                ->whereHas('task', fn ($q) => $q->whereDate('deadline', '<', today()))
                ->get()->sortBy('task.deadline')->take(10),
        ]);
    }
}
