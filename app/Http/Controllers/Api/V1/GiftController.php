<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Consumer\GiftController as WebGiftController;
use Illuminate\Http\JsonResponse;

class GiftController extends Controller
{
    /** Catálogo público de presentes (só ativos). Mesmo shape da porta web. */
    public function index(): JsonResponse
    {
        return response()->json(['gifts' => WebGiftController::activeGifts()]);
    }
}
