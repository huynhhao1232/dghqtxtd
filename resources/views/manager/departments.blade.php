@extends('layouts.app')

@section('title', 'Quản lý Tổ/Nhóm')

@push('styles')
<style>[x-cloak] { display: none !important; }</style>
@endpush

@section('content')
<div x-data="departmentMembers()" x-cloak @keydown.escape.window="handleEscape()">
<div class="mx-auto max-w-7xl">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-gray-900">Quản lý Tổ/Nhóm</h1>
            <p class="mt-1 text-sm text-gray-500">Quản lý cơ cấu, trưởng tổ và thành viên của từng đơn vị.</p>
        </div>
        <button type="button" @click="openDeptForm()"
                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            <i class="fa-solid fa-plus text-xs"></i>
            Thêm tổ
        </button>
    </div>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
        @forelse($departments as $dept)
            @php
                $leaderInitials = $dept->leader
                    ? mb_strtoupper(mb_substr($dept->leader->last_name ?: $dept->leader->first_name, 0, 1)
                        .mb_substr($dept->leader->first_name, 0, 1))
                    : '?';
                $activeMemberCount = $dept->members->where('is_active', true)->count();
            @endphp

            <article class="flex h-full flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-shadow duration-200 hover:shadow-md">
                <header class="flex items-start justify-between gap-3 p-5 pb-3">
                    <div class="min-w-0">
                        <h2 class="truncate text-lg font-bold text-gray-900">{{ $dept->name }}</h2>
                        <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset {{ $dept->badge_classes }}">
                            Tổ/Nhóm
                        </span>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <a href="{{ route('manager_departments', ['action' => 'edit', 'dept_id' => $dept->id]) }}"
                           title="Sửa {{ $dept->name }}" aria-label="Sửa {{ $dept->name }}"
                           class="inline-flex h-9 w-9 items-center justify-center rounded-md p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                            <i class="fa-solid fa-pen text-sm"></i>
                        </a>
                        <form method="POST" action="{{ route('manager_departments') }}"
                              onsubmit="return confirm('Bạn chắc chắn muốn xóa Tổ/Nhóm này?')">
                            @csrf
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="dept_id" value="{{ $dept->id }}">
                            <button type="submit" title="Xóa {{ $dept->name }}" aria-label="Xóa {{ $dept->name }}"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded-md p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-500/30">
                                <i class="fa-solid fa-trash text-sm"></i>
                            </button>
                        </form>
                    </div>
                </header>

                <div class="flex-1 px-5 pb-5">
                    <p class="mb-5 min-h-10 text-sm leading-5 text-gray-500 line-clamp-2">
                        {{ $dept->description ?: 'Chưa có mô tả cho Tổ/Nhóm này.' }}
                    </p>

                    <div class="flex items-center gap-3 rounded-lg bg-gray-50 p-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-700">
                            {{ $leaderInitials }}
                        </span>
                        <div class="min-w-0">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Trưởng tổ</p>
                            <p class="mt-0.5 truncate text-sm font-semibold text-gray-800">
                                {{ $dept->leader ?: 'Chưa chỉ định' }}
                            </p>
                        </div>
                    </div>
                </div>

                <footer class="flex items-center justify-between gap-3 rounded-b-xl border-t border-gray-100 bg-gray-50 p-4">
                    <span class="inline-flex items-center gap-2 text-sm font-medium text-gray-600">
                        <i class="fa-solid fa-users text-gray-400"></i>
                        <span data-member-count="{{ $dept->id }}">{{ $activeMemberCount }} thành viên</span>
                    </span>
                    <button type="button" @click="openModal({{ $dept->id }}, @js($dept->name))"
                            class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                        Thành viên
                    </button>
                </footer>
            </article>
        @empty
            <div class="col-span-full rounded-xl border-2 border-dashed border-gray-300 bg-white px-6 py-16 text-center">
                <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-blue-50 text-2xl text-blue-500">
                    <i class="fa-solid fa-people-group"></i>
                </span>
                <h2 class="mt-4 text-base font-semibold text-gray-900">Chưa có Tổ/Nhóm</h2>
                <p class="mx-auto mt-2 max-w-md text-sm text-gray-500">
                    Tạo Tổ/Nhóm đầu tiên để bắt đầu phân công trưởng tổ và quản lý thành viên.
                </p>
                <button type="button" @click="openDeptForm()"
                        class="mt-5 inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                    <i class="fa-solid fa-plus text-xs"></i>
                    Thêm tổ đầu tiên
                </button>
            </div>
        @endforelse
    </div>
