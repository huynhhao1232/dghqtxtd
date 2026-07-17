@extends('layouts.app')

@section('title', $task->title)

@php
    $submissionData = $assignments->map(fn ($assignment) => [
        'id' => $assignment->id,
        'name' => $assignment->target_display_name,
        'avatar' => $assignment->target_avatar_url,
        'status' => $assignment->status,
        'status_label' => $statusLabels[$assignment->status] ?? $assignment->status,
        'status_pill' => \App\Support\TaskStatus::PILL[$assignment->status] ?? 'bg-gray-100 text-gray-700',
        'proof_url' => $assignment->proof_file
            ? \Illuminate\Support\Facades\Storage::url($assignment->proof_file)
            : null,
        'proof_name' => $assignment->proof_display_name,
        'proof_ext' => $assignment->proof_file
            ? strtolower(pathinfo($assignment->proof_file, PATHINFO_EXTENSION))
            : '',
        'evaluation_result' => $assignment->evaluation_result,
        'evaluation_label' => $assignment->evaluation_result_label,
        'penalty_score' => $assignment->penalty_score,
        'comment' => $assignment->manager_comment ?? '',
        'can_grade' => $assignment->status === \App\Support\TaskStatus::PENDING,
        'review_url' => route('manager_assignment_review', [$task, $assignment]),
    ])->values();
@endphp

@push('styles')
<style>[x-cloak] { display: none !important; }</style>
@endpush

@section('content')
<div x-data="taskAssigneesManager()" x-cloak @keydown.escape.window="closeAssigneeModal()">
<div x-data="documentPreviewer()"
     @preview-document="openPreview($event.detail.url, $event.detail.ext, $event.detail.name)"
     @keydown.escape.window="if (isPreviewOpen) closePreview()">
