<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('healthz', HealthController::class)->name('v1.healthz');

    // Forms / submissions / destinations / api-keys / audit / data-subjects
    // routes land in Phase 2+.
});
