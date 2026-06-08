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

    /*
    |--------------------------------------------------------------------------
    | Destination registry
    |--------------------------------------------------------------------------
    |
    | Each class implements App\Services\Destinations\Destination. Adding
    | destination #N: implement the interface, register here, add the kind
    | constant on FormDestination, write a smoke test.
    |
    */

    'destinations' => [
        App\Services\Destinations\EmailDestination::class,
        App\Services\Destinations\WebhookDestination::class,
        App\Services\Destinations\SlackDestination::class,
        App\Services\Destinations\DiscordDestination::class,
        App\Services\Destinations\GoogleSheetsDestination::class,
        App\Services\Destinations\HubSpotDestination::class,
        App\Services\Destinations\MailchimpDestination::class,
    ],

];
