<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use App\Services\Waitlist\FounderPresenter;
use Illuminate\Contracts\View\View;

class FounderPanelController extends Controller
{
    public function __construct(private readonly FounderPresenter $presenter) {}

    /**
     * Public founder panel: /f/{invite_code}. No auth — this is the shareable
     * viral surface. Shows the person their standing (per role), tier progress,
     * invite link and referral list (masked names only, never emails).
     */
    public function show(string $invite_code): View
    {
        $entry = WaitlistEntry::findByInviteCode($invite_code);

        abort_if($entry === null, 404);

        return view('waitlist.founder', $this->presenter->for($entry));
    }
}
