@extends('layouts.app')

@section('title', $task->title.' — EduEval')

@section('content')
<div class="mx-auto max-w-4xl space-y-5">
    <a href="{{ route('staff_my_tasks') }}" class="inline-flex items-center gap-2 text-sm font-medium text-gray-500 hover:text-blue-700">
        <i class="fas fa-arrow-left"></i> Quay lại
    </a>

    <section class="mb-6 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <h1 class="mb-6 text-2xl font-bold text-gray-900">{{ $task->title }}</h1>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Người giao</p>
                <p class="font-semibold text-gray-900">
                    <i class="fas fa-user-circle mr-1 text-gray-400"></i>{{ $task->createdBy }}
                </p>
            </div>
            <div>
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Thời hạn</p>
                <p @class(['font-semibold', 'text-red-600' => $assignment->is_overdue, 'text-gray-900' => !$assignment->is_overdue])>
                    <i class="fas fa-calendar-alt mr-1 text-gray-400"></i>{{ $task->deadline->format('d/m/Y') }}
                </p>
            </div>
            <div>
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Chu kỳ</p>
                <p class="font-semibold text-gray-900">{{ \App\Models\Task::CYCLE_CHOICES[$task->cycle] ?? $task->cycle }}</p>
            </div>
            <div>
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Kết quả</p>
                <p class="font-semibold text-gray-900">{{ \App\Support\EvaluationResult::label($assignment->evaluation_result) }}</p>
            </div>
        </div>

        <div class="mt-5 flex flex-wrap gap-1.5 border-t border-gray-100 pt-4">
                @if ($isSubtask && $task->parentTask)
                    <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs text-slate-700"><i class="fas fa-sitemap"></i> Từ: {{ $task->parentTask->title }}</span>
                @endif
                @if ($isDepartmentAssignment)
                    <span class="rounded-lg bg-teal-50 px-2.5 py-1 text-xs text-teal-700"><i class="fas fa-users"></i> Bản nộp: {{ $myAssignment->assigneeDepartment->name }}</span>
                @endif
                @if ($task->primaryDepartment)
                    <span class="rounded-lg bg-blue-50 px-2.5 py-1 text-xs text-blue-700"><i class="fas fa-flag"></i> Chủ trì: {{ $task->primaryDepartment->name }}</span>
                @endif
                @foreach ($task->coordinatingDepartments as $department)
                    <span class="rounded-lg bg-emerald-50 px-2.5 py-1 text-xs text-emerald-700"><i class="fas fa-handshake"></i> {{ $department->name }}</span>
                @endforeach
        </div>

        @if ($isDepartmentAssignment)
            <div class="mt-3 rounded-lg border border-teal-100 bg-teal-50 px-3 py-2 text-sm text-teal-900">
                Bạn đại diện tổ <strong>{{ $myAssignment->assigneeDepartment->name }}</strong> cập nhật bản nộp độc lập của tổ.
            </div>
        @elseif ($isCoordLeader)
            <div class="mt-3 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                Bạn là Trưởng tổ Phối hợp{{ $coordDepartment ? ' ('.$coordDepartment->name.')' : '.' }}
            </div>
        @elseif ($isPrimaryLeader)
            <div class="mt-3 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm text-blue-800">
                Bạn là Trưởng tổ Chủ trì. Theo dõi minh chứng phối hợp và chốt tiến độ gửi Lãnh đạo.
            </div>
        @elseif ($isDelegatedUpdater)
            <div class="mt-3 rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                <i class="fas fa-crown"></i> Bạn được ủy quyền cập nhật tiến độ và minh chứng tổng hợp.
            </div>
        @endif

        @if ($needsEvaluation)
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-violet-100 bg-violet-50 px-3 py-2 text-sm text-violet-900">
                <span><i class="fas fa-clipboard-check"></i> Hãy đánh giá từng cá nhân sau nghiệm thu.</span>
                <button type="button" data-open="evaluation-modal" class="rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white">Đánh giá ngay</button>
            </div>
        @endif

        @if ($task->description)
            <div class="mt-5 whitespace-pre-line rounded-xl bg-gray-50 p-4 text-sm leading-relaxed text-gray-700">{{ $task->description }}</div>
        @endif
        @if ($task->attachments->isNotEmpty())
            <div class="mt-4">
                <p class="mb-2 text-xs uppercase text-gray-500">File đính kèm</p>
                @foreach ($task->attachments as $attachment)
                    <a href="{{ \Illuminate\Support\Facades\Storage::url($attachment->file) }}" target="_blank" class="mr-4 text-sm text-blue-700 hover:underline">
                        <i class="fas fa-paperclip"></i> {{ $attachment->display_name }} ({{ $attachment->size_display }})
                    </a>
                @endforeach
            </div>
        @endif
        @if ($assignment->manager_comment)
            <div class="mt-4 rounded-lg border border-amber-100 bg-amber-50 p-4 text-sm text-amber-900">
                <strong><i class="fas fa-comment-dots"></i> Nhận xét lãnh đạo</strong>
                <p class="mt-1 whitespace-pre-line">{{ $assignment->manager_comment }}</p>
            </div>
        @endif
    </section>

    @if ($canManageSubtasks)
        <section class="space-y-4 rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-gray-800"><i class="fas fa-sitemap mr-2 text-blue-700"></i>Phân chia công việc</h2>
                    <p class="mt-1 text-xs text-gray-500">Chẻ nhiệm vụ gốc thành việc nhỏ và giao thành viên.</p>
                </div>
                @if ($subtaskAssigneeOptions->isNotEmpty() && !in_array($assignment->status, [\App\Support\TaskStatus::COMPLETED, \App\Support\TaskStatus::PENDING], true))
                    <button type="button" data-open="subtask-modal" class="rounded-lg bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white"><i class="fas fa-plus"></i> Tạo nhiệm vụ con</button>
                @endif
            </div>
            @if ($subtaskTotal)
                <div>
                    <div class="mb-1.5 flex justify-between text-xs text-gray-500"><span>Tiến độ phân rã</span><strong>{{ $subtaskDone }}/{{ $subtaskTotal }} ({{ $subtaskPercent }}%)</strong></div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-gray-100"><div class="h-full bg-blue-500" style="width: {{ $subtaskPercent }}%"></div></div>
                </div>
            @endif
            <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-100">
                @forelse ($subtaskRows as $row)
                    <li class="p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <strong class="text-sm text-gray-900">{{ $row['task']->title }}</strong>
                            <span class="rounded-full px-2 py-0.5 text-[11px] {{ $row['statusPill'] }}">{{ $row['statusLabel'] }}</span>
                            @if ($row['isOverdue'])<span class="text-[11px] text-red-600">Quá hạn</span>@endif
                            <div class="ml-auto">
                                <button type="button" class="edit-subtask p-2 text-blue-600" data-url="{{ route('staff_subtask_edit', $row['task']->id) }}" data-title="{{ $row['task']->title }}" data-deadline="{{ $row['task']->deadline->format('Y-m-d') }}"><i class="fas fa-pen"></i></button>
                                <form method="post" action="{{ route('staff_subtask_delete', $row['task']->id) }}" class="inline" onsubmit="return confirm('Xóa nhiệm vụ con này?')">@csrf<button class="p-2 text-red-600"><i class="fas fa-trash"></i></button></form>
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-gray-500"><i class="fas fa-calendar"></i> {{ $row['task']->deadline->format('d/m/Y') }}</p>
                        <div class="mt-3 space-y-2">
                            @foreach ($row['assignments'] as $childAssignment)
                                <div class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs">
                                    <img src="{{ $childAssignment->assignee->avatar_url }}" class="h-7 w-7 rounded-full" alt="">
                                    <strong>{{ $childAssignment->assignee }}</strong>
                                    <span>{{ \App\Support\TaskStatus::label($childAssignment->status) }}</span>
                                    @if ($childAssignment->proof_file)
                                        <a target="_blank" href="{{ \Illuminate\Support\Facades\Storage::url($childAssignment->proof_file) }}" class="text-blue-700"><i class="fas fa-paperclip"></i> Minh chứng</a>
                                    @endif
                                    @if ($childAssignment->status === \App\Support\TaskStatus::PENDING)
                                        <form method="post" action="{{ route('staff_subtask_review', $childAssignment->id) }}" class="ml-auto flex flex-wrap items-center gap-2">
                                            @csrf
                                            @foreach (['DAT' => 'Đạt', 'CHO_LAM_LAI' => 'Làm lại', 'TRE_BI_TRU_DIEM' => 'Trừ 1'] as $value => $label)
                                                <label><input type="radio" name="evaluation_result" value="{{ $value }}" @checked($loop->first) required> {{ $label }}</label>
                                            @endforeach
                                            <input name="manager_comment" maxlength="2000" placeholder="Nhận xét..." class="w-32 rounded border-gray-300 px-2 py-1">
                                            <button class="rounded bg-emerald-600 px-2.5 py-1 text-white">Nghiệm thu</button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </li>
                @empty
                    <li class="p-10 text-center text-sm text-gray-400">Chưa có nhiệm vụ con.</li>
                @endforelse
            </ul>
        </section>
    @endif

    @if ($canManageMembers)
        <section class="space-y-5 rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-gray-800"><i class="fas fa-user-friends mr-2 text-blue-700"></i>Thành viên thực hiện</h2>
                @if ($availableMembers->isNotEmpty() && !in_array($assignment->status, [\App\Support\TaskStatus::COMPLETED, \App\Support\TaskStatus::PENDING], true))
                    <button type="button" data-open="members-modal" class="rounded-lg bg-blue-700 px-3 py-2 text-sm text-white"><i class="fas fa-user-plus"></i> Thêm thành viên</button>
                @endif
            </div>
            @if ($isPrimaryLeader)
                <div><p class="mb-2 text-xs font-semibold uppercase text-blue-600">Tổ Chủ trì</p>@include('staff._participation_list', ['items' => $leadParticipations, 'showDelegate' => true, 'canManage' => true])</div>
                <div><p class="mb-2 text-xs font-semibold uppercase text-emerald-600">Tổ Phối hợp</p>@include('staff._participation_list', ['items' => $coordParticipations, 'showDelegate' => false, 'canManage' => false])</div>
            @else
                @include('staff._participation_list', ['items' => $myParticipations, 'showDelegate' => false, 'canManage' => true])
            @endif
        </section>
    @elseif ($isTeamTask && ($leadParticipations->isNotEmpty() || $coordParticipations->isNotEmpty()))
        <section class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold text-gray-800">Thành viên thực hiện</h2>
            <div class="flex flex-wrap gap-2">
                @foreach ($leadParticipations->merge($coordParticipations) as $participation)
                    <span class="rounded-lg bg-blue-50 px-2.5 py-1.5 text-sm text-blue-800">{{ $participation->user }}</span>
                @endforeach
            </div>
        </section>
    @endif

    @if ($isPrimaryLeader || $isDelegatedUpdater)
        <section class="rounded-xl border border-gray-100 bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold text-gray-800"><i class="fas fa-inbox mr-2 text-emerald-600"></i>Minh chứng từ Tổ Phối hợp</h2>
            @forelse ($coordProofs as $proof)
                <div class="mb-2 flex items-center gap-3 rounded-xl bg-gray-50 px-3 py-2.5">
                    <div class="flex-1"><strong class="text-sm">{{ $proof->department->name }}</strong><p class="text-xs text-gray-500">{{ $proof->uploadedBy }} · {{ $proof->updated_at->format('d/m/Y H:i') }}</p><p class="text-xs">{{ $proof->notes }}</p></div>
                    <a target="_blank" href="{{ \Illuminate\Support\Facades\Storage::url($proof->proof_file) }}" class="text-sm text-blue-700"><i class="fas fa-eye"></i> Xem</a>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-gray-400">Chưa có minh chứng phối hợp.</p>
            @endforelse
        </section>
    @endif

    @if ($canSubmitCoordProof)
        <section class="rounded-xl border border-emerald-100 bg-white p-6 shadow-sm">
            <h2 class="mb-4 font-semibold text-gray-800"><i class="fas fa-upload mr-2 text-emerald-600"></i>Nộp minh chứng cho Đơn vị chủ trì</h2>
            <form method="post" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <input type="hidden" name="action" value="submit_coord_proof">
                <textarea name="coord_notes" rows="3" placeholder="Ghi chú" class="w-full rounded-lg border-gray-300">{{ old('coord_notes', $myCoordProof?->notes) }}</textarea>
                <input type="file" name="coord_proof_file" @required(!$myCoordProof) class="block w-full text-sm">
                @if ($myCoordProof)<a target="_blank" href="{{ \Illuminate\Support\Facades\Storage::url($myCoordProof->proof_file) }}" class="text-sm text-blue-700">File hiện tại: {{ $myCoordProof->display_name }}</a>@endif
                <button class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white"><i class="fas fa-paper-plane"></i> Nộp minh chứng</button>
            </form>
        </section>
    @endif

    @if ($assignment->status === \App\Support\TaskStatus::COMPLETED)
        <div class="rounded-xl border border-green-200 bg-green-50 p-5 text-sm text-green-800"><i class="fas fa-check-circle mr-2"></i>Công việc đã được nghiệm thu.</div>
    @elseif ($canUpdate)
        <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="mb-6">
                <h2 class="text-lg font-bold text-gray-900"><i class="fas fa-tasks mr-2 text-blue-600"></i>Cập nhật &amp; chốt tiến độ</h2>
                <p class="mt-1 text-sm text-gray-500">Cập nhật trạng thái, ghi chú và minh chứng mới nhất của công việc.</p>
            </div>
            <form method="post" enctype="multipart/form-data" class="space-y-6">
                @csrf
                <input type="hidden" name="action" value="update_progress">
                <div>
                    <label class="mb-3 block text-sm font-semibold text-gray-700">Trạng thái công việc</label>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        @foreach ($statusChoices as $value => $label)
                            @php
                                $isTodo = $value === \App\Support\TaskStatus::TODO;
                                $isDoing = $value === \App\Support\TaskStatus::IN_PROGRESS;
                                $icon = $isTodo ? 'fa-hourglass-start' : ($isDoing ? 'fa-spinner' : 'fa-check-circle');
                                $checkedClasses = $isTodo
                                    ? 'peer-checked:border-gray-500 peer-checked:bg-gray-100 peer-checked:text-gray-800'
                                    : ($isDoing
                                        ? 'peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-700'
                                        : 'peer-checked:border-green-500 peer-checked:bg-green-50 peer-checked:text-green-700');
                            @endphp
                            <label class="cursor-pointer">
                                <input type="radio" name="status" value="{{ $value }}" class="peer sr-only" @checked(old('status', $assignment->status) === $value) required>
                                <span class="flex min-h-20 flex-col items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-3 text-center text-sm font-semibold text-gray-600 transition-all hover:border-gray-300 hover:shadow-sm {{ $checkedClasses }}">
                                    <i class="fas {{ $icon }} text-lg"></i>
                                    {{ $value === \App\Support\TaskStatus::PENDING ? 'Chốt tiến độ' : $label }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('status')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="progress-notes" class="mb-2 block text-sm font-semibold text-gray-700">Ghi chú tiến độ</label>
                    <textarea id="progress-notes" name="notes" rows="5" maxlength="5000"
                              placeholder="Mô tả công việc đã thực hiện, kết quả hoặc khó khăn cần hỗ trợ..."
                              class="w-full rounded-xl border p-4 text-sm outline-none transition-colors focus:bg-white focus:ring-2 focus:ring-blue-500 {{ $errors->has('notes') ? 'border-red-400 bg-red-50' : 'border-gray-300 bg-gray-50' }}">{{ old('notes', $assignment->notes) }}</textarea>
                    @error('notes')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div x-data="progressFileUploader({{ $proofMaxMb }})">
                    <label class="mb-2 block text-sm font-semibold text-gray-700">Minh chứng công việc</label>
                    <div class="cursor-pointer rounded-xl border-2 border-dashed border-gray-300 bg-gray-50 p-7 text-center transition-colors hover:border-blue-400 hover:bg-blue-50/50"
                         @click="$refs.fileInput.click()"
                         @dragover.prevent
                         @dragenter.prevent
                         @drop.prevent="addFile($event)">
                        <input x-ref="fileInput" type="file" name="proof_file" class="hidden" @change="addFile($event)">
                        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-blue-100 text-blue-600">
                            <i class="fas fa-cloud-upload-alt text-xl"></i>
                        </span>
                        <p class="mt-3 text-sm font-semibold text-gray-700">Click để chọn tệp hoặc kéo thả vào đây</p>
                        <p class="mt-1 text-xs text-gray-400">Dung lượng tối đa {{ $proofMaxMb }}MB</p>
                    </div>

                    <div x-cloak x-show="error" class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-600">
                        <i class="fas fa-exclamation-triangle mr-1"></i><span x-text="error"></span>
                    </div>

                    <div x-cloak x-show="file" class="mt-3 flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600"><i class="fas fa-file-alt"></i></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-800" x-text="file?.name"></p>
                            <p class="text-xs text-gray-400" x-text="file ? formatSize(file.size) : ''"></p>
                        </div>
                        <button type="button" @click.stop="removeFile()" class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-red-50 hover:text-red-500" aria-label="Xóa tệp">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>

                    @error('proof_file')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                    @if ($assignment->proof_file)
                        <a target="_blank" href="{{ \Illuminate\Support\Facades\Storage::url($assignment->proof_file) }}" class="mt-3 inline-flex items-center gap-2 text-sm font-medium text-blue-700 hover:underline">
                            <i class="fas fa-paperclip"></i> File hiện tại: {{ $assignment->proof_display_name }}
                        </a>
                    @endif
                </div>

                <div class="flex justify-end border-t border-gray-100 pt-6">
                    <button class="flex items-center rounded-xl bg-green-600 px-6 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                        <i class="fas fa-save mr-2"></i> Lưu tiến độ
                    </button>
                </div>
            </form>
        </section>
    @endif
</div>

@if ($canManageSubtasks)
<div id="subtask-modal" class="fixed inset-0 z-50 {{ $openSubtaskModal ? 'flex' : 'hidden' }} items-center justify-center bg-black/40 p-4">
    <form method="post" action="{{ route('staff_task_create_subtask', $myAssignment->id) }}" class="w-full max-w-lg space-y-4 rounded-2xl bg-white p-6 shadow-xl">
        @csrf
        <div class="flex justify-between"><h3 class="font-semibold">Tạo nhiệm vụ con</h3><button type="button" data-close="subtask-modal"><i class="fas fa-times"></i></button></div>
        <input name="title" required maxlength="255" placeholder="Tiêu đề" class="w-full rounded-lg border-gray-300">
        <input type="date" name="deadline" required min="{{ now()->format('Y-m-d') }}" max="{{ $task->deadline->format('Y-m-d') }}" class="w-full rounded-lg border-gray-300">
        <div class="max-h-52 space-y-2 overflow-y-auto rounded-lg border p-3">
            @foreach ($subtaskAssigneeOptions as $member)
                <label class="flex items-center gap-3"><input type="checkbox" name="assignees[]" value="{{ $member->id }}"><img src="{{ $member->avatar_url }}" class="h-8 w-8 rounded-full" alt=""><span class="text-sm">{{ $member }}</span></label>
            @endforeach
        </div>
        <div class="flex justify-end gap-2"><button type="button" data-close="subtask-modal" class="rounded-lg bg-gray-100 px-4 py-2">Hủy</button><button class="rounded-lg bg-blue-600 px-4 py-2 text-white">Tạo &amp; giao việc</button></div>
    </form>
</div>

<div id="edit-subtask-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <form method="post" id="edit-subtask-form" class="w-full max-w-md space-y-4 rounded-2xl bg-white p-6 shadow-xl">
        @csrf
        <div class="flex justify-between"><h3 class="font-semibold">Sửa nhiệm vụ con</h3><button type="button" data-close="edit-subtask-modal"><i class="fas fa-times"></i></button></div>
        <input id="edit-subtask-title" name="title" required maxlength="255" class="w-full rounded-lg border-gray-300">
        <input id="edit-subtask-deadline" type="date" name="deadline" required max="{{ $task->deadline->format('Y-m-d') }}" class="w-full rounded-lg border-gray-300">
        <div class="flex justify-end gap-2"><button type="button" data-close="edit-subtask-modal" class="rounded-lg bg-gray-100 px-4 py-2">Hủy</button><button class="rounded-lg bg-blue-600 px-4 py-2 text-white">Lưu</button></div>
    </form>
</div>
@endif

@if ($canManageMembers)
<div id="members-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <form method="post" action="{{ route('staff_task_add_members', $myAssignment->id) }}" class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
        @csrf
        <div class="mb-4 flex justify-between"><h3 class="font-semibold">Thêm thành viên{{ $managedDepartment ? ' — '.$managedDepartment->name : '' }}</h3><button type="button" data-close="members-modal"><i class="fas fa-times"></i></button></div>
        <div class="max-h-72 space-y-2 overflow-y-auto">
            @forelse ($availableMembers as $member)
                <label class="flex items-center gap-3 rounded-xl border p-3"><input type="checkbox" name="member_ids[]" value="{{ $member->id }}"><img src="{{ $member->avatar_url }}" class="h-8 w-8 rounded-full" alt=""><span class="text-sm">{{ $member }}</span></label>
            @empty
                <p class="py-8 text-center text-sm text-gray-500">Không còn thành viên để thêm.</p>
            @endforelse
        </div>
        <div class="mt-4 flex justify-end gap-2"><button type="button" data-close="members-modal" class="rounded-lg bg-gray-100 px-4 py-2">Hủy</button><button class="rounded-lg bg-blue-700 px-4 py-2 text-white">Thêm vào task</button></div>
    </form>
</div>
@endif

@if ($evaluationParticipations->isNotEmpty())
<div id="evaluation-modal" class="fixed inset-0 z-50 {{ $openEvaluateModal ? 'flex' : 'hidden' }} items-center justify-center bg-black/40 p-4">
    <form method="post" class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
        @csrf
        <input type="hidden" name="action" value="internal_evaluate">
        <div class="mb-4 flex justify-between"><div><h3 class="font-semibold">Đánh giá từng cá nhân</h3><p class="text-xs text-gray-500">Chọn đủ một kết quả cho mỗi thành viên.</p></div><button type="button" data-close="evaluation-modal"><i class="fas fa-times"></i></button></div>
        <div class="space-y-3">
            @foreach ($evaluationParticipations as $participation)
                <div class="rounded-xl border p-3">
                    <div class="mb-3 flex items-center gap-3"><img src="{{ $participation->user->avatar_url }}" class="h-9 w-9 rounded-full" alt=""><strong class="text-sm">{{ $participation->user }}</strong></div>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach ($evaluationChoices as $value => $label)
                            <label class="cursor-pointer"><input type="radio" name="eval_{{ $participation->id }}" value="{{ $value }}" class="peer sr-only" @checked($participation->evaluation === $value) required><span class="flex min-h-12 items-center justify-center rounded-lg border px-1 text-center text-xs peer-checked:border-violet-500 peer-checked:bg-violet-50">{{ $value === 'DAT' ? 'Đạt' : ($value === 'CHO_LAM_LAI' ? 'Làm lại' : 'Trừ 1') }}</span></label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-4 flex justify-end gap-2"><button type="button" data-close="evaluation-modal" class="rounded-lg bg-gray-100 px-4 py-2">Đóng</button><button class="rounded-lg bg-violet-600 px-4 py-2 text-white">Lưu đánh giá</button></div>
    </form>
</div>
@endif
@endsection

@push('scripts')
<script>
window.progressFileUploader = function (maxMb) {
    return {
        file: null,
        error: '',
        blockedExtensions: ['exe', 'bat', 'sh', 'msi', 'cmd'],

        addFile(event) {
            const selected = event.dataTransfer?.files || event.target.files;
            const file = selected?.[0];
            this.error = '';

            if (!file) return;

            if (selected.length > 1) {
                this.error = 'Mỗi lần cập nhật chỉ chấp nhận một file minh chứng.';
                this.clearNativeInput();
                return;
            }

            if (file.size > maxMb * 1024 * 1024) {
                this.error = `File ${file.name} vượt quá giới hạn ${maxMb}MB.`;
                this.clearNativeInput();
                return;
            }

            const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
            if (this.blockedExtensions.includes(extension)) {
                this.error = `Định dạng file ${file.name} không được phép tải lên vì lý do bảo mật.`;
                this.clearNativeInput();
                return;
            }

            this.file = file;
            const transfer = new DataTransfer();
            transfer.items.add(file);
            this.$refs.fileInput.files = transfer.files;
        },

        removeFile() {
            this.file = null;
            this.error = '';
            this.clearNativeInput();
        },

        clearNativeInput() {
            this.$refs.fileInput.value = '';
        },

        formatSize(bytes) {
            if (bytes < 1024) return `${bytes} B`;
            if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
            return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        },
    };
};

document.querySelectorAll('[data-open]').forEach(function (button) {
    button.addEventListener('click', function () {
        var modal = document.getElementById(button.dataset.open);
        if (modal) { modal.classList.remove('hidden'); modal.classList.add('flex'); }
    });
});
document.querySelectorAll('[data-close]').forEach(function (button) {
    button.addEventListener('click', function () {
        var modal = document.getElementById(button.dataset.close);
        if (modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); }
    });
});
document.querySelectorAll('.edit-subtask').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById('edit-subtask-form').action = button.dataset.url;
        document.getElementById('edit-subtask-title').value = button.dataset.title || '';
        document.getElementById('edit-subtask-deadline').value = button.dataset.deadline || '';
        var modal = document.getElementById('edit-subtask-modal');
        modal.classList.remove('hidden'); modal.classList.add('flex');
    });
});
</script>
@endpush
