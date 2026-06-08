<?php

namespace App\Services\Destinations;

use App\Models\FormDestination;
use App\Models\Submission;

/**
 * One destination kind. Implementations live under Services/Destinations/*.
 *
 * Adding destination #N: implement this interface, register the class in
 * `config/inkwell.php` under `destinations`, add the kind constant on
 * FormDestination, write a smoke test, ship.
 *
 * Implementations MUST NOT throw on transport errors — record the error on
 * the AttemptResult and let DeliverToDestinationJob handle retry / dead-letter.
 */
interface Destination
{
    /** Stable identifier matching FormDestination::KIND_* constants. */
    public function kind(): string;

    /** Validate the destination's config at save time. */
    public function validateConfig(array $config): ConfigValidation;

    /** Attempt one delivery. Always returns an AttemptResult — no throwing. */
    public function deliver(Submission $submission, FormDestination $destination): AttemptResult;

    /** Periodic health probe. Used by the daily DestinationHealthJob. */
    public function healthCheck(FormDestination $destination): HealthResult;
}
