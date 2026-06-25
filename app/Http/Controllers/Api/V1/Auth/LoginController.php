<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __construct(private AuthService $authService) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = $this->authService->attemptLogin(
            $request->validated('email'),
            $request->validated('password'),
        );

        if (! $user) {
            Audit::log('auth.login_failed', metadata: ['email' => $request->validated('email')]);

            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('api')->plainTextToken;

        Audit::log('auth.login', $user);

        return (new UserResource($user))
            ->additional(['token' => $token])
            ->response();
    }
}
