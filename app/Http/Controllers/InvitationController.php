<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function accept(Request $request, $token)
    {
        $invitation = \App\Models\GroupInvitation::where('token', $token)->first();

        if (!$invitation) {
            return redirect('/')->with('error', 'This invitation link is invalid.');
        }

        if ($invitation->expires_at && $invitation->expires_at->isPast()) {
            return redirect('/')->with('error', 'This invitation link has expired.');
        }

        if (!\Illuminate\Support\Facades\Auth::check()) {
            // Save the intended URL so they return here after logging in or registering a new account
            session()->put('url.intended', request()->url());
            
            // For now, redirect to the Filament panel's login route.
            return redirect()->route('filament.admin.auth.login')
                ->with('status', 'Please log in or register to accept your invitation.');
        }

        /** @var \App\Models\User $user */
        $user = \Illuminate\Support\Facades\Auth::user();

        if (strcasecmp($user->email, $invitation->email) !== 0) {
            return redirect('/')
                ->with('error', 'This invitation was sent to a different email address.');
        }

        $isMember = $user->groups()->where('groups.id', $invitation->group_id)->exists();

        if (!$isMember) {
            $user->groups()->attach($invitation->group_id, ['role' => $invitation->role]);
        }

        // Clean up invitation
        $invitation->delete();

        return redirect('/admin')->with('status', 'You have successfully joined the group.');
    }
}