<div x-data="gradingDrawer()" @keydown.escape.window="closeDrawer()" class="space-y-6">
    <a href="{{ route('manager_manage_tasks') }}"
       class="inline-flex items-center gap-2 text-sm font-medium text-blue-600 transition hover:text-blue-700">
        <i class="fa-solid fa-arrow-left text-xs"></i>
        Quản lý công việc
    </a>

    <section class="mb-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-center">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">
                        {{ \App\Models\Task::CYCLE_CHOICES[$task->cycle] ?? $task->cycle }}
                    </span>
                    @if($task->isTeamTask())
                        <span class="rounded-full bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-700">
                            Chủ trì – Phối hợp
                        </span>
                    @elseif($task->isBatchDepartmentTask())
                        <span class="rounded-full bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-700">
                            Giao đồng loạt
                        </span>
                    @endif
                    <button type="button" @click="openAssigneeModal({{ $task->id }})"
                            title="Quản lý người thực hiện" aria-label="Quản lý người thực hiện"
                            class="flex h-8 w-8 cursor-pointer items-center justify-center rounded-full border-2 border-dashed border-gray-400 text-gray-500 transition hover:border-blue-500 hover:text-blue-500">
                        <i class="fa-solid fa-plus text-xs"></i>
                    </button>
                </div>

                <h1 class="mt-3 text-2xl font-bold tracking-tight text-gray-900">{{ $task->title }}</h1>
                <p class="mt-2 max-w-3xl whitespace-pre-line text-sm leading-6 text-gray-600">
                    {{ $task->description ?: 'Không có mô tả.' }}
                </p>

                <div class="mt-5 flex flex-wrap gap-x-7 gap-y-3 text-sm">
                    <span class="inline-flex items-center gap-2 text-gray-600">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-red-50 text-red-500">
                            <i class="fa-regular fa-calendar"></i>
                        </span>
                        <span>
                            <small class="block text-xs text-gray-400">Hạn chót</small>
                            <b class="font-semibold text-gray-800">{{ $task->deadline->format('d/m/Y') }}</b>
                        </span>
                    </span>
                    <span class="inline-flex items-center gap-2 text-gray-600">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-500">
                            <i class="fa-solid fa-star"></i>
                        </span>
                        <span>
                            <small class="block text-xs text-gray-400">Chuẩn nghiệm thu</small>
                            <b class="font-semibold text-gray-800">Đạt / Làm lại / Trừ 1</b>
                        </span>
                    </span>
                    <span class="inline-flex items-center gap-2 text-gray-600">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
                            <i class="fa-regular fa-user"></i>
                        </span>
                        <span>
                            <small class="block text-xs text-gray-400">Người giao</small>
                            <b class="font-semibold text-gray-800">{{ $task->createdBy }}</b>
                        </span>
                    </span>
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex justify-end">
                    <a href="{{ route('tasks.export_excel', $task) }}"
                       class="inline-flex items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                        <i class="fas fa-file-excel mr-2"></i>
                        Xuất Excel
                    </a>
                </div>
                <div class="rounded-xl border border-emerald-100 bg-emerald-50/60 p-5">
                    <div class="flex items-end justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-emerald-800">Tiến độ nộp bài</p>
                            <p class="mt-1 text-sm text-emerald-700">
                                Đã nộp <b>{{ $submittedCount }}/{{ $totalCount }}</b>
                            </p>
                        </div>
                        <p class="text-3xl font-bold text-emerald-600">{{ $progressPct }}%</p>
                    </div>
                    <div class="mt-4 h-3 overflow-hidden rounded-full bg-emerald-100">
                        <div class="h-full rounded-full bg-emerald-500 transition-all duration-500"
                             style="width: {{ $progressPct }}%"></div>
                    </div>
                </div>
            </div>
        </div>

        @if($task->attachments->isNotEmpty())
            <div class="mt-6 border-t border-gray-100 pt-5">
                <p class="mb-3 text-sm font-semibold text-gray-700">Tệp yêu cầu đính kèm</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($task->attachments as $file)
                        <a href="#"
                           @click.prevent="$dispatch('preview-document', {
                               url: @js(\Illuminate\Support\Facades\Storage::url($file->file)),
                               ext: @js(strtolower(pathinfo($file->file, PATHINFO_EXTENSION))),
                               name: @js($file->display_name),
                           })"
                           class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-blue-700 transition hover:border-blue-300 hover:bg-blue-50">
                            <i class="fa-regular fa-file"></i>
                            <span class="max-w-xs truncate">{{ $file->display_name }}</span>
                            <small class="text-gray-400">{{ $file->size_display }}</small>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-col gap-2 border-b border-gray-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-bold text-gray-900">Danh sách bản nộp</h2>
                <p class="mt-0.5 text-sm text-gray-500">Theo dõi minh chứng và nghiệm thu từng cá nhân/Tổ/Nhóm.</p>
            </div>
            <span class="text-sm text-gray-500" x-text="`${submissions.length} bản phân công`"></span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Người/Tổ thực hiện</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Trạng thái</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Minh chứng</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Kết quả</th>
                        <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Hành động</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <template x-for="submission in submissions" :key="submission.id">
                        <tr class="transition-colors hover:bg-gray-50/70">
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <img :src="submission.avatar" :alt="submission.name"
                                         class="h-10 w-10 rounded-full bg-gray-100 object-cover ring-1 ring-gray-200">
                                    <p class="font-semibold text-gray-900" x-text="submission.name"></p>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <span :class="submission.status_pill"
                                      class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                      x-text="submission.status_label"></span>
                            </td>
                            <td class="px-6 py-4">
                                <a x-show="submission.proof_url" href="#"
                                   @click.prevent="$dispatch('preview-document', {
                                       url: submission.proof_url,
                                       ext: submission.proof_ext,
                                       name: submission.proof_name || 'Minh chứng',
                                   })"
                                   class="inline-flex max-w-xs cursor-pointer items-center gap-2 text-sm font-medium text-blue-600 hover:underline">
                                    <i class="fa-solid fa-paperclip"></i>
                                    <span class="truncate" x-text="submission.proof_name || 'Xem minh chứng'"></span>
                                </a>
                                <span x-show="!submission.proof_url" class="text-sm text-gray-400">Chưa có minh chứng</span>
                            </td>
                            <td class="px-6 py-4">
                                <div x-show="submission.evaluation_result">
                                    <p :class="submission.evaluation_result === 'DAT' ? 'text-emerald-600' : 'text-rose-600'"
                                       class="text-sm font-bold" x-text="submission.evaluation_label"></p>
                                    <p x-show="submission.penalty_score"
                                       class="mt-1 text-xs font-semibold text-rose-500">Trừ 1 điểm thi đua</p>
                                    <p x-show="submission.comment" class="mt-1 max-w-xs truncate text-xs text-gray-500"
                                       x-text="submission.comment" :title="submission.comment"></p>
                                </div>
                                <span x-show="!submission.evaluation_result" class="text-gray-400">—</span>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-right">
                                <button x-show="submission.can_grade" type="button"
                                        @click="openGradingDrawer(submission.id)"
                                        class="rounded-lg border border-blue-300 bg-white px-3 py-1.5 text-sm font-semibold text-blue-700 transition hover:bg-blue-50">
                                    Nghiệm thu
                                </button>
                                <span x-show="!submission.can_grade && submission.evaluation_result"
                                      class="inline-flex items-center gap-1 text-xs font-medium text-gray-400">
                                    <i class="fa-solid fa-check"></i> Đã xử lý
                                </span>
                                <span x-show="!submission.can_grade && !submission.evaluation_result"
                                      class="text-sm text-gray-400">Chờ nộp</span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div x-show="submissions.length === 0" class="border-t border-gray-100 px-6 py-14 text-center">
            <i class="fa-regular fa-folder-open text-3xl text-gray-300"></i>
            <p class="mt-3 text-sm text-gray-500">Nhiệm vụ chưa có bản phân công.</p>
        </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-3">
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:col-span-2">
            <h2 class="font-bold text-gray-900">Thành viên tham gia</h2>
            @if($participations->isNotEmpty())
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach($participations as $participation)
                        <div class="flex items-center gap-3 rounded-xl bg-gray-50 p-3">
                            <img src="{{ $participation->user->avatar_url }}" alt="{{ $participation->user->full_name_vn }}"
                                 class="h-9 w-9 rounded-full bg-gray-100 object-cover">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-800">{{ $participation->user }}</p>
                                <p class="truncate text-xs text-gray-500">
                                    {{ $participation->department?->name ?: '—' }}
                                    · {{ \App\Support\EvaluationResult::label($participation->evaluation) }}
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-3 text-sm text-gray-400">Chưa có thành viên tham gia bổ sung.</p>
            @endif
        </section>

        <aside class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="font-bold text-gray-900">Thông tin Tổ/Nhóm</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div>
                    <dt class="text-gray-400">Chủ trì</dt>
                    <dd class="mt-0.5 font-semibold text-gray-800">{{ $task->primaryDepartment?->name ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-400">Phối hợp</dt>
                    <dd class="mt-0.5 font-semibold text-gray-800">
                        {{ $task->coordinatingDepartments->pluck('name')->implode(', ') ?: '—' }}
                    </dd>
                </div>
            </dl>
        </aside>
    </div>

    <div x-show="isDrawerOpen" x-transition.opacity
         class="fixed inset-0 z-[60] bg-gray-900/40 backdrop-blur-sm"
         @click="closeDrawer()"></div>

    <aside x-show="isDrawerOpen"
           x-transition:enter="transform transition ease-out duration-300"
           x-transition:enter-start="translate-x-full"
           x-transition:enter-end="translate-x-0"
           x-transition:leave="transform transition ease-in duration-200"
           x-transition:leave-start="translate-x-0"
           x-transition:leave-end="translate-x-full"
           class="fixed inset-y-0 right-0 z-[70] flex w-full max-w-md flex-col bg-white shadow-2xl"
           role="dialog" aria-modal="true" aria-labelledby="grading-title">
        <header class="flex items-start justify-between border-b border-gray-200 px-6 py-5">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wider text-blue-600">Nghiệm thu nhiệm vụ</p>
                <h2 id="grading-title" class="mt-1 truncate text-lg font-bold text-gray-900">
                    Chấm — <span x-text="gradeForm.name"></span>
                </h2>
            </div>
            <button type="button" @click="closeDrawer()" aria-label="Đóng"
                    class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <form @submit.prevent="saveGrade()" class="flex min-h-0 flex-1 flex-col">
            <div class="flex-1 space-y-6 overflow-y-auto p-6">
                <div x-show="errors.length" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    <template x-for="error in errors" :key="error">
                        <p x-text="error"></p>
                    </template>
                </div>

                <fieldset>
                    <legend class="mb-3 text-sm font-semibold text-gray-700">Kết quả nghiệm thu</legend>
                    <div class="space-y-3">
                        @foreach($evaluationChoices as $value => $label)
                            <label class="block cursor-pointer">
                                <input type="radio" x-model="gradeForm.evaluation_result"
                                       value="{{ $value }}" class="peer sr-only">
                                <span class="flex items-start gap-3 rounded-xl border-2 border-gray-200 p-4 transition hover:border-blue-300 peer-checked:border-blue-500 peer-checked:bg-blue-50">
                                    <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-gray-300 peer-checked:border-blue-600">
                                        <span class="h-2.5 w-2.5 rounded-full bg-blue-600 opacity-0 peer-checked:opacity-100"></span>
                                    </span>
                                    <span>
                                        <b class="block text-sm text-gray-800">{{ $label }}</b>
                                        @if($value === \App\Support\EvaluationResult::TRE_BI_TRU_DIEM)
                                            <small class="mt-1 block text-rose-600">Áp dụng trừ 1 điểm thi đua.</small>
                                        @endif
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div>
                    <label for="manager-comment" class="mb-2 block text-sm font-semibold text-gray-700">
                        Nhận xét / Đánh giá
                    </label>
                    <textarea id="manager-comment" x-model="gradeForm.manager_comment" rows="6"
                              placeholder="Nhập nhận xét, yêu cầu chỉnh sửa hoặc lý do nghiệm thu..."
                              class="w-full resize-y rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"></textarea>
                </div>
            </div>

            <footer class="flex justify-end gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4">
                <button type="button" @click="closeDrawer()" :disabled="isSaving"
                        class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50">
                    Hủy
                </button>
                <button type="submit" :disabled="isSaving"
                        class="inline-flex min-w-28 items-center justify-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">
                    <i x-show="isSaving" class="fa-solid fa-spinner fa-spin"></i>
                    <span x-text="isSaving ? 'Đang lưu...' : 'Lưu đánh giá'"></span>
                </button>
            </footer>
        </form>
    </aside>

    <div x-show="toast.show" x-transition
         class="fixed right-5 top-5 z-[80] flex max-w-sm items-center gap-3 rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white shadow-xl">
        <i class="fa-solid fa-circle-check text-emerald-400"></i>
        <span x-text="toast.message"></span>
    </div>
</div>

{{-- Modal xem trước tài liệu --}}
<div x-cloak x-show="isPreviewOpen" x-transition.opacity
     class="fixed inset-0 z-[85] flex items-center justify-center bg-gray-900/75 p-4 backdrop-blur-sm"
     role="dialog" aria-modal="true" aria-labelledby="document-preview-title"
     @click.self="closePreview()">
    <div x-show="isPreviewOpen"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="flex h-[85vh] w-11/12 max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
        <header class="flex shrink-0 items-center justify-between border-b border-gray-200 bg-gray-50 px-4 py-3">
            <div class="flex min-w-0 items-center gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                      :class="previewType === 'pdf' ? 'bg-red-100 text-red-600' : (previewType === 'image' ? 'bg-emerald-100 text-emerald-600' : 'bg-blue-100 text-blue-600')">
                    <i class="fas"
                       :class="previewType === 'pdf' ? 'fa-file-pdf' : (previewType === 'image' ? 'fa-file-image' : 'fa-file')"></i>
                </span>
                <h3 id="document-preview-title" class="truncate text-sm font-bold text-gray-900" x-text="fileName"></h3>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a :href="previewUrl" download
                   class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50">
                    <i class="fas fa-download"></i>
                    <span class="hidden sm:inline">Tải về</span>
                </a>
                <button type="button" @click="closePreview()" aria-label="Đóng"
                        class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-200 hover:text-gray-700">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
        </header>

        <div class="relative flex flex-1 items-center justify-center overflow-hidden bg-gray-200">
            <template x-if="previewType === 'pdf'">
                <iframe :src="previewUrl" class="h-full w-full border-0" title="Xem trước PDF"></iframe>
            </template>

            <template x-if="previewType === 'image'">
                <img :src="previewUrl" :alt="fileName" class="max-h-full max-w-full object-contain shadow-sm">
            </template>

            <template x-if="previewType === 'other'">
                <div class="mx-auto max-w-md px-6 text-center">
                    <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-white text-gray-400 shadow-sm">
                        <i class="fas fa-file-alt text-3xl"></i>
                    </span>
                    <p class="mt-4 text-base font-semibold text-gray-800">Định dạng file này không hỗ trợ xem trước.</p>
                    <p class="mt-2 text-sm text-gray-500">Vui lòng tải về máy để mở bằng ứng dụng phù hợp (Word, Excel, …).</p>
                    <a :href="previewUrl" download
                       class="mt-6 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                        <i class="fas fa-download"></i>
                        Tải xuống tệp
                    </a>
                </div>
            </template>
        </div>
    </div>
</div>
</div>

<div x-show="isOpen" x-transition.opacity.duration.200ms
     class="fixed inset-0 z-[90] flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
     role="dialog" aria-modal="true" aria-labelledby="assignee-modal-title"
     @click.self="closeAssigneeModal()">
    <div x-show="isOpen"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-4 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-4 scale-95"
         class="flex max-h-[85vh] w-full max-w-md flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <header class="flex shrink-0 items-start justify-between border-b border-gray-100 px-5 py-4">
            <div>
                <h2 id="assignee-modal-title" class="text-lg font-bold text-gray-900">Quản lý người thực hiện</h2>
                <p class="mt-1 text-sm text-gray-500">
                    <span x-text="currentAssignees.length"></span> đối tượng đang được giao việc
                </p>
            </div>
            <button type="button" @click="closeAssigneeModal()" aria-label="Đóng"
                    class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <div class="relative shrink-0 border-b border-gray-100 p-4">
            <label for="assignee-search" class="sr-only">Tìm người hoặc tổ</label>
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-gray-400"></i>
                <input id="assignee-search" type="search"
                       x-model.debounce.300ms="searchQuery"
                       placeholder="Gõ tên người hoặc tổ để thêm vào..."
                       class="w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-10 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                <i x-show="isSearching"
                   class="fa-solid fa-spinner fa-spin absolute right-3.5 top-1/2 -translate-y-1/2 text-sm text-blue-500"></i>
            </div>
            <p class="mt-2 text-xs text-gray-400"
               x-text="mode === 'user' ? 'Tìm cá nhân chưa được giao nhiệm vụ này.' : 'Tìm Tổ/Nhóm chưa được giao nhiệm vụ này.'"></p>

            <div x-show="searchQuery.length >= 2 && (searchResults.length || hasSearched)"
                 x-transition
                 class="absolute left-4 right-4 top-[5.6rem] z-20 max-h-64 overflow-y-auto rounded-xl border border-gray-200 bg-white p-1.5 shadow-xl">
                <template x-for="result in searchResults" :key="`${result.type}-${result.id}`">
                    <div class="flex items-center gap-3 rounded-lg px-3 py-2.5 hover:bg-gray-50">
                        <img :src="result.avatar" :alt="result.name"
                             class="h-9 w-9 shrink-0 rounded-full bg-gray-100 object-cover ring-1 ring-gray-200">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="truncate text-sm font-semibold text-gray-800" x-text="result.name"></p>
                                <span :class="result.type === 'user' ? 'bg-blue-50 text-blue-700' : 'bg-violet-50 text-violet-700'"
                                      class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                      x-text="result.type === 'user' ? 'Cá nhân' : 'Tổ nhóm'"></span>
                            </div>
                            <p class="truncate text-xs text-gray-500" x-text="result.subtitle"></p>
                        </div>
                        <button type="button" @click="addAssignee(result.type, result.id)"
                                :disabled="actionKey === `${result.type}-${result.id}`"
                                class="inline-flex shrink-0 items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-blue-600 transition hover:bg-blue-50 disabled:opacity-50">
                            <i :class="actionKey === `${result.type}-${result.id}` ? 'fa-spinner fa-spin' : 'fa-plus'"
                               class="fa-solid"></i>
                            Thêm
                        </button>
                    </div>
                </template>
                <p x-show="hasSearched && !isSearching && searchResults.length === 0"
                   class="px-3 py-6 text-center text-sm text-gray-500">
                    Không tìm thấy đối tượng phù hợp.
                </p>
            </div>
        </div>

        <div x-show="errors.length" class="mx-4 mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <template x-for="error in errors" :key="error">
                <p x-text="error"></p>
            </template>
        </div>

        <main class="min-h-56 flex-1 overflow-y-auto p-4">
            <div x-show="isLoading" class="space-y-3">
                <template x-for="index in 3" :key="index">
                    <div class="flex animate-pulse items-center gap-3 p-2">
                        <div class="h-10 w-10 rounded-full bg-gray-200"></div>
                        <div class="flex-1 space-y-2">
                            <div class="h-3 w-2/5 rounded bg-gray-200"></div>
                            <div class="h-2.5 w-1/4 rounded bg-gray-100"></div>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="!isLoading" class="divide-y divide-gray-100">
                <template x-for="assignee in currentAssignees" :key="`${assignee.type}-${assignee.id}`">
                    <div class="flex items-center justify-between gap-3 px-2 py-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <img :src="assignee.avatar" :alt="assignee.name"
                                 class="h-10 w-10 shrink-0 rounded-full bg-gray-100 object-cover ring-1 ring-gray-200">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-sm font-semibold text-gray-800" x-text="assignee.name"></p>
                                    <span :class="assignee.type === 'user' ? 'bg-blue-50 text-blue-700' : 'bg-violet-50 text-violet-700'"
                                          class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                          x-text="assignee.type === 'user' ? 'Cá nhân' : 'Tổ nhóm'"></span>
                                </div>
                                <p class="truncate text-xs text-gray-500" x-text="assignee.subtitle"></p>
                            </div>
                        </div>
                        <button x-show="assignee.can_remove" type="button"
                                @click="removeAssignee(assignee.type, assignee.id)"
                                :disabled="actionKey === `${assignee.type}-${assignee.id}`"
                                :aria-label="`Xóa ${assignee.name}`"
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md p-2 text-gray-400 transition hover:bg-red-50 hover:text-red-500 disabled:opacity-50">
                            <i :class="actionKey === `${assignee.type}-${assignee.id}` ? 'fa-spinner fa-spin' : 'fa-trash'"
                               class="fa-solid text-sm"></i>
                        </button>
                        <span x-show="!assignee.can_remove" title="Không thể xóa Tổ chủ trì"
                              class="flex h-9 w-9 shrink-0 items-center justify-center text-gray-300">
                            <i class="fa-solid fa-lock text-xs"></i>
                        </span>
                    </div>
                </template>

                <div x-show="currentAssignees.length === 0" class="py-10 text-center">
                    <i class="fa-solid fa-user-plus text-3xl text-gray-300"></i>
                    <p class="mt-3 text-sm text-gray-500">Chưa có người thực hiện.</p>
                </div>
            </div>
        </main>
    </div>
</div>

<div x-show="assigneeToast.show" x-transition
     class="fixed right-5 top-5 z-[100] flex max-w-sm items-center gap-3 rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white shadow-xl">
    <i class="fa-solid fa-circle-check text-emerald-400"></i>
    <span x-text="assigneeToast.message"></span>
</div>
</div>
@endsection

@push('scripts')
<script>
const taskSubmissions = {{ \Illuminate\Support\Js::from($submissionData) }};

function documentPreviewer() {
    return {
        isPreviewOpen: false,
        previewUrl: '',
        previewType: '',
        fileName: '',

        openPreview(url, ext, name) {
            if (!url) return;

            const extension = String(ext || '').toLowerCase().replace(/^\./, '');
            this.previewUrl = url;
            this.fileName = name || 'Tài liệu';

            if (extension === 'pdf') {
                this.previewType = 'pdf';
            } else if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(extension)) {
                this.previewType = 'image';
            } else {
                this.previewType = 'other';
            }

            this.isPreviewOpen = true;
        },

        closePreview() {
            this.isPreviewOpen = false;
            this.previewUrl = '';
            this.previewType = '';
            this.fileName = '';
        },
    };
}

function gradingDrawer() {
    return {
        submissions: taskSubmissions,
        isDrawerOpen: false,
        isSaving: false,
        errors: [],
        toast: { show: false, message: '' },
        gradeForm: {
            id: null,
            name: '',
            evaluation_result: '',
            manager_comment: '',
            review_url: '',
        },

        openGradingDrawer(submissionId) {
            const submission = this.submissions.find((item) => Number(item.id) === Number(submissionId));
            if (!submission || !submission.can_grade) return;

            this.gradeForm = {
                id: submission.id,
                name: submission.name,
                evaluation_result: '',
                manager_comment: submission.comment || '',
                review_url: submission.review_url,
            };
            this.errors = [];
            this.isDrawerOpen = true;
        },

        closeDrawer() {
            if (this.isSaving) return;
            this.isDrawerOpen = false;
            this.errors = [];
        },

        async saveGrade() {
            if (!this.gradeForm.evaluation_result) {
                this.errors = ['Vui lòng chọn kết quả nghiệm thu.'];
                return;
            }

            this.isSaving = true;
            this.errors = [];

            try {
                const response = await fetch(this.gradeForm.review_url, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    },
                    body: JSON.stringify({
                        evaluation_result: this.gradeForm.evaluation_result,
                        manager_comment: this.gradeForm.manager_comment,
                    }),
                });
                const payload = await response.json();

                if (response.status === 422) {
                    this.errors = Object.values(payload.errors || {}).flat();
                    return;
                }
                if (!response.ok) throw new Error(payload.message || 'Không thể lưu kết quả nghiệm thu.');

                const index = this.submissions.findIndex((item) => Number(item.id) === Number(payload.assignment.id));
                if (index !== -1) {
                    this.submissions[index] = {
                        ...this.submissions[index],
                        ...payload.assignment,
                    };
                }

                this.isDrawerOpen = false;
                this.showToast(payload.message || 'Đã lưu kết quả nghiệm thu.');
            } catch (error) {
                this.errors = [error.message];
            } finally {
                this.isSaving = false;
            }
        },

        showToast(message) {
            this.toast = { show: true, message };
            window.clearTimeout(this.toastTimer);
            this.toastTimer = window.setTimeout(() => { this.toast.show = false; }, 3500);
        },
    };
}