</div>

@php
    $swatchClasses = [
        'blue' => ['bg-blue-500', 'peer-checked:ring-blue-500'],
        'emerald' => ['bg-emerald-500', 'peer-checked:ring-emerald-500'],
        'amber' => ['bg-amber-500', 'peer-checked:ring-amber-500'],
        'rose' => ['bg-rose-500', 'peer-checked:ring-rose-500'],
        'violet' => ['bg-violet-500', 'peer-checked:ring-violet-500'],
        'cyan' => ['bg-cyan-500', 'peer-checked:ring-cyan-500'],
        'orange' => ['bg-orange-500', 'peer-checked:ring-orange-500'],
        'slate' => ['bg-slate-500', 'peer-checked:ring-slate-500'],
    ];
@endphp

<div x-show="isDeptFormOpen" x-transition.opacity.duration.200ms
     class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 p-4 backdrop-blur-sm"
     role="dialog" aria-modal="true" aria-labelledby="dept-modal-title"
     @click.self="closeDeptForm()">
    <form method="POST" action="{{ route('manager_departments') }}"
          x-show="isDeptFormOpen"
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 translate-y-4 scale-95"
          x-transition:enter-end="opacity-100 translate-y-0 scale-100"
          x-transition:leave="transition ease-in duration-150"
          x-transition:leave-start="opacity-100 translate-y-0 scale-100"
          x-transition:leave-end="opacity-0 translate-y-4 scale-95"
          class="my-6 w-full max-w-md transform rounded-xl bg-white p-6 shadow-2xl transition-all">
        @csrf
        <input type="hidden" name="action" :value="deptForm.action">
        <input type="hidden" name="dept_id" :value="deptForm.id">

        <header class="mb-6 flex items-start justify-between gap-4">
            <div>
                <h2 id="dept-modal-title" class="text-xl font-bold text-gray-900"
                    x-text="deptForm.action === 'edit' ? 'Sửa Tổ/Nhóm' : 'Thêm Tổ/Nhóm mới'"></h2>
                <p class="mt-1 text-sm text-gray-500">Thiết lập thông tin và màu đại diện cho đơn vị.</p>
            </div>
            <button type="button" @click="closeDeptForm()" aria-label="Đóng"
                    class="-mr-2 -mt-2 rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <div class="space-y-5">
            @if($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    <i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $errors->first() }}
                </div>
            @endif

            <div>
                <label for="dept-name" class="mb-1 block text-sm font-medium text-gray-700">
                    Tên Tổ/Nhóm <span class="text-red-500">*</span>
                </label>
                <input id="dept-name" type="text" name="name" x-model="deptForm.name" required
                       placeholder="Ví dụ: Tổ Giáo dục thường xuyên"
                       class="w-full rounded-lg border px-4 py-2.5 text-sm text-gray-900 outline-none transition focus:ring-2 focus:ring-blue-500 {{ $errors->has('name') ? 'border-red-500 focus:border-red-500' : 'border-gray-300 focus:border-blue-500' }}">
                @error('name')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="dept-description" class="mb-1 block text-sm font-medium text-gray-700">Mô tả</label>
                <textarea id="dept-description" name="description" rows="3" x-model="deptForm.description"
                          placeholder="Mô tả ngắn về chức năng của Tổ/Nhóm..."
                          class="w-full resize-y rounded-lg border px-4 py-2.5 text-sm text-gray-900 outline-none transition focus:ring-2 focus:ring-blue-500 {{ $errors->has('description') ? 'border-red-500 focus:border-red-500' : 'border-gray-300 focus:border-blue-500' }}"></textarea>
                @error('description')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="leader_id" class="mb-1 block text-sm font-medium text-gray-700">Trưởng tổ</label>
                <select id="leader_id" name="leader_id" x-model="deptForm.leader_id"
                        class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500">
                    <option value="">Chưa chỉ định trưởng tổ</option>
                    @foreach($staff as $user)
                        <option value="{{ $user->id }}">{{ $user }}</option>
                    @endforeach
                </select>
                @error('leader_id')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <fieldset>
                <legend class="mb-3 text-sm font-medium text-gray-700">Chọn màu đại diện</legend>
                <div class="flex flex-wrap items-center gap-3">
                    @foreach($badgeColors as $value => $label)
                        @php([$backgroundClass, $ringClass] = $swatchClasses[$value])
                        <label class="relative cursor-pointer" title="{{ $label }}">
                            <input type="radio" name="badge_color" value="{{ $value }}"
                                   x-model="deptForm.badge_color" class="peer sr-only">
                            <span class="block h-8 w-8 rounded-full {{ $backgroundClass }} cursor-pointer transition-all hover:opacity-80 peer-focus-visible:ring-2 peer-focus-visible:ring-offset-2 peer-checked:scale-90 peer-checked:ring-2 peer-checked:ring-offset-2 {{ $ringClass }}"></span>
                            <span class="sr-only">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                @error('badge_color')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            </fieldset>
        </div>

        <footer class="mt-6 flex justify-end gap-3 border-t border-gray-100 pt-4">
            <button type="button" @click="closeDeptForm()"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">
                Hủy
            </button>
            <button type="submit"
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                Lưu
            </button>
        </footer>
    </form>
