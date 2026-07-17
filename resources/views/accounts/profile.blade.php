@extends('layouts.app')

@section('title', 'Trang cá nhân — EduEval')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Trang cá nhân</h1>
        <p class="text-sm text-gray-500 mt-1">Quản lý thông tin tài khoản của bạn</p>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <div class="flex items-center gap-4 mb-6">
            <img src="{{ $user->avatar_url }}" alt="Avatar" class="h-20 w-20 rounded-full object-cover border-2 border-blue-100">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">{{ $user->full_name_vn ?: $user->username }}</h2>
                <p class="text-sm text-gray-500">{{ $user->role_label }}</p>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="text-xs font-semibold text-gray-400 uppercase">Họ và tên</label>
                <p class="mt-1 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-700">{{ $user->full_name_vn ?: '—' }}</p>
            </div>
            <div>
                <label class="text-xs font-semibold text-gray-400 uppercase">Tên đăng nhập</label>
                <p class="mt-1 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-700">{{ $user->username }}</p>
            </div>
            <div>
                <label class="text-xs font-semibold text-gray-400 uppercase">Phòng ban / Tổ chuyên môn</label>
                <p class="mt-1 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-700">{{ $user->department_name }}</p>
            </div>
            <div>
                <label class="text-xs font-semibold text-gray-400 uppercase">Chức vụ</label>
                <p class="mt-1 px-3 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm text-gray-700">{{ $user->position ?: '—' }}</p>
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-400"><i class="fas fa-lock mr-1"></i>Họ tên, phòng ban, chức vụ chỉ Admin được sửa.</p>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Cập nhật thông tin liên hệ</h3>
        <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="hidden" name="action" value="profile">
            <div>
                <label for="avatar" class="block text-sm font-medium text-gray-700 mb-1">Ảnh đại diện</label>
                <input id="avatar" name="avatar" type="file" accept="image/*"
                       class="block w-full text-sm text-gray-600 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-primary hover:file:bg-blue-100">
                @error('avatar') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Số điện thoại</label>
                <input id="phone" name="phone" type="text" value="{{ old('phone', $user->phone) }}"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                @error('phone') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-blue-800">
                <i class="fas fa-save mr-2"></i> Lưu thay đổi
            </button>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Đổi mật khẩu</h3>
        <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="action" value="password">
            <div>
                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Mật khẩu hiện tại</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                @error('current_password') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Mật khẩu mới</label>
                <input id="password" name="password" type="password" autocomplete="new-password"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                @error('password') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Xác nhận mật khẩu mới</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
            </div>
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 text-white text-sm font-medium rounded-lg hover:bg-gray-900">
                <i class="fas fa-key mr-2"></i> Đổi mật khẩu
            </button>
        </form>
    </div>
</div>
@endsection
