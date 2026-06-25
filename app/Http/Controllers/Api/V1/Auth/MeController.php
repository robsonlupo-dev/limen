<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): UserResource
    {
        $user = $request->user();

        if ($user->role === 'performer') {
            $user->load('performerProfile');
        }

        return new UserResource($user);
    }
}
