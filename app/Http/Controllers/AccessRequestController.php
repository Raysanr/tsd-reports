<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Accept / Reject a sign-in request from someone not in User Management
 *  (2026-11-23). Accept = create their account with the chosen role. */
class AccessRequestController extends Controller
{
    public function accept(Request $request, AccessRequest $accessRequest)
    {
        $actor = $request->user();
        abort_unless(in_array($request->input('role'), $actor->assignableRoles(), true), 403);
        $data = $request->validate([
            'role'      => 'required|string',
            'modules'   => 'exclude_unless:role,guest|required|array|min:1',
            'modules.*' => 'exclude_unless:role,guest|string|in:' . implode(',', array_keys(User::MODULES)),
        ]);
        if (User::where('email', $accessRequest->email)->exists()) {
            $accessRequest->update(['status' => AccessRequest::APPROVED, 'decided_by' => $actor->id, 'decided_at' => now()]);

            return back()->with('success', "{$accessRequest->email} already has an account.");
        }

        $user = DB::transaction(function () use ($accessRequest, $data, $actor) {
            $user = User::create([
                'name'            => $accessRequest->name,
                'email'           => $accessRequest->email,
                'google_id'       => $accessRequest->google_id,
                'avatar'          => $accessRequest->avatar,
                'role'            => $data['role'],
                'allowed_modules' => $data['modules'] ?? null,
                'is_active'       => true,
                'password'        => Hash::make(Str::random(40)), // Google sign-in only
            ]);
            $accessRequest->update(['status' => AccessRequest::APPROVED, 'decided_by' => $actor->id, 'decided_at' => now()]);

            return $user;
        });
        ActivityLogger::log('access.accepted', $user, "Accepted access request from {$user->name} ({$user->email}) as " . (User::ROLE_LABELS[$user->role] ?? $user->role));

        return back()->with('success', "Accepted {$user->name}. They can sign in with Google now.");
    }

    public function reject(Request $request, AccessRequest $accessRequest)
    {
        $accessRequest->update(['status' => AccessRequest::REJECTED, 'decided_by' => $request->user()->id, 'decided_at' => now()]);
        ActivityLogger::log('access.rejected', $accessRequest, "Rejected access request from {$accessRequest->name} ({$accessRequest->email})");

        return back()->with('success', "Rejected {$accessRequest->email}. They can't sign in.");
    }
}
