@extends('layouts.app')

@section('title', 'Giao nhiệm vụ')

@section('content')
<div class="mx-auto max-w-6xl">
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Giao nhiệm vụ mới</h1>
        <p class="mt-1 text-sm text-slate-500">Thiết lập nội dung, hình thức giao việc và người chịu trách nhiệm.</p>
    </div>

    <form action="{{ route('manager_create_task') }}" method="POST" enctype="multipart/form-data"
          class="space-y-8 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8">
        @csrf

        <section>
            <div class="mb-4">
                <h2 class="text-base font-semibold text-slate-900">Hình thức giao việc</h2>
                <p class="mt-1 text-sm text-slate-500">Chọn một hình thức phù hợp với nhiệm vụ.</p>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                @foreach([
                    'individual' => ['Cá nhân', 'Giao trực tiếp cho một hoặc nhiều nhân viên', 'fa-user'],
                    'department' => ['Chủ trì – Phối hợp', 'Một tổ chủ trì và các tổ cùng phối hợp', 'fa-people-group'],
                    'batch_department' => ['Giao đồng loạt cho tổ', 'Các tổ thực hiện và được nghiệm thu độc lập', 'fa-layer-group'],
                ] as $value => [$label, $description, $icon])
                    <label class="block cursor-pointer">
                        <input type="radio" name="assign_mode" value="{{ $value }}" class="peer sr-only"
                               @checked(old('assign_mode', 'individual') === $value)>
                        <span class="flex h-full min-h-32 flex-col rounded-xl border-2 border-slate-200 bg-white p-4 text-slate-700 transition hover:border-blue-300 hover:shadow-sm peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-checked:text-blue-900 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 peer-focus-visible:ring-offset-2">
                            <span class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-500 transition peer-checked:bg-blue-100">
                                <i class="fa-solid {{ $icon }}"></i>
                            </span>
                            <span class="font-semibold">{{ $label }}</span>
                            <span class="mt-1 text-sm leading-5 text-slate-500">{{ $description }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('assign_mode')
                <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
            @enderror
        </section>

        <div class="border-t border-slate-200"></div>

        <section class="space-y-5">
            <div>
                <label for="title" class="mb-2 block text-sm font-semibold text-slate-700">
                    Tên nhiệm vụ <span class="text-red-500">*</span>
                </label>
                <input id="title" name="title" type="text" value="{{ old('title') }}" required
                       placeholder="Ví dụ: Hoàn thiện báo cáo tổng kết học kỳ I"
                       class="block w-full rounded-lg border px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:ring-2 focus:ring-blue-500/20 {{ $errors->has('title') ? 'border-red-500 focus:border-red-500' : 'border-slate-300 focus:border-blue-500' }}">
                @error('title')
                    <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="description" class="mb-2 block text-sm font-semibold text-slate-700">Mô tả nhiệm vụ</label>
                <textarea id="description" name="description" rows="5"
                          placeholder="Mô tả yêu cầu, kết quả cần đạt và các lưu ý khi thực hiện..."
                          class="block w-full resize-y rounded-lg border px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:ring-2 focus:ring-blue-500/20 {{ $errors->has('description') ? 'border-red-500 focus:border-red-500' : 'border-slate-300 focus:border-blue-500' }}">{{ old('description') }}</textarea>
                @error('description')
                    <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <div>
                    <label for="deadline" class="mb-2 block text-sm font-semibold text-slate-700">
                        Hạn hoàn thành <span class="text-red-500">*</span>
                    </label>
                    <input id="deadline" name="deadline" type="date" value="{{ old('deadline') }}"
                           min="{{ now()->toDateString() }}" required
                           class="block w-full rounded-lg border px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:ring-2 focus:ring-blue-500/20 {{ $errors->has('deadline') ? 'border-red-500 focus:border-red-500' : 'border-slate-300 focus:border-blue-500' }}">
                    @error('deadline')
                        <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="cycle" class="mb-2 block text-sm font-semibold text-slate-700">
                        Chu kỳ <span class="text-red-500">*</span>
                    </label>
                    <select id="cycle" name="cycle" required
                            class="block w-full rounded-lg border px-4 py-3 text-sm text-slate-900 shadow-sm outline-none transition focus:ring-2 focus:ring-blue-500/20 {{ $errors->has('cycle') ? 'border-red-500 focus:border-red-500' : 'border-slate-300 focus:border-blue-500' }}">
                        @foreach($cycles as $value => $label)
                            <option value="{{ $value }}" @selected(old('cycle') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('cycle')
                        <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </section>

        <div class="border-t border-slate-200"></div>

        <section data-panel="individual">
            <div class="mb-4">
                <h2 class="text-base font-semibold text-slate-900">Người thực hiện</h2>
                <p class="mt-1 text-sm text-slate-500">Có thể chọn nhiều nhân viên cho cùng một nhiệm vụ.</p>
            </div>

            <div class="relative mb-4">
                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input id="staff-search" type="search" placeholder="Tìm theo tên, tài khoản hoặc tổ/nhóm..."
                       class="w-full rounded-lg border border-slate-300 py-3 pl-11 pr-4 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
            </div>

            <div id="staff-picker" class="grid max-h-96 grid-cols-1 gap-3 overflow-y-auto pr-1 md:grid-cols-2 xl:grid-cols-3">
                @foreach($staff as $user)
                    <label data-search="{{ mb_strtolower($user->full_name_vn.' '.$user->username.' '.$user->department_name) }}"
                           class="group relative cursor-pointer">
                        <input type="checkbox" name="assignees[]" value="{{ $user->id }}" class="peer sr-only"
                               @checked(in_array($user->id, old('assignees', [])))>
                        <span class="flex min-h-20 items-center gap-3 rounded-xl border-2 border-slate-200 bg-white p-3 pr-12 transition hover:border-blue-300 hover:bg-slate-50 peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 peer-focus-visible:ring-offset-2">
                            <span class="shrink-0">
                                <img src="{{ $user->avatar_url }}" alt="{{ $user->full_name_vn }}"
                                     class="h-11 w-11 rounded-full bg-slate-100 object-cover ring-2 ring-white">
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold text-slate-800">{{ $user->full_name_vn }}</span>
                                <span class="mt-0.5 block truncate text-xs text-slate-500">
                                    <i class="fa-regular fa-building mr-1"></i>{{ $user->department_name ?: 'Chưa thuộc tổ/nhóm' }}
                                </span>
                            </span>
                        </span>
                        <span class="pointer-events-none absolute right-4 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded border-2 border-slate-300 bg-white text-xs text-transparent transition peer-checked:border-blue-600 peer-checked:bg-blue-600 peer-checked:text-white">
                            <i class="fa-solid fa-check"></i>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('assignees')
                <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
            @enderror
            @error('assignees.*')
                <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
            @enderror
        </section>

        <section data-panel="department" class="hidden space-y-5">
            <div>
                <label for="primary_department_id" class="mb-2 block text-sm font-semibold text-slate-700">
                    Tổ/Nhóm chủ trì <span class="text-red-500">*</span>
                </label>
                <select id="primary_department_id" name="primary_department_id"
                        class="block w-full rounded-lg border px-4 py-3 text-sm shadow-sm outline-none transition focus:ring-2 focus:ring-blue-500/20 {{ $errors->has('primary_department_id') ? 'border-red-500 focus:border-red-500' : 'border-slate-300 focus:border-blue-500' }}">
                    <option value="">Chọn tổ/nhóm chủ trì</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->id }}" @selected((string) old('primary_department_id') === (string) $department->id) @disabled(!$department->leader_id)>
                            {{ $department->name }} — {{ $department->leader ?: 'Chưa có trưởng tổ' }}
                        </option>
                    @endforeach
                </select>
                @error('primary_department_id')
                    <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
                @enderror
            </div>

            <div>
                <p class="mb-2 block text-sm font-semibold text-slate-700">Tổ/Nhóm phối hợp</p>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach($departments as $department)
                        <label class="cursor-pointer">
                            <input type="checkbox" name="coordinating_departments[]" value="{{ $department->id }}" class="peer sr-only"
                                   @checked(in_array($department->id, old('coordinating_departments', []))) @disabled(!$department->leader_id)>
                            <span class="flex items-center gap-3 rounded-xl border-2 border-slate-200 p-4 text-sm transition hover:border-blue-300 peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-disabled:cursor-not-allowed peer-disabled:bg-slate-100 peer-disabled:opacity-60">
                                <i class="fa-regular fa-building text-slate-400"></i>
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-slate-800">{{ $department->name }}</span>
                                    <span class="block truncate text-xs text-slate-500">{{ $department->leader ?: 'Chưa có trưởng tổ' }}</span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('coordinating_departments')
                    <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
                @enderror
                @error('coordinating_departments.*')
                    <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
                @enderror
            </div>
        </section>

        <section data-panel="batch_department" class="hidden">
            <div class="mb-4">
                <h2 class="text-base font-semibold text-slate-900">Các Tổ/Nhóm nhận việc</h2>
                <p class="mt-1 text-sm text-slate-500">Mỗi tổ/nhóm sẽ có một kết quả nghiệm thu độc lập.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach($departments as $department)
                    <label class="cursor-pointer">
                        <input type="checkbox" name="batch_departments[]" value="{{ $department->id }}" class="peer sr-only"
                               @checked(in_array($department->id, old('batch_departments', []))) @disabled(!$department->leader_id)>
                        <span class="flex items-center gap-3 rounded-xl border-2 border-slate-200 p-4 text-sm transition hover:border-blue-300 peer-checked:border-blue-500 peer-checked:bg-blue-50 peer-disabled:cursor-not-allowed peer-disabled:bg-slate-100 peer-disabled:opacity-60">
                            <i class="fa-solid fa-people-group text-slate-400"></i>
                            <span class="min-w-0">
                                <span class="block truncate font-semibold text-slate-800">{{ $department->name }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ $department->leader ?: 'Chưa có trưởng tổ' }}</span>
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('batch_departments')
                <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
            @enderror
            @error('batch_departments.*')
                <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
            @enderror
        </section>

        <div class="border-t border-slate-200"></div>

        <section x-data="fileUploader({{ (int) $maxAttachmentCount }}, {{ (int) $maxAttachmentMb }}, {{ $errors->has('attachments') || $errors->has('attachments.*') ? 'true' : 'false' }})" x-cloak>
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Tệp đính kèm</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Tối đa {{ $maxAttachmentCount }} tệp, dung lượng {{ $maxAttachmentMb }} MB cho mỗi tệp.
                        Có thể chọn nhiều lần — file sẽ được cộng dồn.
                    </p>
                </div>
                <p x-show="files.length > 0" class="text-xs font-medium text-slate-500"
                   x-text="`${files.length}/${maxCount} tệp đã chọn`"></p>
            </div>

            <div @click="$refs.fileInput.click()"
                 @dragover.prevent="isDragging = true"
                 @dragleave.prevent="isDragging = false"
                 @drop.prevent="isDragging = false; addFiles($event)"
                 :class="{
                    'border-blue-500 bg-blue-50': isDragging,
                    'border-red-400 bg-red-50/50': !isDragging && (errorMessages.length > 0 || hasServerError),
                    'border-gray-300 bg-slate-50/60 hover:border-blue-400 hover:bg-blue-50/60': !isDragging && errorMessages.length === 0 && !hasServerError
                 }"
                 class="flex min-h-44 cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-6 text-center transition-colors">
                <span class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-blue-100 text-xl text-blue-600">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                </span>
                <span class="text-sm font-semibold text-slate-700">
                    <span class="text-blue-600">Click để chọn tệp</span> hoặc kéo thả vào đây
                </span>
                <span class="mt-2 text-xs text-slate-500">Chọn lắt nhắt từng lần — danh sách sẽ cộng dồn, không bị ghi đè</span>
                <input x-ref="fileInput" type="file" name="attachments[]" multiple class="hidden"
                       @change="addFiles($event)" @click.stop>
            </div>

            <div x-show="errorMessages.length > 0" x-cloak x-transition
                 class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-600">
                <div class="mb-1.5 flex items-center gap-2 font-semibold">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Một số tệp không được chấp nhận</span>
                </div>
                <ul class="list-inside list-disc space-y-1">
                    <template x-for="(error, errorIndex) in errorMessages" :key="errorIndex">
                        <li x-text="error"></li>
                    </template>
                </ul>
            </div>

            <div x-show="files.length > 0" x-cloak class="mt-3 space-y-2">
                <template x-for="(file, index) in files" :key="`${file.name}-${file.size}-${file.lastModified}-${index}`">
                    <div class="mt-2 flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white p-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                                <i class="fa-solid fa-file-alt"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-800" x-text="file.name" :title="file.name"></p>
                                <p class="mt-0.5 text-xs text-slate-400" x-text="formatSize(file.size)"></p>
                            </div>
                        </div>
                        <button type="button" @click.stop="removeFile(index)"
                                title="Xóa tệp này" aria-label="Xóa tệp"
                                class="cursor-pointer rounded-lg p-2 text-gray-400 transition-colors hover:bg-red-50 hover:text-red-500">
                            <i class="fa-solid fa-trash-alt"></i>
                        </button>
                    </div>
                </template>
            </div>

            @error('attachments')
                <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
            @enderror
            @error('attachments.*')
                <p class="mt-2 text-sm text-red-600"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
            @enderror
        </section>

        <div class="flex flex-col-reverse items-stretch justify-end gap-3 border-t border-slate-200 pt-6 sm:flex-row sm:items-center">
            <a href="{{ route('manager_manage_tasks') }}"
               class="inline-flex justify-center rounded-lg border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2">
                Hủy
            </a>
            <button type="submit"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                <i class="fa-solid fa-paper-plane"></i>
                Giao nhiệm vụ
            </button>
        </div>
    </form>
