<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Hệ thống Đánh giá Hiệu quả Công việc')</title>
    <link rel="icon" type="image/png" href="{{ asset('images/school-logo.png') }}">
    <script src="https://cdn.tailwindcss.com"></script>
    @vite('resources/js/app.js')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: { extend: { colors: {
                primary: '#1d4ed8', secondary: '#f3f4f6', danger: '#ef4444', success: '#10b981'
            } } }
        };
    </script>
    <style>[x-cloak] { display: none !important; }</style>
    @stack('styles')
    @yield('extra_css')
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased overflow-hidden">
    <div id="sidebar-overlay" class="fixed inset-0 bg-black/40 z-30 hidden md:hidden"></div>

    <div class="flex h-screen w-full overflow-hidden">
        <aside id="sidebar"
               class="fixed inset-y-0 left-0 w-64 bg-white border-r border-gray-200 flex flex-col z-40
                      transform -translate-x-full transition-transform duration-200 ease-in-out
                      md:static md:translate-x-0 md:flex md:h-full md:shrink-0 md:z-20">
            <div class="relative h-16 flex items-center justify-center px-4 border-b border-gray-200">
                <a href="{{ route('home') }}" class="flex items-center" aria-label="Trang chủ">
                    <img src="{{ asset('images/school-logo.png') }}"
                         alt="Logo Trung tâm GDNN-GDTX Thành phố Thủ Đức"
                         class="w-10 h-10 rounded-full object-contain shrink-0">
                </a>
                <button type="button" id="sidebar-close-btn" class="absolute right-4 md:hidden text-gray-400 hover:text-gray-600 p-1" aria-label="Đóng menu">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            @php
                $navBase = 'flex items-center px-3 py-2 text-sm font-medium rounded-md';
                $navIdle = 'text-gray-600 hover:bg-gray-100 hover:text-gray-900';
                $navActive = 'bg-blue-50 text-primary';
            @endphp
            <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
                @if (auth()->user()->is_manager)
                    <a href="{{ route('manager_dashboard') }}" class="{{ $navBase }} {{ request()->routeIs('manager_dashboard') ? $navActive : $navIdle }}">
                        <i class="fas fa-home w-6"></i> Tổng quan
                    </a>
                    <p class="px-3 pt-4 pb-2 text-xs font-semibold text-gray-400 uppercase tracking-wider">Quản lý</p>
                    <a href="{{ route('manager_manage_tasks') }}" class="{{ $navBase }} {{ request()->routeIs('manager_manage_tasks', 'manager_task_edit') ? $navActive : $navIdle }}">
                        <i class="fas fa-list-check w-6"></i> Công việc đã giao
                    </a>
                    <a href="{{ route('manager_review_queue') }}" class="{{ $navBase }} {{ request()->routeIs('manager_review_queue', 'manager_review_task') ? $navActive : $navIdle }}">
                        <i class="fas fa-check-double w-6"></i> Hàng đợi duyệt
                    </a>
                    <a href="{{ route('manager_departments') }}" class="{{ $navBase }} {{ request()->routeIs('manager_departments') ? $navActive : $navIdle }}">
                        <i class="fas fa-sitemap w-6"></i> Quản lý Tổ/Nhóm
                    </a>
                    <a href="{{ route('manager_staff') }}" class="{{ $navBase }} {{ request()->routeIs('manager_staff') ? $navActive : $navIdle }}">
                        <i class="fas fa-users w-6"></i> Quản lý Viên chức
                    </a>
                @else
                    <a href="{{ route('staff_dashboard') }}" class="{{ $navBase }} {{ request()->routeIs('staff_dashboard') ? $navActive : $navIdle }}">
                        <i class="fas fa-home w-6"></i> Tổng quan
                    </a>
                    <p class="px-3 pt-4 pb-2 text-xs font-semibold text-gray-400 uppercase tracking-wider">Cá nhân</p>
                    <a href="{{ route('staff_my_tasks') }}" class="{{ $navBase }} {{ request()->routeIs('staff_my_tasks', 'staff_task_detail') ? $navActive : $navIdle }}">
                        <i class="fas fa-clipboard-list w-6"></i> Việc của tôi
                    </a>
                @endif

                @if ($sidebar_departments->isNotEmpty())
                    <p class="px-3 pt-4 pb-2 text-xs font-semibold text-gray-400 uppercase tracking-wider">Nhóm của tôi</p>
                    @foreach ($sidebar_departments as $department)
                        <a href="{{ route('department_interaction', $department) }}"
                           class="{{ $navBase }} {{ request()->routeIs('department_interaction') ? $navActive : $navIdle }}">
                            <i class="fas fa-users-cog w-6 shrink-0"></i>
                            <span class="truncate">{{ $department->name }}</span>
                        </a>
                    @endforeach
                @endif

                @if (auth()->user()->is_manager)
                    <p class="px-3 pt-4 pb-2 text-xs font-semibold text-gray-400 uppercase tracking-wider">Báo cáo</p>
                    <a href="{{ route('manager_statistics') }}" class="{{ $navBase }} {{ request()->routeIs('manager_statistics') ? $navActive : $navIdle }}">
                        <i class="fas fa-chart-bar w-6"></i> Thống kê định lượng
                    </a>
                @endif
            </nav>

            <div class="p-4 border-t border-gray-200 space-y-1">
                <a href="{{ route('profile') }}" class="{{ $navBase }} {{ request()->routeIs('profile') ? $navActive : $navIdle }}">
                    <i class="fas fa-user w-6"></i> Trang cá nhân
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full flex items-center px-3 py-2 text-sm font-medium text-red-600 rounded-md hover:bg-red-50">
                        <i class="fas fa-sign-out-alt w-6 text-left"></i> Đăng xuất
                    </button>
                </form>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-w-0 min-h-0 bg-gray-50 overflow-hidden">
            <header class="h-16 shrink-0 bg-white border-b border-gray-200 flex items-center justify-between px-4 lg:px-8 z-10 shadow-sm">
                <button type="button" id="mobile-menu-btn" class="md:hidden text-gray-500 hover:text-gray-700 p-2 -ml-2" aria-label="Mở menu">
                    <i class="fas fa-bars text-xl"></i>
                </button>

                <div class="hidden md:flex flex-1 max-w-md">
                    <form method="GET" action="{{ auth()->user()->is_manager ? route('manager_review_queue') : route('staff_my_tasks') }}" class="relative w-full text-gray-400 focus-within:text-gray-600">
                        <span class="absolute inset-y-0 left-0 flex items-center pointer-events-none pl-3"><i class="fas fa-search"></i></span>
                        <input type="text" name="q" value="{{ request('q') }}"
                               class="block w-full h-10 pl-10 pr-3 py-2 border border-gray-300 rounded-lg bg-gray-50 placeholder-gray-500 focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary sm:text-sm"
                               placeholder="Tìm kiếm công việc, nhân sự...">
                    </form>
                </div>

                <div class="ml-4 flex items-center space-x-4 sm:space-x-6">
                    <div class="relative" x-data="notificationCenter()" @keydown.escape.window="isOpen = false">
                        <button type="button"
                                class="relative p-2 text-gray-400 rounded-full hover:text-gray-600 hover:bg-gray-100"
                                :class="isRinging ? 'animate-bounce text-blue-600' : ''"
                                aria-label="Thông báo"
                                @click="isOpen = !isOpen">
                            <i class="fas fa-bell text-xl"></i>
                            <span x-cloak x-show="unreadCount > 0"
                                  x-text="unreadCount > 99 ? '99+' : unreadCount"
                                  class="absolute -top-0.5 -right-0.5 flex items-center justify-center min-w-[1.1rem] h-4 px-1 rounded-full bg-red-500 text-white text-[10px] font-bold ring-2 ring-white"></span>
                        </button>
                        <div x-cloak x-show="isOpen" @click.outside="isOpen = false"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 translate-y-1 scale-95"
                             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                             x-transition:leave="transition ease-in duration-100"
                             x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                             x-transition:leave-end="opacity-0 translate-y-1 scale-95"
                             class="absolute right-0 mt-2 w-80 sm:w-96 bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden z-50">
                            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                                <span class="text-sm font-semibold text-gray-800">Thông báo</span>
                                <span class="text-xs text-gray-500"><span x-text="unreadCount"></span> chưa đọc</span>
                            </div>
                            <ul class="max-h-80 overflow-y-auto divide-y divide-gray-50">
                                <template x-if="isLoading">
                                    <li class="px-4 py-8 text-center text-sm text-gray-400">
                                        <i class="fas fa-spinner fa-spin mr-2"></i>Đang tải...
                                    </li>
                                </template>
                                <template x-for="notification in notifications" :key="notification.id">
                                    <li>
                                        <a :href="notification.url" @click.prevent="openNotification(notification)"
                                           class="flex gap-3 px-4 py-3 hover:bg-gray-50 transition-colors"
                                           :class="notification.read_at ? 'bg-white' : 'bg-blue-50'">
                                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                                  :class="notification.type === 'danger' ? 'bg-red-100 text-red-600' : (notification.type === 'success' ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-500')">
                                                <i class="fas" :class="notification.type === 'danger' ? 'fa-user-minus' : (notification.type === 'success' ? 'fa-user-check' : 'fa-bell')"></i>
                                            </span>
                                            <span class="min-w-0">
                                                <span class="block text-sm text-gray-800" :class="notification.read_at ? '' : 'font-semibold'" x-text="notification.message"></span>
                                                <span class="block text-xs text-gray-400 mt-1" x-text="notification.time_ago"></span>
                                            </span>
                                        </a>
                                    </li>
                                </template>
                                <template x-if="!isLoading && notifications.length === 0">
                                    <li class="px-4 py-8 text-center text-sm text-gray-400">Không có thông báo</li>
                                </template>
                            </ul>
                        </div>
                    </div>

                    <div class="relative" id="profile-wrapper">
                        <button type="button" id="profile-btn" class="flex items-center space-x-2 p-1 rounded-lg hover:bg-gray-50">
                            <img class="h-9 w-9 rounded-full object-cover border border-gray-200" src="{{ auth()->user()->avatar_url }}" alt="Avatar">
                            <span class="hidden sm:flex sm:flex-col sm:items-start">
                                <span class="text-sm font-bold text-gray-700">{{ auth()->user()->full_name_vn ?: auth()->user()->username }}</span>
                                <span class="text-xs font-medium text-gray-500">{{ auth()->user()->role_label }}</span>
                            </span>
                            <i class="fas fa-chevron-down text-xs text-gray-400 hidden sm:block ml-1"></i>
                        </button>
                        <div id="profile-dropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-xl shadow-lg border border-gray-200 py-1 z-50">
                            <a href="{{ route('profile') }}" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                <i class="fas fa-user w-5 text-gray-400"></i> Trang cá nhân
                            </a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="w-full flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                    <i class="fas fa-sign-out-alt w-5 text-left"></i> Đăng xuất
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main class="flex-1 min-h-0 overflow-y-auto p-4 md:p-6 lg:p-8">
                @foreach (['success', 'error', 'info', 'status'] as $type)
                    @if (session($type))
                        @php
                            $isError = $type === 'error';
                            $isInfo = in_array($type, ['info', 'status'], true);
                        @endphp
                        <div class="mb-4 p-4 rounded-lg flex items-center border shadow-sm {{ $isError ? 'bg-red-50 text-red-800 border-red-200' : ($isInfo ? 'bg-blue-50 text-blue-800 border-blue-200' : 'bg-green-50 text-green-800 border-green-200') }}">
                            <i class="fas {{ $isError ? 'fa-exclamation-circle' : ($isInfo ? 'fa-info-circle' : 'fa-check-circle') }} mr-2"></i>
                            <span class="text-sm font-medium">{{ session($type) }}</span>
                        </div>
                    @endif
                @endforeach
                @yield('content')
            </main>
        </div>
    </div>

    <div x-data="toastManager()"
         @new-toast.window="show($event.detail)"
         class="fixed bottom-5 right-5 z-[60] w-[calc(100%-2.5rem)] max-w-sm space-y-3 pointer-events-none"
         aria-live="polite">
        <template x-for="toast in toasts" :key="toast.id">
            <div x-show="toast.visible"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-x-8"
                 x-transition:enter-end="opacity-100 translate-x-0"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-x-0"
                 x-transition:leave-end="opacity-0 translate-x-8"
                 class="pointer-events-auto flex items-start gap-3 rounded-xl border bg-white p-4 shadow-xl"
                 :class="toast.type === 'danger' ? 'border-red-200' : 'border-blue-200'">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                      :class="toast.type === 'danger' ? 'bg-red-100 text-red-600' : 'bg-blue-100 text-blue-600'">
                    <i class="fas" :class="toast.type === 'danger' ? 'fa-user-minus' : 'fa-bell'"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-gray-900" x-text="toast.title"></p>
                    <p class="mt-0.5 text-sm text-gray-600" x-text="toast.message"></p>
                </div>
                <button type="button" class="p-1 text-gray-400 hover:text-gray-700" @click="dismiss(toast.id)" aria-label="Đóng">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </template>
    </div>

    <div id="file-preview-modal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
        <div class="bg-white w-full max-w-5xl h-[90vh] rounded-xl shadow-2xl flex flex-col overflow-hidden">
            <div class="shrink-0 border-b border-gray-200 px-5 py-3 space-y-2">
                <div class="flex items-center justify-between gap-3">
                    <h3 id="file-preview-title" class="text-sm sm:text-base font-semibold text-gray-800 truncate">Xem trước tài liệu</h3>
                    <div class="flex items-center gap-2">
                        <a id="file-preview-download" href="#" download class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg px-4 py-2">
                            <i class="fas fa-download"></i> Tải xuống
                        </a>
                        <button type="button" id="file-preview-close" class="p-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50" aria-label="Đóng">
                            <i class="fas fa-times text-lg"></i>
                        </button>
                    </div>
                </div>
                <p id="file-preview-office-note" class="hidden text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    Xem trước file Office chỉ hoạt động khi ứng dụng có địa chỉ công khai.
                </p>
            </div>
            <div id="file-preview-body" class="bg-gray-100 flex-1 overflow-auto w-full relative p-4"></div>
        </div>
    </div>

    <script>
        window.notificationCenter = function () {
            return {
                userId: {{ auth()->id() }},
                unreadCount: 0,
                notifications: [],
                isOpen: false,
                isLoading: true,
                isRinging: false,
                echoConnected: false,

                init() {
                    this.loadNotifications();

                    if (window.Echo) {
                        this.connectEcho();
                    } else {
                        window.addEventListener('echo-ready', () => this.connectEcho(), { once: true });
                    }
                },

                connectEcho() {
                    if (this.echoConnected || !window.Echo) return;

                    this.echoConnected = true;
                    window.Echo.private(`App.Models.User.${this.userId}`)
                        .notification((notification) => {
                            if (this.notifications.some(item => item.id === notification.id)) return;

                            const item = {
                                id: notification.id,
                                title: notification.title || 'Thông báo mới',
                                message: notification.message,
                                type: notification.type || 'info',
                                url: notification.url || '/',
                                read_at: null,
                                time_ago: 'Vừa xong',
                            };

                            this.unreadCount++;
                            this.notifications.unshift(item);
                            this.notifications = this.notifications.slice(0, 12);
                            this.isRinging = true;
                            window.setTimeout(() => { this.isRinging = false; }, 2000);

                            window.dispatchEvent(new CustomEvent('new-toast', {
                                detail: {
                                    title: item.title,
                                    message: item.message,
                                    type: item.type,
                                },
                            }));
                        });
                },

                destroy() {
                    if (this.echoConnected && window.Echo) {
                        window.Echo.leave(`App.Models.User.${this.userId}`);
                    }
                },

                async loadNotifications() {
                    this.isLoading = true;
                    try {
                        const response = await fetch('/api/notifications', {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin',
                        });
                        if (!response.ok) throw new Error('Không thể tải thông báo.');
                        const data = await response.json();
                        const serverIds = new Set(data.notifications.map(notification => notification.id));
                        const realtimeOnly = this.notifications.filter(notification => !serverIds.has(notification.id));
                        this.unreadCount = data.unread_count
                            + realtimeOnly.filter(notification => !notification.read_at).length;
                        this.notifications = [...realtimeOnly, ...data.notifications].slice(0, 12);
                    } catch (error) {
                        console.error(error);
                    } finally {
                        this.isLoading = false;
                    }
                },

                async openNotification(notification) {
                    if (!notification.read_at) {
                        try {
                            const response = await fetch(`/api/notifications/${encodeURIComponent(notification.id)}/mark-as-read`, {
                                method: 'PATCH',
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                },
                            });
                            if (response.ok) {
                                notification.read_at = new Date().toISOString();
                                this.unreadCount = Math.max(0, this.unreadCount - 1);
                            }
                        } catch (error) {
                            console.error(error);
                        }
                    }

                    window.location.href = notification.url || '/';
                },
            };
        };

        window.toastManager = function () {
            return {
                toasts: [],

                show(detail) {
                    const id = `${Date.now()}-${Math.random()}`;
                    this.toasts.push({
                        id,
                        title: detail.title || 'Thông báo mới',
                        message: detail.message || '',
                        type: detail.type || 'info',
                        visible: true,
                    });

                    window.setTimeout(() => this.dismiss(id), 5000);
                },

                dismiss(id) {
                    const toast = this.toasts.find(item => item.id === id);
                    if (!toast) return;

                    toast.visible = false;
                    window.setTimeout(() => {
                        this.toasts = this.toasts.filter(item => item.id !== id);
                    }, 250);
                },
            };
        };

        (function () {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');
            const openSidebar = () => { sidebar?.classList.remove('-translate-x-full'); overlay?.classList.remove('hidden'); };
            const closeSidebar = () => { sidebar?.classList.add('-translate-x-full'); overlay?.classList.add('hidden'); };
            document.getElementById('mobile-menu-btn')?.addEventListener('click', openSidebar);
            document.getElementById('sidebar-close-btn')?.addEventListener('click', closeSidebar);
            overlay?.addEventListener('click', closeSidebar);

            function dropdown(buttonId, dropdownId, wrapperId) {
                const button = document.getElementById(buttonId);
                const menu = document.getElementById(dropdownId);
                const wrapper = document.getElementById(wrapperId);
                button?.addEventListener('click', function (event) {
                    event.stopPropagation();
                    const open = menu.classList.contains('hidden');
                    document.querySelectorAll('#profile-dropdown').forEach(el => el.classList.add('hidden'));
                    if (open) menu.classList.remove('hidden');
                });
                document.addEventListener('click', event => {
                    if (wrapper && !wrapper.contains(event.target)) menu?.classList.add('hidden');
                });
            }
            dropdown('profile-btn', 'profile-dropdown', 'profile-wrapper');

            const modal = document.getElementById('file-preview-modal');
            const body = document.getElementById('file-preview-body');
            const title = document.getElementById('file-preview-title');
            const download = document.getElementById('file-preview-download');
            const officeNote = document.getElementById('file-preview-office-note');
            let blobUrl = null;

            function closePreview() {
                modal?.classList.add('hidden');
                if (blobUrl) URL.revokeObjectURL(blobUrl);
                blobUrl = null;
                body.innerHTML = '';
                officeNote?.classList.add('hidden');
            }
            function message(icon, text) {
                body.className = 'bg-gray-100 flex-1 w-full p-4 flex items-center justify-center';
                body.innerHTML = `<div class="text-center bg-white rounded-xl border p-10"><i class="fas ${icon} text-4xl text-gray-300 mb-3"></i><p class="text-sm text-gray-700">${text}</p></div>`;
            }
            function render(url, name) {
                const absolute = new URL(url, window.location.origin).href;
                const extension = (name || url).split('?')[0].split('.').pop().toLowerCase();
                body.innerHTML = '';
                officeNote.classList.add('hidden');
                if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(extension)) {
                    body.className = 'bg-gray-100 flex-1 overflow-auto w-full p-4 flex items-center justify-center';
                    const image = document.createElement('img');
                    image.src = absolute; image.alt = name; image.className = 'max-w-full max-h-full object-contain rounded-lg';
                    body.appendChild(image);
                } else if (extension === 'pdf') {
                    message('fa-spinner fa-spin', 'Đang tải xem trước...');
                    fetch(absolute, { credentials: 'same-origin' }).then(response => {
                        if (!response.ok) throw new Error();
                        return response.blob();
                    }).then(blob => {
                        blobUrl = URL.createObjectURL(blob);
                        body.className = 'bg-gray-100 flex-1 overflow-hidden w-full p-0';
                        body.innerHTML = `<embed src="${blobUrl}" type="application/pdf" class="w-full h-full">`;
                    }).catch(() => message('fa-exclamation-triangle', 'Không thể tải file PDF để xem trước.'));
                } else if (['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'].includes(extension)) {
                    officeNote.classList.remove('hidden');
                    if (/^(localhost|127\.0\.0\.1)$/.test(location.hostname)) {
                        message('fa-file', 'Không xem trước được file Office trên localhost. Vui lòng tải xuống để mở file.');
                    } else {
                        body.className = 'bg-gray-100 flex-1 overflow-hidden w-full p-0';
                        body.innerHTML = `<iframe src="https://view.officeapps.live.com/op/embed.aspx?src=${encodeURIComponent(absolute)}" class="w-full h-full border-0"></iframe>`;
                    }
                } else {
                    message('fa-exclamation-circle', 'Định dạng file không hỗ trợ xem trước. Vui lòng tải xuống.');
                }
            }
            document.addEventListener('click', event => {
                const trigger = event.target.closest('[data-file-url]');
                if (!trigger) return;
                event.preventDefault();
                const url = trigger.dataset.fileUrl;
                const name = trigger.dataset.fileName || decodeURIComponent(url.split('/').pop());
                title.textContent = `Xem trước tài liệu: ${name}`;
                download.href = url;
                render(url, name);
                modal.classList.remove('hidden');
            });
            document.getElementById('file-preview-close')?.addEventListener('click', closePreview);
            modal?.addEventListener('click', event => { if (event.target === modal) closePreview(); });
            document.addEventListener('keydown', event => { if (event.key === 'Escape') closePreview(); });
        })();
    </script>
    @stack('scripts')
    @yield('extra_js')

    @if (auth()->user()->is_manager)
        <a href="{{ route('manager_create_task') }}" aria-label="Giao việc mới" title="Giao việc mới"
           class="group fixed bottom-6 right-6 z-50 h-12 w-12 px-0 flex items-center justify-center overflow-hidden rounded-full bg-blue-600 text-white opacity-60 shadow-md transition-all duration-300 hover:w-36 hover:px-4 hover:bg-blue-700 hover:opacity-100 hover:shadow-lg">
            <i class="fas fa-plus shrink-0"></i>
            <span class="max-w-0 overflow-hidden whitespace-nowrap text-sm font-medium opacity-0 transition-all duration-300 group-hover:ml-2 group-hover:max-w-xs group-hover:opacity-100">
                Giao việc
            </span>
        </a>
    @endif
</body>
</html>