</div>

<div x-show="isOpen" x-transition.opacity.duration.200ms
     class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
     role="dialog" aria-modal="true" aria-labelledby="members-modal-title"
     @click.self="closeModal()">
    <div x-show="isOpen"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-4 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-4 scale-95"
         class="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <header class="flex shrink-0 items-start justify-between border-b border-gray-100 px-5 py-4">
            <div class="min-w-0">
                <h2 id="members-modal-title" class="truncate text-lg font-bold text-gray-900">
                    Thành viên — <span x-text="currentDept.name"></span>
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    <span x-text="members.length"></span> thành viên hiện tại
                </p>
            </div>
            <button type="button" @click="closeModal()" aria-label="Đóng"
                    class="ml-3 rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </header>

        <div class="relative shrink-0 border-b border-gray-100 p-4">
            <label for="member-search" class="sr-only">Tìm người để thêm</label>
            <div class="relative">
                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-gray-400"></i>
                <input id="member-search" type="search" x-model.debounce.500ms="searchQuery"
                       placeholder="Tìm theo tên, tài khoản hoặc chức vụ..."
                       class="block w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-10 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                <i x-show="isSearching" class="fa-solid fa-spinner fa-spin absolute right-3.5 top-1/2 -translate-y-1/2 text-sm text-blue-500"></i>
            </div>

            <div x-show="searchQuery.length >= 2 && (searchResults.length || (!isSearching && hasSearched))"
                 x-transition
                 class="absolute left-4 right-4 top-[4.3rem] z-20 max-h-64 overflow-y-auto rounded-xl border border-gray-200 bg-white p-1.5 shadow-xl">
                <template x-for="user in searchResults" :key="user.id">
                    <div class="flex items-center gap-3 rounded-lg px-3 py-2.5 hover:bg-gray-50">
                        <img :src="user.avatar" :alt="user.name"
                             class="h-9 w-9 shrink-0 rounded-full bg-gray-100 object-cover ring-1 ring-gray-200">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-800" x-text="user.name"></p>
                            <p class="truncate text-xs text-gray-500" x-text="user.position"></p>
                        </div>
                        <button type="button" @click="addMember(user.id)" :disabled="actionUserId === user.id"
                                class="inline-flex shrink-0 items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-blue-600 transition hover:bg-blue-50 disabled:opacity-50">
                            <i :class="actionUserId === user.id ? 'fa-spinner fa-spin' : 'fa-plus'" class="fa-solid"></i>
                            Thêm
                        </button>
                    </div>
                </template>
                <p x-show="!isSearching && hasSearched && searchResults.length === 0"
                   class="px-3 py-6 text-center text-sm text-gray-500">
                    Không tìm thấy nhân sự phù hợp.
                </p>
            </div>
        </div>

        <main class="min-h-56 flex-1 overflow-y-auto p-4">
            <div x-show="isLoading" class="space-y-3">
                <template x-for="index in 4" :key="index">
                    <div class="flex animate-pulse items-center gap-3 rounded-lg p-2">
                        <div class="h-10 w-10 rounded-full bg-gray-200"></div>
                        <div class="flex-1 space-y-2">
                            <div class="h-3 w-2/5 rounded bg-gray-200"></div>
                            <div class="h-2.5 w-1/4 rounded bg-gray-100"></div>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="!isLoading" class="divide-y divide-gray-100">
                <template x-for="member in members" :key="member.id">
                    <div class="flex items-center justify-between gap-3 px-2 py-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <img :src="member.avatar" :alt="member.name"
                                 class="h-10 w-10 shrink-0 rounded-full bg-gray-100 object-cover ring-1 ring-gray-200">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-800" x-text="member.name"></p>
                                <p class="truncate text-xs text-gray-500" x-text="member.position"></p>
                            </div>
                        </div>

                        <span x-show="member.is_leader"
                              class="shrink-0 rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-semibold uppercase text-amber-700 ring-1 ring-amber-200">
                            Trưởng tổ
                        </span>
                        <button x-show="!member.is_leader" type="button" @click="removeMember(member.id)"
                                :disabled="actionUserId === member.id"
                                :aria-label="`Xóa ${member.name}`"
                                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-red-500 transition-colors hover:bg-red-50 hover:text-red-600 disabled:opacity-50">
                            <i :class="actionUserId === member.id ? 'fa-spinner fa-spin' : 'fa-trash'" class="fa-solid text-sm"></i>
                        </button>
                    </div>
                </template>

                <div x-show="members.length === 0" class="py-12 text-center">
                    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                        <i class="fa-solid fa-user-group"></i>
                    </span>
                    <p class="mt-3 text-sm font-semibold text-gray-700">Chưa có thành viên</p>
                    <p class="mt-1 text-xs text-gray-500">Tìm kiếm phía trên để thêm người vào tổ.</p>
                </div>
            </div>
        </main>
    </div>
