<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /** The page remembers this browser's access request (2026-11-23) so its
     *  status (waiting / accepted / declined) survives a reload, instead of
     *  a one-time flash message. ?other=1 forgets it (another account). */
    public function showLogin(Request $request)
    {
        if ($request->boolean('other')) {
            $request->session()->forget('access_request_email');

            return redirect()->route('login');
        }
        $email = $request->session()->get('access_request_email');

        return view('auth.login', [
            'accessRequest' => $email ? \App\Models\AccessRequest::where('email', $email)->first() : null,
        ]);
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
            // Logged (2026-08-24, real production incident: every attempt
            // failed with this generic message and NOTHING in the logs to
            // diagnose it by, since this catch previously discarded $e
            // entirely) — the real exception (redirect_uri_mismatch, an
            // expired/revoked client secret, etc.) is what actually
            // explains a failure here, this message alone never does.
            \Illuminate\Support\Facades\Log::error('Google sign-in failed: ' . $e->getMessage(), ['exception' => $e]);

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
        // Access requests (2026-11-23): instead of a dead end, the attempt
        // becomes a request an admin Accepts or Rejects in User Management.
        // Nobody gets in until accepted.
        if (!$user) {
            $req = \App\Models\AccessRequest::recordAttempt(
                (string) $googleUser->getEmail(), $googleUser->getName(), $googleUser->getId(), $googleUser->getAvatar()
            );
            if ($req->wasRecentlyCreated) {
                \App\Support\ActivityLogger::log('access.requested', $req, "Access requested by {$req->name} ({$req->email})");
            }
            // remembered (not flashed): the login page keeps showing its status
            $request->session()->put('access_request_email', $req->email);

            return redirect()->route('login');
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
        $request->session()->forget('access_request_email');

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
