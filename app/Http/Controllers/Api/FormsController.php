<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FormsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $cursor = $request->query('cursor');

        $query = $workspace->forms()->whereNull('archived_at')->orderBy('id');
        if ($cursor) {
            $query->where('id', '>', $cursor);
        }
        $forms = $query->limit($limit + 1)->get();
        $hasMore = $forms->count() > $limit;
        $page = $forms->take($limit);

        return response()->json([
            'data' => $page->map(fn ($f) => $this->serialize($f))->all(),
            'next_cursor' => $hasMore ? $page->last()->id : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);

        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'slug' => ['required', 'string', 'min:1', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'schema' => ['required', 'array'],
            'spam_threshold' => ['nullable', 'integer', 'min:0', 'max:100'],
            'success_redirect_url' => ['nullable', 'url'],
            'cors_origins' => ['nullable', 'array'],
            'cors_origins.*' => ['string'],
            'accept_any_origin' => ['nullable', 'boolean'],
            'allowed_mime_types' => ['nullable', 'array'],
        ])->validate();

        if ($workspace->forms()->where('slug', $data['slug'])->exists()) {
            return $this->problem(409, "A form with slug '{$data['slug']}' already exists.");
        }

        // CORS allowlist: explicit on creation. Either cors_origins is non-empty
        // OR accept_any_origin is true.
        $origins = $data['cors_origins'] ?? [];
        $any = (bool) ($data['accept_any_origin'] ?? false);
        if ($origins === [] && ! $any) {
            return $this->problem(
                400,
                'Form requires either a non-empty cors_origins list or accept_any_origin=true. '
                .'See /docs/concepts/security for why this is required at creation.',
            );
        }

        $form = $workspace->forms()->create([
            'slug' => $data['slug'],
            'name' => $data['name'],
            'schema' => $data['schema'],
            'spam_threshold' => $data['spam_threshold'] ?? 50,
            'success_redirect_url' => $data['success_redirect_url'] ?? null,
            'cors_origins' => $origins,
            'accept_any_origin' => $any,
            'accept_any_origin_set_at' => $any ? now() : null,
            'allowed_mime_types' => $data['allowed_mime_types'] ?? null,
        ]);

        AuditLogger::record($workspace, 'form', $form->id, 'created', [
            'after' => $form->only(['name', 'slug', 'spam_threshold', 'accept_any_origin']),
        ], request: $request);

        return response()->json($this->serialize($form), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->serialize($this->find($request, $id)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $form = $this->find($request, $id);

        $data = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'schema' => ['sometimes', 'array'],
            'spam_threshold' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'success_redirect_url' => ['sometimes', 'nullable', 'url'],
            'cors_origins' => ['sometimes', 'array'],
            'accept_any_origin' => ['sometimes', 'boolean'],
            'allowed_mime_types' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $before = $form->only(['name', 'spam_threshold', 'accept_any_origin']);

        if (array_key_exists('accept_any_origin', $data)) {
            $form->accept_any_origin = (bool) $data['accept_any_origin'];
            $form->accept_any_origin_set_at = $data['accept_any_origin'] ? now() : null;
        }
        foreach (['name', 'schema', 'spam_threshold', 'success_redirect_url', 'cors_origins', 'allowed_mime_types'] as $f) {
            if (array_key_exists($f, $data)) {
                $form->$f = $data[$f];
            }
        }
        $form->save();

        AuditLogger::record($this->workspace($request), 'form', $form->id, 'updated', [
            'before' => $before,
            'after' => $form->only(['name', 'spam_threshold', 'accept_any_origin']),
        ], request: $request);

        return response()->json($this->serialize($form));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $form = $this->find($request, $id);
        $form->archived_at = now();
        $form->save();

        AuditLogger::record($this->workspace($request), 'form', $form->id, 'archived', [], request: $request);

        return response()->json(status: 204);
    }

    private function workspace(Request $request): Workspace
    {
        /** @var Workspace $w */
        $w = $request->attributes->get('workspace');
        return $w;
    }

    private function find(Request $request, string $id): Form
    {
        return $this->workspace($request)->forms()->whereNull('archived_at')->findOrFail($id);
    }

    private function serialize(Form $form): array
    {
        return [
            'id' => $form->id,
            'name' => $form->name,
            'slug' => $form->slug,
            'schema' => $form->schema,
            'spam_threshold' => $form->spam_threshold,
            'success_redirect_url' => $form->success_redirect_url,
            'cors_origins' => $form->cors_origins ?: [],
            'accept_any_origin' => $form->accept_any_origin,
            'archived_at' => $form->archived_at?->toIso8601String(),
            'created_at' => $form->created_at?->toIso8601String(),
        ];
    }

    private function problem(int $status, string $detail): JsonResponse
    {
        return response()->json([
            'type' => 'about:blank',
            'title' => $status === 409 ? 'Conflict' : 'Invalid request',
            'status' => $status,
            'detail' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json']);
    }
}
