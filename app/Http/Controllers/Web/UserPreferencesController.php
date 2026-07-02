<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserPreferencesController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferred_world' => ['required', 'in:mulheres,homens,casais,trans,gls,swing'],
        ]);

        $user = $request->user();
        // Set explicitly (preferred_world is not mass-assignable).
        $user->preferred_world = $validated['preferred_world'];
        $user->save();

        return redirect()->route('catalog');
    }
}
