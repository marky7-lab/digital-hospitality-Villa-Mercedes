<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Support\OutboundMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        if (! OutboundMail::isConfigured()) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'We can’t send password reset emails right now. Please try again later or call the resort for help.']);
        }

        try {
            Password::sendResetLink(
                $request->only('email'),
                function ($user, string $token) use ($request): void {
                    if ((bool) config('auth.passwords.users.reveal_link', false)) {
                        $request->session()->flash('password_reset_url', route('password.reset', [
                            'token' => $token,
                            'email' => $user->getEmailForPasswordReset(),
                        ], false));
                    }

                    $user->sendPasswordResetNotification($token);
                },
            );
        } catch (Throwable $e) {
            report($e);

            $request->session()->forget('password_reset_url');
        }

        return back()
            ->withInput($request->only('email'))
            ->with('status', 'If you have an account with this email address, look for a password reset link in your inbox. Check your spam folder if you don’t see it.');
    }
}
