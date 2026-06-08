<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormDestination;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DestinationsController extends Controller
{
    public function index(Request $request, string $formId): JsonResponse
    {
        $form = $this->workspace($request)->forms()->findOrFail($formId);
        return response()->json([
            'data' => $form->destinations->map(fn ($d) => $this->serialize($d))->all(),
        ]);
    }

    public function store(Request $request, string $formId): JsonResponse
    {
        $form = $this->workspace($request)->forms()->findOrFail($formId);

        $data = Validator::make($request->all(), [
            'kind' => ['required', 'string', 'in:'.implode(',', FormDestination::KINDS)],
            'config' => ['required', 'array'],
            'enabled' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        $destination = $form->destinations()->create([
            'kind' => $data['kind'],
            'config' => $data['config'],
            'enabled' => $data['enabled'] ?? true,
            'priority' => $data['priority'] ?? 0,
        ]);

        AuditLogger::record($this->workspace($request), 'destination', $destination->id, 'created', [
            'form_id' => $form->id,
            'kind' => $destination->kind,
        ], request: $request);

        return response()->json($this->serialize($destination), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $destination = $this->findDestination($request, $id);

        $data = Validator::make($request->all(), [
            'config' => ['sometimes', 'array'],
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0'],
        ])->validate();

        $destination->fill($data)->save();

        AuditLogger::record($this->workspace($request), 'destination', $destination->id, 'updated', $data, request: $request);

        return response()->json($this->serialize($destination));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $destination = $this->findDestination($request, $id);
        $destination->delete();
        AuditLogger::record($this->workspace($request), 'destination', $id, 'deleted', request: $request);
        return response()->json(status: 204);
    }

    public function test(Request $request, string $id): JsonResponse
    {
        $destination = $this->findDestination($request, $id);
        // Phase 4 owns the actual delivery test. Phase 2 returns a stub.
        return response()->json([
            'attempt_number' => 0,
            'request_summary' => "test fire stub for destination kind={$destination->kind}",
            'response_status' => null,
            'response_body_snippet' => 'Real delivery lands in Phase 4',
            'latency_ms' => 0,
            'error_code' => null,
            'created_at' => now()->toIso8601String(),
        ]);
    }

    public function rotateSecret(Request $request, string $id): JsonResponse
    {
        $destination = $this->findDestination($request, $id);
        if ($destination->kind !== FormDestination::KIND_WEBHOOK) {
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Invalid destination kind',
                'status' => 400,
                'detail' => 'Secret rotation only applies to webhook destinations.',
            ], 400, ['Content-Type' => 'application/problem+json']);
        }

        $config = $destination->config ?: [];
        $oldSecret = $config['secret'] ?? null;
        $newSecret = 'whsec_'.Str::random(48);
        $config['secret'] = $newSecret;

        $graceExpires = now()->addHours(48);
        $destination->fill([
            'config' => $config,
            'previous_secret' => $oldSecret,
            'previous_secret_expires_at' => $graceExpires,
        ])->save();

        AuditLogger::record($this->workspace($request), 'destination', $destination->id, 'secret_rotated', [
            'grace_expires_at' => $graceExpires->toIso8601String(),
        ], request: $request);

        return response()->json([
            'secret' => $newSecret,
            'grace_expires_at' => $graceExpires->toIso8601String(),
        ]);
    }

    private function workspace(Request $request): Workspace
    {
        return $request->attributes->get('workspace');
    }

    private function findDestination(Request $request, string $id): FormDestination
    {
        // Destinations are scoped via the parent form's workspace.
        $workspaceId = $this->workspace($request)->id;
        return FormDestination::query()
            ->whereHas('form', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->findOrFail($id);
    }

    private function serialize(FormDestination $d): array
    {
        $config = $d->config ?: [];
        // Never expose secret in API responses.
        unset($config['secret']);

        return [
            'id' => $d->id,
            'form_id' => $d->form_id,
            'kind' => $d->kind,
            'config' => $config,
            'enabled' => $d->enabled,
            'priority' => $d->priority,
            'health' => $d->health,
            'last_attempted_at' => $d->last_attempted_at?->toIso8601String(),
        ];
    }
}