</div>

<div x-show="toast.show" x-transition
     class="fixed right-5 top-5 z-[70] flex max-w-sm items-center gap-3 rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white shadow-xl">
    <i :class="toast.type === 'success' ? 'fa-circle-check text-green-400' : 'fa-circle-exclamation text-red-400'"
       class="fa-solid"></i>
    <span x-text="toast.message"></span>
</div>
</div>
@endsection

@push('scripts')
<script>
function departmentMembers() {
    return {
        isDeptFormOpen: @json((bool) ($editDepartment || $errors->any())),
        deptForm: {
            action: @json($editDepartment ? 'edit' : 'create'),
            id: @json($editDepartment?->id),
            name: @json(old('name', $editDepartment?->name ?? '')),
            description: @json(old('description', $editDepartment?->description ?? '')),
            leader_id: @json((string) old('leader_id', $editDepartment?->leader_id ?? '')),
            badge_color: @json(old('badge_color', $editDepartment?->badge_color ?? 'blue')),
        },
        isOpen: false,
        currentDept: { id: null, name: '' },
        members: [],
        searchQuery: '',
        searchResults: [],
        isLoading: false,
        isSearching: false,
        hasSearched: false,
        actionUserId: null,
        toast: { show: false, message: '', type: 'success' },
        membersUrlTemplate: @json(route('department_members_api', ['department' => '__DEPT__'])),
        searchUrl: @json(route('department_member_search_api')),
        addUrlTemplate: @json(route('department_member_add_api', ['department' => '__DEPT__'])),
        removeUrlTemplate: @json(route('department_member_destroy_api', ['department' => '__DEPT__', 'user' => '__USER__'])),

        init() {
            this.$watch('searchQuery', () => this.searchUsers());
        },

        openDeptForm() {
            this.deptForm = {
                action: 'create',
                id: null,
                name: '',
                description: '',
                leader_id: '',
                badge_color: 'blue',
            };
            this.isDeptFormOpen = true;
            this.$nextTick(() => document.getElementById('dept-name')?.focus());
        },

        closeDeptForm() {
            this.isDeptFormOpen = false;
        },

        handleEscape() {
            if (this.isDeptFormOpen) {
                this.closeDeptForm();
                return;
            }
            this.closeModal();
        },

        async openModal(id, name) {
            this.currentDept = { id, name };
            this.members = [];
            this.searchQuery = '';
            this.searchResults = [];
            this.hasSearched = false;
            this.isOpen = true;
            this.isLoading = true;

            try {
                const response = await fetch(this.url(this.membersUrlTemplate), {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Không thể tải danh sách thành viên.');
                this.members = payload.members;
                this.$nextTick(() => document.getElementById('member-search')?.focus());
            } catch (error) {
                this.notify(error.message, 'error');
                this.isOpen = false;
            } finally {
                this.isLoading = false;
            }
        },

        closeModal() {
            if (this.actionUserId !== null) return;
            this.isOpen = false;
            this.searchQuery = '';
            this.searchResults = [];
        },

        async searchUsers() {
            const query = this.searchQuery.trim();
            if (query.length < 2 || !this.currentDept.id) {
                this.searchResults = [];
                this.hasSearched = false;
                return;
            }

            this.isSearching = true;
            try {
                const url = new URL(this.searchUrl, window.location.origin);
                url.searchParams.set('q', query);
                url.searchParams.set('department_id', this.currentDept.id);
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Không thể tìm kiếm nhân sự.');
                if (query === this.searchQuery.trim()) {
                    this.searchResults = payload.users;
                    this.hasSearched = true;
                }
            } catch (error) {
                this.notify(error.message, 'error');
            } finally {
                this.isSearching = false;
            }
        },

        async addMember(userId) {
            if (this.actionUserId !== null) return;
            this.actionUserId = userId;

            try {
                const response = await fetch(this.url(this.addUrlTemplate), {
                    method: 'POST',
                    headers: this.jsonHeaders(),
                    body: JSON.stringify({ user_id: userId }),
                });
                const payload = await response.json();
                if (!response.ok) {
                    const validationMessage = Object.values(payload.errors || {}).flat()[0];
                    throw new Error(payload.error || validationMessage || 'Không thể thêm thành viên.');
                }

                this.members.push(payload.member);
                this.searchResults = this.searchResults.filter((user) => Number(user.id) !== Number(userId));
                this.updateMemberCount(payload.member_count);
                this.notify(payload.message || 'Đã thêm thành viên.', 'success');
            } catch (error) {
                this.notify(error.message, 'error');
            } finally {
                this.actionUserId = null;
            }
        },

        async removeMember(userId) {
            const member = this.members.find((item) => Number(item.id) === Number(userId));
            if (!member || member.is_leader || !window.confirm(`Xóa ${member.name} khỏi Tổ/Nhóm?`)) return;
            if (this.actionUserId !== null) return;
            this.actionUserId = userId;

            try {
                const endpoint = this.url(this.removeUrlTemplate).replace('__USER__', userId);
                const response = await fetch(endpoint, {
                    method: 'DELETE',
                    headers: this.jsonHeaders(),
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Không thể xóa thành viên.');

                this.members = this.members.filter((item) => Number(item.id) !== Number(userId));
                this.updateMemberCount(payload.member_count);
                this.notify(payload.message || 'Đã xóa thành viên.', 'success');
                if (this.searchQuery.trim().length >= 2) this.searchUsers();
            } catch (error) {
                this.notify(error.message, 'error');
            } finally {
                this.actionUserId = null;
            }
        },

        url(template) {
            return template.replace('__DEPT__', this.currentDept.id);
        },

        jsonHeaders() {
            return {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            };
        },

        updateMemberCount(count) {
            const counter = document.querySelector(`[data-member-count="${this.currentDept.id}"]`);
            if (counter) counter.textContent = `${count} thành viên`;
        },

        notify(message, type = 'success') {
            this.toast = { show: true, message, type };
            window.clearTimeout(this.toastTimer);
            this.toastTimer = window.setTimeout(() => { this.toast.show = false; }, 3500);
        },
    };
}
</script>
@endpush
