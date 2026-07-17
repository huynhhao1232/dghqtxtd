<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('accounts.profile', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        return match ($request->input('action')) {
            'profile' => $this->updateProfile($request),
            'password' => $this->updatePassword($request),
            default => back()->with('error', 'Yêu cầu cập nhật không hợp lệ.'),
        };
    }

    private function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'avatar' => ['nullable', 'image', 'max:2048'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        if ($request->hasFile('avatar')) {
            $validated['avatar'] = $request->file('avatar')->store('avatars', 'public');
        } else {
            unset($validated['avatar']);
        }

        // ConvertEmptyStringsToNull turns blank phone into null; column is NOT NULL.
        $validated['phone'] = $validated['phone'] ?? '';

        $user->update($validated);

        return redirect()->route('profile')->with('success', 'Đã cập nhật thông tin cá nhân.');
    }

    private function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();
        if (! Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors([
                'current_password' => 'Mật khẩu hiện tại không chính xác.',
            ]);
        }

        $user->update(['password' => $validated['password']]);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('profile')->with('success', 'Đã đổi mật khẩu thành công.');
    }
}
