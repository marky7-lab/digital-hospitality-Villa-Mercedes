<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Admin\StaffSessionService;
use App\Support\AuthRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AdminAuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly StaffSessionService $staffSessions,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasRole(Role::Administrator->value)) {
            return redirect()->route('admin.dashboard');
        }

        if ($user) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return view('auth.admin-login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate(Role::Administrator);

        $request->session()->regenerate();

        $staff = $request->user();

        if ($staff instanceof User) {
            $this->staffSessions->started($staff);
        }

        return redirect()->to(AuthRedirect::adminUrl());
    }

    /**
     * End the back office session.
     *
     * Staff sign out to the back office door, not to the public home page: the
     * desk is where they were working, and the sign-in form is what they need
     * next. Guests signing out of the public site keep using `logout`.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->staffSessions->end($request, $request->user());

        return redirect()->route('admin.login')
            ->with('status', 'You’re signed out of the staff area.');
    }
}