function taskAssigneesManager() {
    return {
        isOpen: false,
        taskId: null,
        mode: 'user',
        currentAssignees: [],
        searchQuery: '',
        searchResults: [],
        isLoading: false,
        isSearching: false,
        hasSearched: false,
        actionKey: null,
        errors: [],
        assigneeToast: { show: false, message: '' },
        listUrlTemplate: @json(route('task_assignees_api', ['task' => '__TASK__'])),
        searchUrl: @json(route('task_assignees_search_api')),
        storeUrlTemplate: @json(route('task_assignees_store_api', ['task' => '__TASK__'])),
        destroyUrlTemplate: @json(route('task_assignees_destroy_api', ['task' => '__TASK__'])),

        init() {
            this.$watch('searchQuery', () => this.search());
        },

        async openAssigneeModal(id) {
            this.taskId = id;
            this.currentAssignees = [];
            this.searchQuery = '';
            this.searchResults = [];
            this.hasSearched = false;
            this.errors = [];
            this.isOpen = true;
            this.isLoading = true;

            try {
                const response = await fetch(this.taskUrl(this.listUrlTemplate), {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Không thể tải người thực hiện.');
                this.mode = payload.mode;
                this.currentAssignees = payload.assignees;
                this.$nextTick(() => document.getElementById('assignee-search')?.focus());
            } catch (error) {
                this.errors = [error.message];
            } finally {
                this.isLoading = false;
            }
        },

        closeAssigneeModal() {
            if (this.actionKey !== null) return;
            this.isOpen = false;
            this.searchQuery = '';
            this.searchResults = [];
            this.errors = [];
        },

        async search() {
            const query = this.searchQuery.trim();
            if (!this.taskId || query.length < 2) {
                this.searchResults = [];
                this.hasSearched = false;
                return;
            }

            this.isSearching = true;
            try {
                const url = new URL(this.searchUrl, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('task_id', this.taskId);
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Không thể tìm kiếm.');
                if (query === this.searchQuery.trim()) {
                    this.searchResults = payload.results;
                    this.hasSearched = true;
                }
            } catch (error) {
                this.errors = [error.message];
            } finally {
                this.isSearching = false;
            }
        },

        async addAssignee(type, id) {
            if (this.actionKey !== null) return;
            this.actionKey = `${type}-${id}`;
            this.errors = [];

            try {
                const response = await fetch(this.taskUrl(this.storeUrlTemplate), {
                    method: 'POST',
                    headers: this.jsonHeaders(),
                    body: JSON.stringify({ type, id }),
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(this.errorMessage(payload, 'Không thể thêm người thực hiện.'));

                this.currentAssignees.push(payload.assignee);
                this.searchResults = this.searchResults.filter(
                    (item) => !(item.type === type && Number(item.id) === Number(id))
                );
                this.showAssigneeToast(payload.message);
            } catch (error) {
                this.errors = [error.message];
            } finally {
                this.actionKey = null;
            }
        },

        async removeAssignee(type, id) {
            const assignee = this.currentAssignees.find(
                (item) => item.type === type && Number(item.id) === Number(id)
            );
            if (!assignee?.can_remove || !window.confirm(`Xóa ${assignee.name} khỏi nhiệm vụ?`)) return;
            if (this.actionKey !== null) return;
            this.actionKey = `${type}-${id}`;
            this.errors = [];

            try {
                const response = await fetch(this.taskUrl(this.destroyUrlTemplate), {
                    method: 'DELETE',
                    headers: this.jsonHeaders(),
                    body: JSON.stringify({ type, id }),
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(this.errorMessage(payload, 'Không thể xóa người thực hiện.'));

                this.currentAssignees = this.currentAssignees.filter(
                    (item) => !(item.type === type && Number(item.id) === Number(id))
                );
                this.showAssigneeToast(payload.message);
                if (this.searchQuery.trim().length >= 2) this.search();
            } catch (error) {
                this.errors = [error.message];
            } finally {
                this.actionKey = null;
            }
        },

        taskUrl(template) {
            return template.replace('__TASK__', this.taskId);
        },

        jsonHeaders() {
            return {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            };
        },

        errorMessage(payload, fallback) {
            return Object.values(payload.errors || {}).flat()[0] || payload.message || fallback;
        },

        showAssigneeToast(message) {
            this.assigneeToast = { show: true, message };
            window.clearTimeout(this.assigneeToastTimer);
            this.assigneeToastTimer = window.setTimeout(() => {
                this.assigneeToast.show = false;
            }, 3500);
        },
    };
}
</script>
@endpush
