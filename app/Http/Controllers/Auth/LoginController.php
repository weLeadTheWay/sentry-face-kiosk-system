<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function __construct(private AuthService $authService) {}

    public function show()
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request)
    {
        $user = $this->authService->authenticate(
            $request->email,
            $request->password
        );

        if (!$user) {
            return back()->withErrors(['email' => 'Invalid credentials.']);
        }

        Auth::login($user, $request->boolean('remember'));
        return redirect()->route('dashboard');
    }

    public function destroy()
    {
        Auth::logout();
        return redirect()->route('login');
    }
}
