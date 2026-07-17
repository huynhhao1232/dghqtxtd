<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function showLoginForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route(Auth::user()->is_manager ? 'manager_dashboard' : 'staff_dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $credentials['username'])->first();

        if ($user && ! $user->is_active) {
            return back()
                ->withErrors(['username' => 'Tài khoản đã bị khóa'])
                ->onlyInput('username');
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withErrors(['username' => 'Tên đăng nhập hoặc mật khẩu không chính xác.'])
                ->onlyInput('username');
        }

        $request->session()->regenerate();
        $user = Auth::user();

        return redirect()
            ->intended(route($user->is_manager ? 'manager_dashboard' : 'staff_dashboard'))
            ->with('success', 'Xin chào, '.($user->full_name_vn ?: $user->username).'!');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('info', 'Bạn đã đăng xuất thành công.');
    }
}
