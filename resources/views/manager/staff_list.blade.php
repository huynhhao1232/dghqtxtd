@extends('layouts.app')

@section('title', 'Quản lý nhân sự')

@push('styles')
<style>[x-cloak] { display: none !important; }</style>
@endpush

@section('content')
<div x-data="staffManager()" x-cloak @keydown.escape.window="closeEditModal()">
<div class="mx-auto max-w-7xl">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-gray-900">Quản lý nhân sự</h1>
            <p class="mt-1 text-sm text-gray-500">Quản lý tài khoản, vai trò và tổ/nhóm của viên chức trong hệ thống.</p>
        </div>
        <button type="button" onclick="openUser()"
                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            <i class="fa-solid fa-plus text-xs"></i>
            Tạo tài khoản
        </button>
    </div>

    <form method="GET" action="{{ route('manager_staff') }}"
          class="mb-6 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-12">
            <div class="relative md:col-span-5">
                <label for="q" class="sr-only">Tìm kiếm nhân sự</label>
                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-gray-400"></i>
                <input id="q" name="q" type="search" value="{{ request('q') }}"
                       placeholder="Tìm theo tên, username, email..."
                       class="block h-11 w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-11 pr-4 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
            </div>

            <div class="md:col-span-4">
                <label for="department" class="sr-only">Lọc theo Tổ/Nhóm</label>
                <select id="department" name="department"
                        class="block h-11 w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-700 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                    <option value="">Tất cả Tổ/Nhóm</option>
                    @foreach($departments as $department)
                        <option value="{{ $department->id }}" @selected((string) request('department') === (string) $department->id)>
                            {{ $department->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex gap-2 md:col-span-3">
                <button type="submit"
                        class="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-gray-800 px-5 text-sm font-semibold text-white transition-colors hover:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-600 focus:ring-offset-2">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i>
                    Tìm kiếm
                </button>
                @if(request()->filled('q') || request()->filled('department'))
                    <a href="{{ route('manager_staff') }}" title="Xóa bộ lọc" aria-label="Xóa bộ lọc"
                       class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-gray-300 text-gray-500 transition-colors hover:bg-gray-50 hover:text-gray-700">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                @endif
            </div>
        </div>
    </form>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Nhân sự</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Tổ/Nhóm</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Vai trò</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Trạng thái</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">
                            <span class="sr-only">Hành động</span>
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($users as $user)
                        <tr data-user-row="{{ $user->id }}" class="transition-colors hover:bg-gray-50/70">
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $user->avatar_url }}" alt="{{ $user->full_name_vn }}"
                                         class="h-11 w-11 shrink-0 rounded-full bg-gray-100 object-cover ring-1 ring-gray-200">
                                    <div class="min-w-0">
                                        <p data-field="name" class="truncate text-sm font-semibold text-gray-900">{{ $user->full_name_vn }}</p>
                                        <p class="mt-0.5 truncate text-xs text-gray-500">
                                            <span data-field="username">{{ '@'.$user->username }}</span>
                                            <span class="mx-1 text-gray-300">•</span>
                                            {{ $user->position ?: 'Chưa cập nhật chức danh' }}
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <td class="px-6 py-4">
                                <div data-field="departments" class="flex max-w-sm flex-wrap gap-1.5">
                                    @forelse($user->departments as $department)
                                        <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                                            {{ $department->name }}
                                        </span>
                                    @empty
                                        <span class="text-sm text-gray-400">Chưa phân tổ</span>
                                    @endforelse
                                </div>
                            </td>

                            <td class="whitespace-nowrap px-6 py-4">
                                @if($user->is_manager)
                                    <span data-field="role" class="inline-flex items-center rounded-full bg-purple-50 px-2.5 py-1 text-xs font-semibold text-purple-700 ring-1 ring-inset ring-purple-200">
                                        Quản lý
                                    </span>
                                @else
                                    <span data-field="role" class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-200">
                                        Nhân viên
                                    </span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap px-6 py-4">
                                @if($user->is_active)
                                    <span data-field="status" class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">
                                        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span>
                                        Đang hoạt động
                                    </span>
                                @else
                                    <span data-field="status" class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">
                                        <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>
                                        Bị khóa
                                    </span>
                                @endif
                            </td>

                            <td class="whitespace-nowrap px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" @click="openEditModal({{ $user->id }})"
                                            data-field="edit-button"
                                            title="Sửa tài khoản" aria-label="Sửa tài khoản {{ $user->full_name_vn }}"
                                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500/30">
                                        <i class="fa-solid fa-pen text-sm"></i>
                                    </button>

                                    <form method="POST" action="{{ route('manager_staff_toggle', $user) }}"
                                          data-field="toggle-form" data-user-name="{{ $user->full_name_vn }}"
                                          data-user-active="{{ $user->is_active ? '1' : '0' }}"
                                          onsubmit="return confirmToggle(this)">
                                        @csrf
                                        <button type="submit"
                                                data-field="toggle-button"
                                                title="{{ $user->is_active ? 'Khóa tài khoản' : 'Mở khóa tài khoản' }}"
                                                aria-label="{{ $user->is_active ? 'Khóa' : 'Mở khóa' }} tài khoản {{ $user->full_name_vn }}"
                                                class="inline-flex h-9 w-9 items-center justify-center rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-100 {{ $user->is_active ? 'hover:text-red-600' : 'hover:text-green-600' }} focus:outline-none focus:ring-2 focus:ring-gray-400/30">
                                            <i data-field="toggle-icon" class="fa-solid {{ $user->is_active ? 'fa-lock' : 'fa-unlock' }} text-sm"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-16 text-center">
                                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                    <i class="fa-solid fa-user-slash"></i>
                                </span>
                                <p class="mt-3 text-sm font-semibold text-gray-700">Không tìm thấy nhân sự</p>
                                <p class="mt-1 text-sm text-gray-500">Hãy thử thay đổi từ khóa hoặc bộ lọc Tổ/Nhóm.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($users->hasPages())
            <div class="border-t border-gray-200 p-4">
                {{ $users->links() }}
            </div>
        @endif
    </div>
