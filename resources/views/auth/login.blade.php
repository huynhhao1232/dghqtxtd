<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập — EduEval</title>
    <link rel="icon" type="image/png" href="{{ asset('images/school-logo.png') }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = { theme: { extend: { colors: { primary: '#1d4ed8' } } } };
    </script>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-900 via-blue-900 to-slate-800 flex items-center justify-center p-4">
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
        <div class="absolute -top-24 -right-24 w-96 h-96 bg-blue-500/20 rounded-full blur-3xl"></div>
        <div class="absolute -bottom-24 -left-24 w-96 h-96 bg-indigo-500/20 rounded-full blur-3xl"></div>
    </div>

    <div class="relative w-full max-w-md">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-white shadow-xl ring-4 ring-white/20 mb-4 overflow-hidden">
                <img src="{{ asset('images/school-logo.png') }}"
                     alt="Logo Trung tâm GDNN-GDTX Thành phố Thủ Đức"
                     class="w-full h-full object-contain">
            </div>
            <h1 class="text-2xl sm:text-3xl font-bold text-white tracking-tight">
                TRUNG TÂM GDNN - GDTX TP THỦ ĐỨC
            </h1>
            <p class="text-blue-100/80 mt-2 text-sm">Hệ thống Đánh giá Hiệu quả Công việc</p>
        </div>

        <div class="bg-white rounded-2xl shadow-2xl p-8">
            <h2 class="text-xl font-semibold text-gray-800 mb-6">Đăng nhập</h2>

            @if ($errors->any())
                <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm flex items-start gap-2">
                    <i class="fas fa-exclamation-circle mt-0.5"></i>
                    <div>
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                </div>
            @endif

            @foreach (['error', 'info', 'status'] as $type)
                @if (session($type))
                    <div class="mb-4 p-3 rounded-lg text-sm {{ $type === 'error' ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700' }}">
                        {{ session($type) }}
                    </div>
                @endif
            @endforeach

            <form method="POST" action="{{ route('login.attempt') }}" class="space-y-5" novalidate>
                @csrf
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-1.5">Tên đăng nhập</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                            <i class="fas fa-user"></i>
                        </span>
                        <input id="username" name="username" type="text" value="{{ old('username') }}" required autofocus
                               autocomplete="username"
                               class="block w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-3 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    </div>
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">Mật khẩu</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-400">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input id="password" name="password" type="password" required autocomplete="current-password"
                               class="block w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-3 text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="remember" value="1" class="rounded border-gray-300 text-primary focus:ring-primary">
                    Ghi nhớ đăng nhập
                </label>
                <button type="submit" class="w-full py-3 px-4 bg-primary hover:bg-blue-800 text-white font-semibold rounded-lg shadow-md transition-colors flex items-center justify-center gap-2">
                    <i class="fas fa-sign-in-alt"></i>
                    Đăng nhập
                </button>
            </form>
        </div>

        <p class="text-center text-blue-100/60 text-xs mt-6">© {{ now()->year }} EduEval — GDNN/GDTX</p>
    </div>
</body>
</html>