</div>
@endsection

@push('styles')
<style>[x-cloak] { display: none !important; }</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modeInputs = document.querySelectorAll('input[name="assign_mode"]');
    const panels = document.querySelectorAll('[data-panel]');

    const switchMode = () => {
        const selectedMode = document.querySelector('input[name="assign_mode"]:checked')?.value ?? 'individual';
        panels.forEach((panel) => panel.classList.toggle('hidden', panel.dataset.panel !== selectedMode));
    };

    modeInputs.forEach((input) => input.addEventListener('change', switchMode));
    switchMode();

    document.getElementById('staff-search')?.addEventListener('input', (event) => {
        const query = event.target.value.toLocaleLowerCase('vi').trim();
        document.querySelectorAll('#staff-picker > label').forEach((item) => {
            item.classList.toggle('hidden', !item.dataset.search.includes(query));
        });
    });
});

function fileUploader(maxCount = 10, maxMb = 5, hasServerError = false) {
    const blockedExtensions = ['exe', 'bat', 'sh', 'msi', 'cmd'];

    return {
        files: [],
        errorMessages: [],
        isDragging: false,
        hasServerError,
        maxCount,
        maxBytes: 5 * 1024 * 1024,
        maxMb,
        errorTimer: null,

        addFiles(event) {
            const incoming = Array.from(event.dataTransfer?.files || event.target.files || []);
            this.errorMessages = [];
            if (this.errorTimer) {
                window.clearTimeout(this.errorTimer);
                this.errorTimer = null;
            }
            if (!incoming.length) return;

            const next = [...this.files];

            for (const file of incoming) {
                if (next.length >= this.maxCount) {
                    this.errorMessages.push(`Chỉ được đính kèm tối đa ${this.maxCount} tệp.`);
                    break;
                }

                if (file.size > this.maxBytes) {
                    this.errorMessages.push(`File ${file.name} vượt quá giới hạn 5MB.`);
                    continue;
                }

                const extension = (file.name.split('.').pop() || '').toLowerCase();
                if (blockedExtensions.includes(extension)) {
                    this.errorMessages.push(
                        `Định dạng file ${file.name} không được phép tải lên vì lý do bảo mật.`
                    );
                    continue;
                }

                const duplicate = next.some((existing) =>
                    existing.name === file.name
                    && existing.size === file.size
                    && existing.lastModified === file.lastModified
                );
                if (duplicate) continue;

                next.push(file);
            }

            this.files = next;
            this.syncInput();

            if (this.errorMessages.length > 0) {
                this.errorTimer = window.setTimeout(() => {
                    this.errorMessages = [];
                    this.errorTimer = null;
                }, 5000);
            }

            if (event.target?.value !== undefined) {
                event.target.value = '';
            }
        },

        removeFile(index) {
            this.files.splice(index, 1);
            this.syncInput();
        },

        syncInput() {
            const transfer = new DataTransfer();
            this.files.forEach((file) => transfer.items.add(file));
            this.$refs.fileInput.files = transfer.files;
        },

        formatSize(bytes) {
            if (bytes < 1024) return `${bytes} B`;
            if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
            return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
        },
    };
}
</script>
@endpush
