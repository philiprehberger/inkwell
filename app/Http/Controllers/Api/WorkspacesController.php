<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspacesController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        /** @var Workspace $w */
        $w = $request->attributes->get('workspace');
        return response()->json([
            'id' => $w->id,
            'name' => $w->name,
            'slug' => $w->slug,
            'created_at' => $w->created_at?->toIso8601String(),
        ]);
    }
}
