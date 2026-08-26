<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        return view('user-management', [
            'users'           => $this->usersForManagement(),
            'assignableRoles' => $request->user()->assignableRoles(),
        ]);
    }

    /** Which named route store()/update()/toggleActive() below send the
     *  browser back to — see those methods' own doc comment for why this
     *  exists instead of a hardcoded route() or back(). */
    private const RETURN_ROUTES = ['user-management', 'hub.users'];

    /** Same data/permissions as index() above, rendered as a Hub-styled
     *  standalone page instead of layouts.app's internal dashboard chrome —
     *  explicit request (2026-08-12): a User Management entry point living
     *  in the Hub itself, reusing this same controller (one users table,
     *  one set of business rules) rather than a second parallel
     *  implementation that could drift from this one. The old /user-
     *  management page stays as-is for now (not removed) — see
     *  RETURN_ROUTES/redirectToCaller() for how the mutating actions below
     *  know which of the two pages to send the browser back to. */
    public function hubIndex(Request $request)
    {
        return view('hub-user-management', [
            'users'           => $this->usersForManagement(),
            'assignableRoles' => $request->user()->assignableRoles(),
        ]);
    }

    private function usersForManagement(): \Illuminate\Support\Collection
    {
        // CASE WHEN (not MySQL's field()) so this runs the same on SQLite
        // (the test DB) and MySQL (production).
        return User::orderByRaw("
                case role
                    when 'super_admin' then 0
                    when 'admin' then 1
                    when 'normal' then 2
                    when 'guest' then 3
                    else 4
                end
            ")
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        $data  = $this->validateUser($request, $actor);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'role'     => $data['role'],
            'is_active' => true,
            // Random unusable password: this person signs in by doing "Sign in
            // with Google" with this same email; there's no password-based path
            // for accounts created here (see design spec).
            'password' => Hash::make(Str::random(40)),
        ]);

        $message = "Added \"{$data['name']}\".";
        ActivityLogger::log('user.created', $user, $message);

        return $this->redirectToCaller($request)->with('success', $message);
    }

    public function update(Request $request, User $user)
    {
        $actor = $request->user();
        abort_unless($actor->canManage($user), 403);

        $data = $this->validateUser($request, $actor, $user);

        $user->update([
            'name'  => $data['name'],
            'email' => $data['email'],
            'role'  => $data['role'],
        ]);

        $message = "Updated \"{$data['name']}\".";
        ActivityLogger::log('user.updated', $user, $message);

        return $this->redirectToCaller($request)->with('success', $message);
    }

    /**
     * No explicit "last active Super Admin" runtime guard is needed here (or in
     * update() above): canManage() already blocks self-edit, and only a Super
     * Admin can manage another Super Admin's account — so reaching this method
     * with a Super Admin $user requires the acting user to already be a SECOND,
     * distinct, active Super Admin. At least one therefore always remains after
     * this mutation; see UserManagementControllerTest::
     * test_deactivating_one_of_two_super_admins_leaves_one_active for the proof.
     */
    public function toggleActive(Request $request, User $user)
    {
        $actor = $request->user();
        abort_unless($actor->canManage($user), 403);

        $user->is_active = !$user->is_active;
        $user->save();

        $verb   = $user->is_active ? 'Reactivated' : 'Deactivated';
        $action = $user->is_active ? 'user.activated' : 'user.deactivated';

        $message = "{$verb} \"{$user->name}\".";
        ActivityLogger::log($action, $user, $message);

        return $this->redirectToCaller($request)->with('success', $message);
    }

    /**
     * Explicit request, 2026-08-26: a genuinely permanent delete, distinct
     * from toggleActive() above — deactivating already covers "this
     * person shouldn't be able to sign in anymore" reversibly, so this is
     * for actually removing the row, not a second way to do the same
     * thing. User has no SoftDeletes trait, so $user->delete() here really
     * is a hard delete — no restore, no Removed list, matching the
     * confirm-modal wording ("delete permanently").
     *
     * Every foreign key referencing users.id (activity_logs.user_id,
     * lead_activities.user_id, leads.called_by_user_id, tsa_shifts.
     * status_locked_by, tag_conflict_reviews.reviewed_by) is nullOnDelete
     * (checked live in the migrations) specifically so history survives a
     * deleted account rather than erroring — same reasoning ActivityLogger
     * itself already documents for its own user_id column.
     *
     * Same "last active Super Admin" safety proof as toggleActive() above:
     * canManage() blocks self-target, and only a Super Admin can manage
     * another Super Admin, so reaching here with a Super Admin $user
     * requires the actor to already be a second, distinct, active one.
     */
    public function destroy(Request $request, User $user)
    {
        $actor = $request->user();
        abort_unless($actor->canManage($user), 403);

        $name = $user->name;
        $user->delete();

        $message = "Deleted \"{$name}\" permanently.";
        ActivityLogger::log('user.deleted', null, $message);

        return $this->redirectToCaller($request)->with('success', $message);
    }

    /** Which page (old /user-management or the Hub's own) a mutating action
     *  sends the browser back to — explicit request (2026-08-12): a User
     *  Management entry point in the Hub, reusing this same controller
     *  rather than a second parallel implementation, so both pages' forms
     *  post to the SAME store()/update()/toggleActive() and need a way to
     *  land back on whichever one they came from. A hidden `_redirect_route`
     *  field (set per-form in each view) rather than back()/the Referer
     *  header — the Referer isn't reliably present (privacy settings/
     *  referrer-policy can strip it, and it's simply absent in tests unless
     *  manually faked), where an explicit field always is. Allowlisted
     *  against RETURN_ROUTES rather than passed straight to route() — a
     *  request could otherwise name any other named route in the app and
     *  get redirected there instead. */
    private function redirectToCaller(Request $request): \Illuminate\Http\RedirectResponse
    {
        $target = $request->input('_redirect_route');
        $target = in_array($target, self::RETURN_ROUTES, true) ? $target : 'user-management';

        return redirect()->route($target);
    }

    private function validateUser(Request $request, User $actor, ?User $target = null): array
    {
        $assignable = $actor->assignableRoles();

        // A role value outside what this actor is allowed to assign is a
        // permission boundary, not a generic data-validity issue — so it's a
        // 403, same as canManage() failing, not a validation error.
        abort_unless(in_array($request->input('role'), $assignable, true), 403);

        return $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email' . ($target ? ",{$target->id}" : ''),
            'role'  => 'required|string|in:' . implode(',', $assignable),
        ]);
    }
}
