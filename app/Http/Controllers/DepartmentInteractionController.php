<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\GroupPost;
use App\Models\Task;
use App\Support\TaskStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DepartmentInteractionController extends Controller
{
    public function __invoke(Request $request, Department $department): View|RedirectResponse
    {
        abort_unless($department->userCanAccess($request->user()), 403, 'Bạn không có quyền truy cập khu vực nhóm này.');
        if ($request->isMethod('post')) {
            $data = $request->validate(['content' => ['required', 'string', 'max:5000']]);
            GroupPost::create($data + ['department_id' => $department->id, 'author_id' => $request->user()->id]);

            return back()->with('success', 'Đã đăng tin lên bảng tin nhóm.');
        }

        $department->load(['leader', 'members']);
        $members = $department->members->where('is_active', true)->sortBy(
            fn ($u) => (($u->id === $department->leader_id) ? '0' : '1').(string) $u
        )->values();
        $tasks = Task::with(['primaryDepartment', 'coordinatingDepartments', 'assignments'])
            ->where(fn ($q) => $q->where('primary_department_id', $department->id)
                ->orWhereHas('coordinatingDepartments', fn ($d) => $d->where('departments.id', $department->id)))
            ->latest()->limit(5)->get()->map(fn ($task) => [
                'task' => $task,
                'status' => $task->getCanonicalAssignment()?->status ?? TaskStatus::TODO,
                'status_label' => TaskStatus::label($task->getCanonicalAssignment()?->status ?? TaskStatus::TODO),
            ]);

        return view('accounts.department_interaction', [
            'department' => $department, 'members' => $members, 'taskCards' => $tasks,
            'posts' => GroupPost::with('author')->where('department_id', $department->id)->latest()->limit(50)->get(),
        ]);
    }
}
