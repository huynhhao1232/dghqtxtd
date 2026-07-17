@extends('layouts.app')

@section('title', 'Việc của tôi — EduEval')

@section('content')
<div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
    <div class="flex flex-col gap-4 border-b border-gray-100 p-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Việc của tôi</h1>
            <p class="mt-1 text-sm text-gray-500">Danh sách công việc được phân công</p>
        </div>
        <form method="get">
            <label for="status-filter" class="sr-only">Lọc trạng thái</label>
            <select id="status-filter" name="status" onchange="this.form.submit()"
                    class="min-w-[180px] rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-700 focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                <option value="">Tất cả trạng thái</option>
                @foreach ($statusChoices as $value => $label)
                    <option value="{{ $value }}" @selected($statusFilter === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full">
            <thead class="bg-gray-50">
                <tr>
                    @foreach (['Công việc', 'Người giao', 'Thời hạn', 'Chu kỳ', 'Trạng thái', 'Kết quả'] as $heading)
                        <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">{{ $heading }}</th>
                    @endforeach
                    <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Hành động</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($assignments as $assignment)
                    <tr class="border-b border-gray-100 transition-colors hover:bg-gray-50/50">
                        <td class="px-6 py-4">
                            <div class="flex flex-col gap-1">
                                <span class="font-medium text-gray-900">{{ $assignment->task->title }}</span>
                                @if ($assignment->task->is_subtask && $assignment->task->parentTask)
                                    <span class="w-fit rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                        <i class="fas fa-sitemap"></i> Từ: {{ $assignment->task->parentTask->title }}
                                    </span>
                                @endif
                                @if ($assignment->is_department_target)
                                    <span class="w-fit rounded-md bg-teal-50 px-2 py-0.5 text-[11px] text-teal-700">
                                        <i class="fas fa-users"></i> Bản nộp của tổ: {{ $assignment->assigneeDepartment->name }}
                                    </span>
                                @elseif ($assignment->task->primaryDepartment)
                                    <span class="text-xs text-blue-600">
                                        <i class="fas fa-flag"></i> {{ $assignment->task->primaryDepartment->name }}
                                        @if ((int) $assignment->task->delegated_updater_id === (int) auth()->id())
                                            <i class="fas fa-crown text-amber-500" title="Được ủy quyền báo cáo"></i>
                                        @endif
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $assignment->task->createdBy }}</td>
                        <td @class(['px-6 py-4 text-sm', 'font-medium text-red-600' => $assignment->is_overdue, 'text-gray-600' => !$assignment->is_overdue])>
                            {{ $assignment->task->deadline->format('d/m/Y') }}
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ \App\Models\Task::CYCLE_CHOICES[$assignment->task->cycle] ?? $assignment->task->cycle }}</td>
                        <td class="px-6 py-4">
                            @php
                                $pill = $assignment->is_overdue && !in_array($assignment->status, [\App\Support\TaskStatus::COMPLETED, \App\Support\TaskStatus::PENDING], true)
                                    ? 'bg-red-100 text-red-700'
                                    : (\App\Support\TaskStatus::PILL[$assignment->status] ?? 'bg-gray-100 text-gray-700');
                            @endphp
                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-medium {{ $pill }}">
                                {{ $assignment->is_overdue && !in_array($assignment->status, [\App\Support\TaskStatus::COMPLETED, \App\Support\TaskStatus::PENDING], true) ? 'Quá hạn' : \App\Support\TaskStatus::label($assignment->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm font-semibold text-gray-800">
                            {{ \App\Support\EvaluationResult::label($assignment->evaluation_result) }}
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('staff_task_detail', $assignment->id) }}"
                               class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:border-blue-500 hover:text-blue-600">
                                <i class="fas fa-eye"></i> Xem
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-16 text-center text-gray-400">
                            <i class="fas fa-inbox mb-3 block text-3xl text-gray-300"></i>Không có công việc nào
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
