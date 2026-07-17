@extends('layouts.app')
@section('title', 'Hàng đợi nghiệm thu')
@section('content')
<div class="space-y-6"><div><h1 class="text-2xl font-bold">Hàng đợi nghiệm thu</h1><p class="text-slate-500">{{ $queue->count() }} bản nộp đang chờ xử lý</p></div>
<div class="overflow-hidden rounded-2xl bg-white ring-1 ring-slate-200"><div class="divide-y">
@forelse($queue as $assignment)<div class="flex flex-wrap items-center justify-between gap-4 p-5"><div class="flex items-center gap-4"><img src="{{ $assignment->target_avatar_url }}" class="h-12 w-12 rounded-full"><div><a href="{{ route('manager_task_detail',$assignment->task) }}" class="font-semibold hover:text-blue-600">{{ $assignment->task->title }}</a><p class="text-sm text-slate-500">{{ $assignment->target_display_name }} · nộp {{ $assignment->submitted_at?->format('d/m/Y H:i') ?: '—' }}</p></div></div><a href="{{ route('manager_review_task',$assignment) }}" class="rounded-xl bg-amber-500 px-5 py-2.5 font-semibold text-white">Đánh giá</a></div>
@empty<p class="p-12 text-center text-slate-500">Không có bản nộp nào đang chờ nghiệm thu.</p>@endforelse
</div></div></div>
@endsection
