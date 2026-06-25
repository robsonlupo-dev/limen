<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TokenPackageResource;
use App\Models\TokenPackage;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TokenPackageController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $packages = TokenPackage::where('active', true)
            ->orderBy('sort_order')
            ->get();

        return TokenPackageResource::collection($packages);
    }
}
