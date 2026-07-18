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
        $actor = $request->user();
        // CASE WHEN (not MySQL's field()) so this runs the same on SQLite
        // (the test DB) and MySQL (production).
        $users = User::orderByRaw("
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

        return view('user-management', [
            'users'           => $users,
            'assignableRoles' => $actor->assignableRoles(),
        ]);
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

        return redirect()->route('user-management')
            ->with('success', $message);
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

        return redirect()->route('user-management')
            ->with('success', $message);
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

        return redirect()->route('user-management')
            ->with('success', $message);
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
