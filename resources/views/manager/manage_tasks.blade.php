@extends('layouts.app')
@section('title', 'Quản lý công việc')

@push('styles')
<style>[x-cloak] { display: none !important; }</style>
@endpush

@section('content')
<div x-data="taskManager()" x-cloak @keydown.escape.window="closeEditModal()">
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-2xl font-bold">Quản lý công việc</h1><p class="text-slate-500">Tìm kiếm, theo dõi và điều chỉnh nhiệm vụ</p></div><a href="{{ route('manager_create_task') }}" class="rounded-xl bg-blue-600 px-5 py-2.5 font-semibold text-white">+ Giao nhiệm vụ</a></div>
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">@foreach(['total'=>'Tất cả','in_progress'=>'Đang làm','pending'=>'Chờ duyệt','overdue'=>'Quá hạn'] as $key=>$label)<div class="rounded-xl bg-white p-4 ring-1 ring-slate-200"><p class="text-sm text-slate-500">{{ $label }}</p><p class="text-2xl font-bold">{{ $stats[$key] }}</p></div>@endforeach</div>
    <form class="grid gap-3 rounded-2xl bg-white p-4 ring-1 ring-slate-200 md:grid-cols-4">
        <input name="q" value="{{ request('q') }}" placeholder="Tìm nhiệm vụ..." class="rounded-xl border-slate-300">
        <select name="status" class="rounded-xl border-slate-300"><option value="">Mọi trạng thái</option>@foreach($statusChoices as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach<option value="overdue" @selected(request('status')==='overdue')>Quá hạn</option></select>
        <select name="dept" class="rounded-xl border-slate-300"><option value="">Mọi Tổ/Nhóm</option>@foreach($departments as $dept)<option value="{{ $dept->id }}" @selected(request('dept')==$dept->id)>{{ $dept->name }}</option>@endforeach</select>
        <button class="rounded-xl bg-slate-800 px-4 py-2 text-white">Lọc</button>
    </form>
    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-slate-200"><table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="p-4">Nhiệm vụ</th><th class="p-4">Hạn</th><th class="p-4">Trạng thái</th><th class="p-4">Tiến độ</th><th class="p-4 text-right">Thao tác</th></tr></thead>
        <tbody class="divide-y">
            @forelse($tasks as $task)
                @php
                    $taskEditData = [
                        'id' => $task->id,
                        'title' => $task->title,
                        'description' => (string) $task->description,
                        'deadline' => $task->deadline->format('Y-m-d'),
                        'cycle' => $task->cycle,
                    ];
                @endphp
                <tr class="{{ $task->row_is_overdue ? 'bg-rose-50/40' : '' }}">
                    <td class="p-4">
                        <a href="{{ route('manager_task_detail', $task) }}" class="font-semibold text-slate-900 hover:text-blue-600">{{ $task->title }}</a>
                        <p class="max-w-md truncate text-slate-500">{{ $task->description }}</p>
                    </td>
                    <td class="p-4 {{ $task->row_is_overdue ? 'font-semibold text-rose-600' : '' }}">{{ $task->deadline->format('d/m/Y') }}</td>
                    <td class="p-4">
                        <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $task->display_status_pill }}">{{ $task->display_status_label }}</span>
                    </td>
                    <td class="p-4">
                        <div class="mb-1 flex justify-between text-xs">
                            <span>{{ $task->progress_label }}</span>
                            <span>{{ $task->progress_pct }}%</span>
                        </div>
                        <div class="h-2 w-40 rounded-full bg-slate-100">
                            <div class="h-2 rounded-full bg-blue-600" style="width:{{ $task->progress_pct }}%"></div>
                        </div>
                    </td>
                    <td class="p-4">
                        <div class="flex items-center justify-end gap-2">
                            <a href="{{ route('manager_task_detail', $task) }}"
                               title="Xem chi tiết" aria-label="Xem chi tiết {{ $task->title }}"
                               class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition-colors duration-200 hover:bg-blue-50 hover:text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                                <i class="fas fa-eye text-sm"></i>
                            </a>
                            <button type="button"
                                    data-task="{{ json_encode($taskEditData, JSON_UNESCAPED_UNICODE) }}"
                                    @click="openEditModal(JSON.parse($el.dataset.task))"
                                    title="Sửa công việc" aria-label="Sửa {{ $task->title }}"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition-colors duration-200 hover:bg-orange-50 hover:text-orange-600 focus:outline-none focus:ring-2 focus:ring-orange-500/30">
                                <i class="fas fa-pen text-sm"></i>
                            </button>
                            <form method="POST" action="{{ route('manager_task_extend', $task) }}">
                                @csrf
                                <button name="days" value="3" title="Gia hạn thêm 3 ngày"
                                        aria-label="Gia hạn {{ $task->title }} thêm 3 ngày"
                                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition-colors duration-200 hover:bg-green-50 hover:text-green-600 focus:outline-none focus:ring-2 focus:ring-green-500/30">
                                    <i class="fas fa-calendar-plus text-sm"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('manager_task_delete', $task) }}"
                                  onsubmit="return confirm('Xóa nhiệm vụ này?')">
                                @csrf
                                <button title="Xóa công việc" aria-label="Xóa {{ $task->title }}"
                                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition-colors duration-200 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-500/30">
                                    <i class="fas fa-trash text-sm"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="p-10 text-center text-slate-500">Không tìm thấy nhiệm vụ.</td>
                </tr>
            @endforelse
        </tbody>
    </table></div>
    {{ $tasks->links() }}
