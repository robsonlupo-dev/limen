<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ConviteController extends Controller
{
    /**
     * Invite link: /convite/{invite_code}. Renders the landing with a "convidado
     * por X" banner and stashes the referrer id in the session so the eventual
     * signup is attributed to them. Attribution lives in the session (not a form
     * field) so it cannot be tampered with client-side.
     */
    public function show(Request $request, string $invite_code): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('catalog');
        }

        $referrer = WaitlistEntry::findByInviteCode($invite_code);

        // Unknown code → behave like a normal landing visit, no attribution.
        if ($referrer === null) {
            $request->session()->forget('waitlist_referrer_id');

            return Inertia::render('Landing');
        }

        $request->session()->put('waitlist_referrer_id', $referrer->id);

        return Inertia::render('Landing', [
            'referral' => ['name' => Str::of($referrer->name)->trim()->explode(' ')->first()],
        ]);
    }
}
