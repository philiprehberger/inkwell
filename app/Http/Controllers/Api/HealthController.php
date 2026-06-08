<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'version' => config('app.version', '0.1.0'),
        ]);
    }
}
