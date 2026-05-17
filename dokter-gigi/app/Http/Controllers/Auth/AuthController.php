<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    /**
     * Redirect to dashboard or login from root path.
     */
    public function homeRedirect()
    {
        return Auth::check() ? redirect()->route('dashboard') : redirect()->route('login');
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $loginValue = trim($validated['email']);
        $loginField = filter_var($loginValue, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $user = User::where($loginField, $loginValue)->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak cocok.',
            ]);
        }

        if ($user->status !== Status::APPROVED) {
            throw ValidationException::withMessages([
                'email' => 'Akun Anda belum disetujui petugas.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'role' => Role::PASIEN,
            'status' => Status::APPROVED,
            'password' => Hash::make($validated['password']),
        ]);

        // Don't auto-login. Redirect back to login with a success flash so UI can show a popup.
        return redirect()->route('login')->with('registered', true);
    }

    public function sendForgotPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Check if email exists in database
        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            return back()->withInput()->withErrors([
                'email' => 'Email tidak terdaftar di sistem.',
            ]);
        }

        // Redirect to password change form with step 2 and email
        return redirect()->route('password.request', [
            'step' => '2',
            'email' => $validated['email'],
        ]);
    }

    public function showResetPassword(Request $request, $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->email,
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Find user by email
        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            return back()->withErrors([
                'email' => 'Email tidak ditemukan.',
            ]);
        }

        // Update password
        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return redirect()->route('login')->with('status', 'Password berhasil diubah. Silakan login dengan password baru Anda.');
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function dashboard()
    {
        return redirect()->route($this->dashboardRoute(Auth::user()));
    }

    protected function dashboardRoute(?User $user): string
    {
        return match ($user?->role) {
            Role::DOKTER => 'dokter.dashboard',
            Role::PETUGAS => 'petugas.dashboard',
            default => 'pasien.dashboard',
        };
    }
}