</div>

<div id="user-modal"
     class="fixed inset-0 z-50 {{ $errors->any() ? 'flex' : 'hidden' }} items-center justify-center overflow-y-auto bg-gray-900/60 p-4 backdrop-blur-sm"
     role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
    <form method="POST" action="{{ route('manager_staff_create') }}"
          class="my-6 w-full max-w-2xl overflow-hidden rounded-xl bg-white shadow-xl">
        @csrf

        <div class="flex items-start justify-between border-b border-gray-200 px-6 py-5">
            <div>
                <h2 id="user-modal-title" class="text-lg font-bold text-gray-900">Tạo tài khoản mới</h2>
                <p class="mt-1 text-sm text-gray-500">Nhập thông tin và phân quyền cho nhân sự.</p>
            </div>
            <button type="button" onclick="closeUser()" aria-label="Đóng"
                    class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="max-h-[70vh] space-y-5 overflow-y-auto p-6">
            @if($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    <i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $errors->first() }}
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="last_name" class="mb-1.5 block text-sm font-medium text-gray-700">Họ</label>
                    <input id="last_name" name="last_name" value="{{ old('last_name') }}"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="first_name" class="mb-1.5 block text-sm font-medium text-gray-700">Tên đệm và tên <span class="text-red-500">*</span></label>
                    <input id="first_name" name="first_name" value="{{ old('first_name') }}" required
                           class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="username" class="mb-1.5 block text-sm font-medium text-gray-700">Tên đăng nhập <span class="text-red-500">*</span></label>
                    <input id="username" name="username" value="{{ old('username') }}" required
                           class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-gray-700">Email</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="position" class="mb-1.5 block text-sm font-medium text-gray-700">Chức danh</label>
                    <input id="position" name="position" value="{{ old('position') }}"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="phone" class="mb-1.5 block text-sm font-medium text-gray-700">Điện thoại</label>
                    <input id="phone" name="phone" value="{{ old('phone') }}"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
            </div>

            <div>
                <label for="role" class="mb-1.5 block text-sm font-medium text-gray-700">Vai trò <span class="text-red-500">*</span></label>
                <select id="role" name="role"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="staff" @selected(old('role', 'staff') === 'staff')>Nhân viên</option>
                    <option value="manager" @selected(old('role', 'staff') === 'manager')>Quản lý</option>
                </select>
            </div>

            <div>
                <p class="mb-2 block text-sm font-medium text-gray-700">Tổ/Nhóm</p>
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    @foreach($departments as $department)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 transition hover:bg-gray-50">
                            <input type="checkbox" name="departments[]" value="{{ $department->id }}"
                                   @checked(in_array($department->id, old('departments', [])))
                                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            {{ $department->name }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium text-gray-700">
                        Mật khẩu <span class="text-red-500">*</span>
                    </label>
                    <input id="password" type="password" name="password" required placeholder="Tối thiểu 8 ký tự"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="password_confirmation" class="mb-1.5 block text-sm font-medium text-gray-700">Nhập lại mật khẩu</label>
                    <input id="password_confirmation" type="password" name="password_confirmation"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500">
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4">
            <button type="button" onclick="closeUser()"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">
                Hủy
            </button>
            <button type="submit"
                    class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700">
                Tạo tài khoản
            </button>
        </div>
    </form>
</div>

<div x-show="isModalOpen" x-transition.opacity.duration.200ms
     class="fixed inset-0 z-[60] flex items-center justify-center bg-gray-900/60 p-4 backdrop-blur-sm"
     role="dialog" aria-modal="true" aria-labelledby="edit-staff-title"
     @click.self="closeEditModal()">
    <div x-show="isModalOpen"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-4 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-4 scale-95"
         class="w-full max-w-xl overflow-hidden rounded-xl bg-white shadow-2xl">
        <div class="flex items-start justify-between border-b border-gray-200 px-6 py-5">
            <div>
                <h2 id="edit-staff-title" class="text-lg font-bold text-gray-900">Sửa viên chức</h2>
                <p class="mt-1 text-sm text-gray-500">Cập nhật thông tin mà không tải lại trang.</p>
            </div>
            <button type="button" @click="closeEditModal()" aria-label="Đóng"
                    class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="relative min-h-64">
            <div x-show="isLoading && !editForm.id"
                 class="absolute inset-0 z-10 flex flex-col items-center justify-center bg-white">
                <i class="fa-solid fa-spinner fa-spin text-2xl text-blue-600"></i>
                <p class="mt-3 text-sm text-gray-500">Đang tải thông tin...</p>
            </div>

            <form @submit.prevent="saveUser()" class="max-h-[70vh] space-y-5 overflow-y-auto p-6">
                <template x-if="Object.keys(errors).length">
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        <p class="font-semibold">Vui lòng kiểm tra lại thông tin.</p>
                        <ul class="mt-1 list-inside list-disc">
                            <template x-for="message in Object.values(errors).flat()" :key="message">
                                <li x-text="message"></li>
                            </template>
                        </ul>
                    </div>
                </template>

                <div>
                    <label for="edit-name" class="mb-1.5 block text-sm font-medium text-gray-700">
                        Họ và tên <span class="text-red-500">*</span>
                    </label>
                    <input id="edit-name" type="text" x-model="editForm.name" required
                           class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                </div>

                <div>
                    <label for="edit-username" class="mb-1.5 block text-sm font-medium text-gray-700">
                        Tên đăng nhập <span class="text-red-500">*</span>
                    </label>
                    <input id="edit-username" type="text" x-model="editForm.username" required
                           class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                </div>

                <div>
                    <label for="edit-role" class="mb-1.5 block text-sm font-medium text-gray-700">Vai trò</label>
                    <select id="edit-role" x-model="editForm.role"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        <option value="staff">Nhân viên</option>
                        <option value="manager">Quản lý</option>
                    </select>
                </div>

                <div>
                    <span class="mb-2 block text-sm font-medium text-gray-700">Tổ/Nhóm</span>
                    <div class="min-h-12 rounded-lg border border-gray-300 bg-white p-2">
                        <div class="flex flex-wrap gap-2">
                            <template x-for="departmentId in editForm.departments" :key="departmentId">
                                <span class="inline-flex items-center gap-1.5 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">
                                    <span x-text="departmentName(departmentId)"></span>
                                    <button type="button" @click="removeDepartment(departmentId)"
                                            class="text-gray-400 transition hover:text-red-600"
                                            :aria-label="`Xóa ${departmentName(departmentId)}`">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </span>
                            </template>
                            <span x-show="editForm.departments.length === 0" class="px-1 py-1 text-xs text-gray-400">
                                Chưa chọn tổ/nhóm
                            </span>
                        </div>
                    </div>
                    <select @change="addDepartment($event)" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20">
                        <option value="">+ Thêm Tổ/Nhóm</option>
                        <template x-for="department in availableDepartments" :key="department.id">
                            <option :value="department.id" x-text="department.name"></option>
                        </template>
                    </select>
                </div>

                <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 p-4">
                    <div>
                        <p class="text-sm font-semibold text-gray-800">Trạng thái tài khoản</p>
                        <p class="mt-0.5 text-xs text-gray-500" x-text="editForm.is_active ? 'Đang hoạt động' : 'Tài khoản bị khóa'"></p>
                    </div>
                    <label class="relative inline-flex shrink-0 cursor-pointer">
                        <input type="checkbox" x-model="editForm.is_active" class="peer sr-only">
                        <span :class="editForm.is_active ? 'bg-blue-600' : 'bg-gray-300'"
                              class="relative inline-flex h-6 w-11 rounded-full transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 peer-focus-visible:ring-offset-2">
                            <span :class="editForm.is_active ? 'translate-x-5' : 'translate-x-0'"
                                  class="pointer-events-none inline-block h-5 w-5 translate-y-0.5 rounded-full bg-white shadow ring-0 transition-transform"></span>
                        </span>
                        <span class="sr-only">Bật hoặc khóa tài khoản</span>
                    </label>
                </div>
            </form>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4">
            <button type="button" @click="closeEditModal()" :disabled="isLoading"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50">
                Hủy
            </button>
            <button type="button" @click="saveUser()" :disabled="isLoading"
                    class="inline-flex min-w-32 items-center justify-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">
                <i x-show="isLoading" class="fa-solid fa-spinner fa-spin"></i>
                <span x-text="isLoading ? 'Đang lưu...' : 'Lưu lại'"></span>
            </button>
        </div>
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
function openUser() {
    const modal = document.getElementById('user-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.getElementById('first_name')?.focus();
}

function closeUser() {
    window.location.href = @json(route('manager_staff'));
}

function confirmToggle(form) {
    const action = form.dataset.userActive === '1' ? 'Khóa' : 'Mở khóa';
    return window.confirm(`${action} tài khoản ${form.dataset.userName}?`);
}

document.getElementById('user-modal')?.addEventListener('click', (event) => {
    if (event.target === event.currentTarget) closeUser();
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !document.getElementById('user-modal')?.classList.contains('hidden')) closeUser();
});

function staffManager() {
    return {
        isModalOpen: false,
        isLoading: false,
        errors: {},
        editForm: {
            id: '',
            name: '',
            username: '',
            role: '',
            is_active: false,
            departments: [],
        },
        allDepartments: @json($departments->map(fn ($department) => ['id' => $department->id, 'name' => $department->name])->values()),
        toast: { show: false, message: '', type: 'success' },
        showUrlTemplate: @json(route('manager_staff_api_show', ['user' => '__ID__'])),
        updateUrlTemplate: @json(route('manager_staff_api_update', ['user' => '__ID__'])),

        get availableDepartments() {
            return this.allDepartments.filter((department) => !this.editForm.departments.includes(Number(department.id)));
        },

        departmentName(id) {
            return this.allDepartments.find((department) => Number(department.id) === Number(id))?.name ?? 'Không xác định';
        },

        addDepartment(event) {
            const id = Number(event.target.value);
            if (id && !this.editForm.departments.includes(id)) this.editForm.departments.push(id);
            event.target.value = '';
        },

        removeDepartment(id) {
            this.editForm.departments = this.editForm.departments.filter((item) => Number(item) !== Number(id));
        },

        async openEditModal(id) {
            this.isModalOpen = true;
            this.isLoading = true;
            this.errors = {};
            this.editForm = { id: '', name: '', username: '', role: '', is_active: false, departments: [] };

            try {
                const response = await fetch(this.showUrlTemplate.replace('__ID__', id), {
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) throw new Error('Không thể tải thông tin nhân sự.');

                const { user } = await response.json();
                this.editForm = {
                    id: user.id,
                    name: user.name,
                    username: user.username,
                    role: user.role,
                    is_active: Boolean(user.is_active),
                    departments: user.departments.map((department) => Number(department.id)),
                };
                this.$nextTick(() => document.getElementById('edit-name')?.focus());
            } catch (error) {
                this.notify(error.message, 'error');
                this.isModalOpen = false;
            } finally {
                this.isLoading = false;
            }
        },

        closeEditModal() {
            if (this.isLoading) return;
            this.isModalOpen = false;
            this.errors = {};
        },

        async saveUser() {
            if (!this.editForm.id || this.isLoading) return;
            this.isLoading = true;
            this.errors = {};

            try {
                const response = await fetch(this.updateUrlTemplate.replace('__ID__', this.editForm.id), {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    },
                    body: JSON.stringify(this.editForm),
                });
                const payload = await response.json();

                if (response.status === 422) {
                    this.errors = payload.errors ?? {};
                    return;
                }
                if (!response.ok) throw new Error(payload.message ?? 'Không thể cập nhật nhân sự.');

                this.updateTableRow(payload.user);
                this.isModalOpen = false;
                this.notify(payload.message ?? 'Đã cập nhật thành công.', 'success');
            } catch (error) {
                this.notify(error.message, 'error');
            } finally {
                this.isLoading = false;
            }
        },

        updateTableRow(user) {
            const row = document.querySelector(`[data-user-row="${user.id}"]`);
            if (!row) return;

            row.querySelector('[data-field="name"]').textContent = user.name;
            row.querySelector('[data-field="username"]').textContent = `@${user.username}`;
            const editButton = row.querySelector('[data-field="edit-button"]');
            editButton.title = `Sửa tài khoản ${user.name}`;
            editButton.setAttribute('aria-label', editButton.title);

            const role = row.querySelector('[data-field="role"]');
            role.textContent = user.role_label;
            role.className = user.role === 'manager'
                ? 'inline-flex items-center rounded-full bg-purple-50 px-2.5 py-1 text-xs font-semibold text-purple-700 ring-1 ring-inset ring-purple-200'
                : 'inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 ring-1 ring-inset ring-blue-200';

            const status = row.querySelector('[data-field="status"]');
            status.className = user.is_active
                ? 'inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700'
                : 'inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700';
            const dot = document.createElement('span');
            dot.className = user.is_active ? 'h-1.5 w-1.5 rounded-full bg-green-500' : 'h-1.5 w-1.5 rounded-full bg-red-500';
            status.replaceChildren(dot, document.createTextNode(user.is_active ? ' Đang hoạt động' : ' Bị khóa'));

            const toggleForm = row.querySelector('[data-field="toggle-form"]');
            const toggleButton = row.querySelector('[data-field="toggle-button"]');
            const toggleIcon = row.querySelector('[data-field="toggle-icon"]');
            toggleForm.dataset.userName = user.name;
            toggleForm.dataset.userActive = user.is_active ? '1' : '0';
            toggleButton.title = user.is_active ? 'Khóa tài khoản' : 'Mở khóa tài khoản';
            toggleButton.setAttribute('aria-label', `${toggleButton.title} ${user.name}`);
            toggleButton.className = `inline-flex h-9 w-9 items-center justify-center rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-100 ${user.is_active ? 'hover:text-red-600' : 'hover:text-green-600'} focus:outline-none focus:ring-2 focus:ring-gray-400/30`;
            toggleIcon.className = `fa-solid ${user.is_active ? 'fa-lock' : 'fa-unlock'} text-sm`;

            const departments = row.querySelector('[data-field="departments"]');
            departments.replaceChildren();
            if (!user.departments.length) {
                const empty = document.createElement('span');
                empty.className = 'text-sm text-gray-400';
                empty.textContent = 'Chưa phân tổ';
                departments.append(empty);
                return;
            }
            user.departments.forEach((department) => {
                const tag = document.createElement('span');
                tag.className = 'inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700';
                tag.textContent = department.name;
                departments.append(tag);
            });
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
