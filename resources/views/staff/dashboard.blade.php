@extends('layouts.app')

@section('title', 'Dashboard Nhân viên — EduEval')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Tổng quan công việc</h1>
        <p class="mt-1 text-sm text-gray-500">Xin chào, {{ auth()->user()->full_name_vn }}</p>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-semibold uppercase text-gray-400">Việc đang làm</p>
            <p class="mt-1 text-3xl font-bold text-blue-700">{{ $inProgress }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <p class="text-xs font-semibold uppercase text-gray-400">Việc quá hạn</p>
            <p class="mt-1 text-3xl font-bold text-red-500">{{ $overdue }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:col-span-2">
            <p class="text-xs font-semibold uppercase text-gray-400">Kết quả cá nhân</p>
            <p class="mt-1 text-xs text-gray-400">Năm học {{ $personPeriodCards['academic_year_label'] }}</p>
            <div class="mt-3 grid grid-cols-3 gap-3">
                @foreach (['month' => 'Tháng', 'quarter' => 'Quý', 'academic_year' => 'Năm học'] as $period => $label)
                    <div>
                        <p class="text-[11px] text-gray-400">{{ $label }} · Đạt</p>
                        <p class="text-xl font-bold text-emerald-600">{{ $personPeriodCards[$period]['dat_count'] }}</p>
                        <p class="text-[11px] text-rose-500">Trừ {{ $personPeriodCards[$period]['penalty_total'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    @if ($departmentPeriodCards->isNotEmpty())
        <div class="space-y-4">
            <h2 class="text-base font-semibold text-gray-800">Kết quả Tổ/Nhóm (tách biệt cá nhân)</h2>
            @foreach ($departmentPeriodCards as $item)
                <div class="rounded-xl border border-teal-100 bg-white p-5 shadow-sm">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-teal-800">
                            <i class="fas fa-users mr-1"></i>{{ $item['department']->name }}
                        </h3>
                        <span class="text-xs text-gray-400">NH {{ $item['stats']['academic_year_label'] }}</span>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        @foreach (['month' => 'Tháng', 'quarter' => 'Quý', 'academic_year' => 'Năm học'] as $period => $label)
                            <div>
                                <p class="text-[11px] text-gray-400">{{ $label }} · Đạt</p>
                                <p class="text-lg font-bold text-emerald-600">{{ $item['stats'][$period]['dat_count'] }}</p>
                                <p class="text-[11px] text-rose-500">Trừ {{ $item['stats'][$period]['penalty_total'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-semibold text-gray-800">Công việc gần đây</h2>
            <a href="{{ route('staff_my_tasks') }}" class="text-sm text-blue-700 hover:underline">Xem tất cả</a>
        </div>
        <ul class="divide-y divide-gray-100">
            @forelse ($recentTasks as $item)
                <li class="flex items-center justify-between gap-4 py-3">
                    <div class="min-w-0">
                        <a href="{{ route('staff_task_detail', $item->id) }}" class="text-sm font-medium text-gray-800 hover:text-blue-700">
                            {{ $item->task->title }}
                        </a>
                        <p class="mt-0.5 text-xs text-gray-400">Hạn: {{ $item->task->deadline->format('d/m/Y') }}</p>
                    </div>
                    <span @class([
                        'shrink-0 rounded-full px-2 py-1 text-xs',
                        'bg-emerald-50 text-emerald-700' => $item->status === \App\Support\TaskStatus::COMPLETED,
                        'bg-amber-50 text-amber-700' => $item->status === \App\Support\TaskStatus::PENDING,
                        'bg-red-50 text-red-700' => $item->is_overdue,
                        'bg-blue-50 text-blue-700' => !$item->is_overdue && !in_array($item->status, [\App\Support\TaskStatus::COMPLETED, \App\Support\TaskStatus::PENDING], true),
                    ])>{{ \App\Support\TaskStatus::label($item->status) }}</span>
                </li>
            @empty
                <li class="py-8 text-center text-sm text-gray-400">Chưa có công việc nào</li>
            @endforelse
        </ul>
    </div>
</div>
@endsection
