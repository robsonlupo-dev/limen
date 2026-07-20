<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterPerformerRequest;
use App\Http\Requests\RegisterConsumerRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __construct(private AuthService $authService) {}

    public function consumer(RegisterConsumerRequest $request): JsonResponse
    {
        $user = $this->authService->registerConsumer($request->validated());
        $token = $user->createToken('api')->plainTextToken;

        Audit::log('auth.register', $user);

        return (new UserResource($user))
            ->additional(['token' => $token])
            ->response()
            ->setStatusCode(201);
    }

    public function performer(RegisterPerformerRequest $request): JsonResponse
    {
        $user = $this->authService->registerPerformer($request->validated(), $request);
        $token = $user->createToken('api')->plainTextToken;

        Audit::log('auth.register_performer', $user);

        return (new UserResource($user->load('performerProfile')))
            ->additional(['token' => $token])
            ->response()
            ->setStatusCode(201);
    }
}
