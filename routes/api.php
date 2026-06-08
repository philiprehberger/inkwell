<?php

use App\Http\Controllers\Api\ApiKeysController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\DataSubjectController;
use App\Http\Controllers\Api\DestinationsController;
use App\Http\Controllers\Api\FormsController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HostedThankYouController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\SubmissionsController;
use App\Http\Controllers\Api\WorkspacesController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('healthz', HealthController::class)->name('v1.healthz');

    // Submission endpoint — UNAUTHENTICATED, the canonical public ingest path.
    Route::post('forms/{formId}/submit', IngestController::class)->name('v1.submit');

    // Hosted thank-you page (fallback for visitors).
    Route::get('hosted/thank-you/{id}', HostedThankYouController::class)->name('v1.hosted-thank-you');

    // Management API — authenticated.
    Route::middleware(['api.key', 'workspace.rate-limit'])->group(function () {
        Route::get('workspaces/current', [WorkspacesController::class, 'current'])->name('v1.workspaces.current');

        // Forms CRUD
        Route::middleware(['idempotency'])->group(function () {
            Route::post('forms', [FormsController::class, 'store'])->name('v1.forms.store');
            Route::patch('forms/{id}', [FormsController::class, 'update'])->name('v1.forms.update');
        });
        Route::get('forms', [FormsController::class, 'index'])->name('v1.forms.index');
        Route::get('forms/{id}', [FormsController::class, 'show'])->name('v1.forms.show');
        Route::delete('forms/{id}', [FormsController::class, 'destroy'])->name('v1.forms.destroy');

        // Submissions
        Route::get('forms/{formId}/submissions', [SubmissionsController::class, 'index'])->name('v1.submissions.index');
        Route::get('submissions/{id}', [SubmissionsController::class, 'show'])->name('v1.submissions.show');
        Route::middleware(['idempotency'])->group(function () {
            Route::post('submissions/{id}/promote', [SubmissionsController::class, 'promote'])->name('v1.submissions.promote');
            Route::post('submissions/{id}/replay-deliveries', [SubmissionsController::class, 'replay'])->name('v1.submissions.replay');
        });

        // Destinations
        Route::get('forms/{formId}/destinations', [DestinationsController::class, 'index'])->name('v1.destinations.index');
        Route::middleware(['idempotency'])->group(function () {
            Route::post('forms/{formId}/destinations', [DestinationsController::class, 'store'])->name('v1.destinations.store');
            Route::patch('destinations/{id}', [DestinationsController::class, 'update'])->name('v1.destinations.update');
        });
        Route::delete('destinations/{id}', [DestinationsController::class, 'destroy'])->name('v1.destinations.destroy');
        Route::post('destinations/{id}/test', [DestinationsController::class, 'test'])->name('v1.destinations.test');
        Route::post('destinations/{id}/rotate-secret', [DestinationsController::class, 'rotateSecret'])->name('v1.destinations.rotate');

        // API keys — admin scope only.
        Route::middleware(['api.key:admin'])->group(function () {
            Route::get('api-keys', [ApiKeysController::class, 'index'])->name('v1.api-keys.index');
            Route::middleware(['idempotency'])->post('api-keys', [ApiKeysController::class, 'store'])->name('v1.api-keys.store');
            Route::delete('api-keys/{id}', [ApiKeysController::class, 'destroy'])->name('v1.api-keys.destroy');
        });

        // Audit
        Route::get('audit', AuditController::class)->name('v1.audit.index');

        // Compliance — data-subject access + erasure. Admin-scope only.
        Route::middleware(['api.key:admin'])->group(function () {
            Route::post('data-subjects/lookup', [DataSubjectController::class, 'lookup'])->name('v1.data-subjects.lookup');
            Route::middleware(['idempotency'])->delete('data-subjects/by-email', [DataSubjectController::class, 'delete'])->name('v1.data-subjects.delete');
            Route::get('data-subjects/requests/{id}', [DataSubjectController::class, 'status'])->name('v1.data-subjects.status');
        });
    });
});
