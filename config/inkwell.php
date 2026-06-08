<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Spam-scoring pipeline
    |--------------------------------------------------------------------------
    |
    | Order matters — signals are evaluated in this order and a hard-block
    | (points >= 100) short-circuits the pipeline.
    |
    | Adding signal #N: implement App\Services\Spam\SpamSignal, append to
    | this array, write a corpus row in tests/corpus/spam-corpus.json,
    | ship. No other code changes required.
    |
    */

    'spam_signals' => [
        App\Services\Spam\Signals\HoneypotSignal::class,
        App\Services\Spam\Signals\IpReputationSignal::class,
        App\Services\Spam\Signals\TimingSignal::class,
        App\Services\Spam\Signals\SubmissionRateSignal::class,
        App\Services\Spam\Signals\ContentSignal::class,
        App\Services\Spam\Signals\EmailValiditySignal::class,
        App\Services\Spam\Signals\CaptchaSignal::class,
    ],

];
