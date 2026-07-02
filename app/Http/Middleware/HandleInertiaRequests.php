<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role,
                    'status' => $request->user()->status,
                    'email_verified_at' => $request->user()->email_verified_at,
                ] : null,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            'ageAccepted' => (bool) $request->cookie('limen_age'),
        ]);
    }
}