</div>

<div x-show="isEditModalOpen" x-transition.opacity.duration.200ms
     class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4 backdrop-blur-sm"
     role="dialog" aria-modal="true" aria-labelledby="edit-modal-title"
     @click.self="closeEditModal()">
    <form method="POST" :action="editForm.action"
          x-show="isEditModalOpen"
          x-transition:enter="transform transition ease-out duration-200"
          x-transition:enter-start="opacity-0 translate-y-4 scale-95"
          x-transition:enter-end="opacity-100 translate-y-0 scale-100"
          x-transition:leave="transform transition ease-in duration-150"
          x-transition:leave-start="opacity-100 translate-y-0 scale-100"
          x-transition:leave-end="opacity-0 translate-y-4 scale-95"
          class="w-full max-w-lg transform rounded-2xl bg-white p-6 shadow-2xl transition-all">
        @csrf
        <input type="hidden" name="edit_task_id" x-model="editForm.id">

        <header class="flex items-start justify-between gap-4">
            <div>
                <h2 id="edit-modal-title" class="text-xl font-bold text-gray-900">Cập nhật công việc</h2>
                <p class="mt-1 text-sm text-gray-500">Chỉnh sửa nội dung và thời hạn của nhiệm vụ.</p>
            </div>
            <button type="button" @click="closeEditModal()" aria-label="Đóng"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-700">
                <i class="fas fa-xmark"></i>
            </button>
        </header>

        @if($errors->any())
            <div class="mt-5 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                <i class="fas fa-circle-exclamation mr-1"></i>{{ $errors->first() }}
            </div>
        @endif

        <div class="mt-6 space-y-5">
            <div>
                <label for="edit-title" class="mb-1 block text-sm font-semibold text-gray-700">
                    Tên công việc <span class="text-red-500">*</span>
                </label>
                <input id="edit-title" type="text" name="title" x-model="editForm.title" required
                       class="w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 outline-none transition-colors hover:bg-white focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-500/20">
            </div>

            <div>
                <label for="edit-description" class="mb-1 block text-sm font-semibold text-gray-700">Mô tả</label>
                <textarea id="edit-description" name="description" rows="3" x-model="editForm.description"
                          class="w-full resize-y rounded-lg border border-gray-300 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 outline-none transition-colors hover:bg-white focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-500/20"></textarea>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="edit-deadline" class="mb-1 block text-sm font-semibold text-gray-700">
                        Hạn chót <span class="text-red-500">*</span>
                    </label>
                    <input id="edit-deadline" type="date" name="deadline" x-model="editForm.deadline" required
                           class="w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 outline-none transition-colors hover:bg-white focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-500/20">
                </div>
                <div>
                    <label for="edit-cycle" class="mb-1 block text-sm font-semibold text-gray-700">Chu kỳ</label>
                    <select id="edit-cycle" name="cycle" x-model="editForm.cycle"
                            class="w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 outline-none transition-colors hover:bg-white focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-500/20">
                        @foreach(\App\Models\Task::CYCLE_CHOICES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <footer class="mt-8 flex justify-end gap-3 border-t border-gray-100 pt-4">
            <button type="button" @click="closeEditModal()"
                    class="rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50">
                Hủy
            </button>
            <button type="submit"
                    class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-medium text-white shadow-md transition-colors hover:bg-blue-700">
                Lưu thay đổi
            </button>
        </footer>
    </form>
</div>
</div>
@endsection

@push('scripts')
<script>
function taskManager() {
    const oldTaskId = @json(old('edit_task_id'));

    return {
        isEditModalOpen: Boolean(oldTaskId),
        editForm: {
            id: oldTaskId || null,
            title: @json(old('title', '')),
            description: @json(old('description', '')),
            deadline: @json(old('deadline', '')),
            cycle: @json(old('cycle', '')),
            action: oldTaskId ? `${@json(url('/manager/tasks'))}/${oldTaskId}/edit` : '',
        },

        openEditModal(task) {
            this.editForm = {
                id: task.id,
                title: task.title || '',
                description: task.description || '',
                deadline: task.deadline || '',
                cycle: task.cycle || '',
                action: `${@json(url('/manager/tasks'))}/${task.id}/edit`,
            };
            this.isEditModalOpen = true;
            this.$nextTick(() => document.getElementById('edit-title')?.focus());
        },

        closeEditModal() {
            this.isEditModalOpen = false;
        },
    };
}
</script>
@endpush
