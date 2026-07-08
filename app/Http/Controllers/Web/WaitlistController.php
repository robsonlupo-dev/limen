<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\WaitlistWebRequest;
use App\Models\WaitlistEntry;
use Illuminate\Http\RedirectResponse;

class WaitlistController extends Controller
{
    /**
     * Pre-launch interest capture from the public landing page. Idempotent per
     * (email, role): re-submitting the same interest updates the name instead of
     * creating a duplicate, so a curious visitor never sees an error.
     */
    public function store(WaitlistWebRequest $request): RedirectResponse
    {
        $success = 'Tudo certo! Você está na lista. Avisaremos assim que o Limen abrir.';

        // Honeypot: a hidden field no human should ever fill. When a bot fills it,
        // we swallow the submission (return the same success) without persisting.
        if (filled($request->input('website'))) {
            return back()->with('success', $success);
        }

        $data = $request->validated();

        WaitlistEntry::updateOrCreate(
            ['email' => $data['email'], 'role' => $data['role']],
            [
                'name' => $data['name'],
                'world' => $data['world'] ?? null,
                'age_confirmed' => true,
                'source' => 'landing',
            ],
        );

        return back()->with('success', $success);
    }
}
