<?php

namespace App\Services\Spam;

/**
 * One signal in the spam-scoring pipeline.
 *
 * Adding signal #N: implement this interface, register the class in
 * `config/inkwell.php` under `spam_signals`, write a corpus row, ship.
 *
 * Signals MUST be deterministic given the same context (so corpus tests
 * are stable). Signals MUST NOT throw on bad input — return null instead.
 */
interface SpamSignal
{
    /**
     * Evaluate this signal. Return null if the signal doesn't apply
     * (e.g. EmailValiditySignal on a form with no email field).
     */
    public function evaluate(SubmissionContext $context): ?SignalResult;
}
