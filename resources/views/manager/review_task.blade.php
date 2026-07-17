@extends('layouts.app')
@section('title', 'Nghiệm thu nhiệm vụ')
@section('content')
<div class="mx-auto max-w-4xl space-y-5"><a href="{{ route('manager_review_queue') }}" class="text-sm text-blue-600">← Hàng đợi nghiệm thu</a>
<div class="rounded-2xl bg-white p-6 ring-1 ring-slate-200"><h1 class="text-2xl font-bold">{{ $task->title }}</h1><p class="mt-2 whitespace-pre-line text-slate-600">{{ $task->description }}</p><div class="mt-4 flex flex-wrap gap-4 text-sm"><span><b>Người/Tổ nộp:</b> {{ $assignment->target_display_name }}</span><span><b>Hạn:</b> {{ $task->deadline->format('d/m/Y') }}</span></div>@if($assignment->proof_file)<a href="{{ \Illuminate\Support\Facades\Storage::url($assignment->proof_file) }}" target="_blank" class="mt-4 inline-block rounded-xl bg-blue-50 px-4 py-2 text-blue-700">📎 Mở minh chứng</a>@endif</div>
@if($participations->isNotEmpty())<div class="rounded-2xl bg-white p-5 ring-1 ring-slate-200"><h2 class="mb-3 font-semibold">Thành viên tham gia</h2><div class="grid gap-2 md:grid-cols-2">@foreach($participations as $part)<div class="rounded-xl bg-slate-50 p-3">{{ $part->user }} <span class="text-sm text-slate-500">· {{ $part->department?->name }}</span></div>@endforeach</div></div>@endif
<form method="POST" class="rounded-2xl bg-white p-6 ring-1 ring-slate-200">@csrf<h2 class="mb-4 text-lg font-semibold">Kết quả nghiệm thu</h2>
<div class="grid gap-3">@foreach($evaluationChoices as $value=>$label)<label class="cursor-pointer rounded-xl border p-4 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50"><input type="radio" name="evaluation_result" value="{{ $value }}" @checked(old('evaluation_result')===$value) required class="mr-2"><b>{{ $label }}</b></label>@endforeach</div>
@error('evaluation_result')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
<textarea name="manager_comment" rows="5" class="mt-4 w-full rounded-xl border-slate-300" placeholder="Nhận xét, yêu cầu bổ sung...">{{ old('manager_comment') }}</textarea>
<div class="mt-4 flex justify-end gap-2"><a href="{{ route('manager_review_queue') }}" class="rounded-xl border px-5 py-2.5">Hủy</a><button class="rounded-xl bg-blue-600 px-6 py-2.5 font-semibold text-white">Xác nhận đánh giá</button></div></form></div>
@endsection
