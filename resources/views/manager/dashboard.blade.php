@extends('layouts.app')
@section('title', 'Tổng quan quản lý')
@section('content')
<div class="space-y-6">
    <div><h1 class="text-2xl font-bold text-slate-900">Tổng quan</h1><p class="text-slate-500">Theo dõi tiến độ toàn hệ thống</p></div>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach([
            ['Tỷ lệ hoàn thành', $completionRate.'%', $completedTasks.'/'.$totalTasks.' phân công', 'blue'],
            ['Chờ nghiệm thu', $pendingCount, 'Nhiệm vụ gốc', 'amber'],
            ['Nhân sự hoạt động', $staffCount, 'Viên chức', 'emerald'],
            ['Việc quá hạn', $overdueList->count(), 'Chưa hoàn thành', 'rose'],
        ] as [$label,$value,$sub,$color])
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-2 text-3xl font-bold text-{{ $color }}-600">{{ $value }}</p><p class="mt-1 text-xs text-slate-400">{{ $sub }}</p>
        </div>
        @endforeach
    </div>
    <div class="grid gap-4 md:grid-cols-3">
        @foreach(['month'=>'Tháng này','quarter'=>'Quý này','academic_year'=>'Năm học '.$periodStats['academic_year_label']] as $key=>$label)
        <div class="rounded-2xl bg-teal-700 p-5 text-white"><p class="text-sm text-teal-100">{{ $label }}</p>
            <div class="mt-3 flex items-end justify-between"><span class="text-3xl font-bold">{{ $periodStats[$key]['dat_count'] }} Đạt</span><span class="rounded-full bg-white/15 px-3 py-1 text-sm">Trừ {{ $periodStats[$key]['penalty_total'] }}</span></div>
        </div>
        @endforeach
    </div>
    <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
        <div class="flex items-center justify-between border-b px-5 py-4"><h2 class="font-semibold">Công việc quá hạn</h2><a href="{{ route('manager_manage_tasks', ['status'=>'overdue']) }}" class="text-sm text-blue-600">Xem tất cả</a></div>
        <div class="divide-y">
            @forelse($overdueList as $assignment)
            <a href="{{ route('manager_task_detail', $assignment->task) }}" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                <div><p class="font-medium">{{ $assignment->task->title }}</p><p class="text-sm text-slate-500">{{ $assignment->target_display_name }}</p></div>
                <span class="whitespace-nowrap text-sm font-medium text-rose-600">{{ $assignment->task->deadline->format('d/m/Y') }}</span>
            </a>
            @empty <p class="p-8 text-center text-slate-500">Không có công việc quá hạn.</p> @endforelse
        </div>
    </section>
</div>
@endsection
