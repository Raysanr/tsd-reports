<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            // Attached to the email field (not a top-level banner) — same
            // field-level error convention as every other form in this app
            // (TSA Management, Product Management, Settings).
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (!Auth::user()->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated.',
            ]);
        }

        $request->session()->regenerate();

        // Successful sign-ins only — a failed attempt isn't an authenticated
        // user's action to attribute, and logging it here would need a
        // separate, unauthenticated-actor code path in ActivityLogger.
        ActivityLogger::log('auth.login', Auth::user(), "Logged in as \"{$credentials['email']}\".");

        // Deliberately NOT redirect()->intended() — the whole point of the
        // Hub redirect is "always land here after logging in", but
        // intended() prefers whatever protected URL a guest tried to visit
        // BEFORE reaching login (stored in session by redirectGuestsTo) over
        // this fallback. That stored URL persists across logout/login too,
        // since nothing here ever clears it — so intended() would silently
        // skip the Hub for good the first time anyone visits the dashboard
        // directly while signed out. A plain route('hub') redirect has no
        // such override.
        return redirect()->route('hub');
    }

    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            // Covers the user cancelling on Google's consent screen, an
            // expired/replayed callback URL, and bad client credentials.
            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in failed. Please try again.',
            ]);
        }

        // Match by google_id first (returning Google user), then by email so
        // an existing password account gets linked instead of duplicated —
        // users.email is unique, so creating blindly would throw.
        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        // No auto-create: accounts only ever come from User Management now. A
        // stranger's Google account matching no existing row is not "sign up",
        // it's a dead end — the whole point of closing public registration.
        if (!$user) {
            return redirect()->route('login')->withErrors([
                'email' => 'No account found for that Google email — ask an admin to add you first.',
            ]);
        }

        if (!$user->is_active) {
            return redirect()->route('login')->withErrors([
                'email' => 'This account has been deactivated.',
            ]);
        }

        $user->update([
            'google_id' => $googleUser->getId(),
            'avatar'    => $googleUser->getAvatar(),
        ]);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        ActivityLogger::log('auth.login', $user, "Logged in as \"{$user->email}\" (Google).");

        // Deliberately NOT redirect()->intended() — the whole point of the
        // Hub redirect is "always land here after logging in", but
        // intended() prefers whatever protected URL a guest tried to visit
        // BEFORE reaching login (stored in session by redirectGuestsTo) over
        // this fallback. That stored URL persists across logout/login too,
        // since nothing here ever clears it — so intended() would silently
        // skip the Hub for good the first time anyone visits the dashboard
        // directly while signed out. A plain route('hub') redirect has no
        // such override.
        return redirect()->route('hub');
    }

    public function logout(Request $request)
    {
        // Logged BEFORE Auth::logout() — ActivityLogger reads auth()->id()
        // internally, which would be null once the session is cleared.
        if ($user = Auth::user()) {
            ActivityLogger::log('auth.logout', $user, "Logged out \"{$user->email}\".");
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
