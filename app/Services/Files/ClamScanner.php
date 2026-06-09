<?php

namespace App\Services\Files;

use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over xenolope/quahog. Connects to ClamAV's clamd daemon over
 * TCP (configured via CLAMAV_HOST + CLAMAV_PORT env). Returns a clean / infected
 * / error verdict.
 *
 * In environments without ClamAV (dev, CI), `scan()` returns ERROR — the
 * caller treats ERROR as quarantine-and-flag rather than auto-clean.
 *
 * Imports of Socket\Raw / Quahog\Client happen lazily inside scan() so
 * test-doubles can extend this class without triggering autoload of
 * extensions that may not be installed.
 */
class ClamScanner
{
    public const RESULT_CLEAN = 'clean';
    public const RESULT_INFECTED = 'infected';
    public const RESULT_ERROR = 'error';

    public function __construct(
        protected readonly string $host = '127.0.0.1',
        protected readonly int $port = 3310,
        protected readonly int $timeoutSeconds = 5,
    ) {}

    /**
     * @return array{0: string, 1: ?string}  [verdict, reason]
     */
    public function scan(string $bytes): array
    {
        if (! class_exists('\\Socket\\Raw\\Factory') || ! class_exists('\\Xenolope\\Quahog\\Client')) {
            return [self::RESULT_ERROR, 'clamav client library not installed'];
        }
        try {
            $factoryClass = '\\Socket\\Raw\\Factory';
            $clientClass = '\\Xenolope\\Quahog\\Client';
            $socket = (new $factoryClass)->createClient("tcp://{$this->host}:{$this->port}", $this->timeoutSeconds);
            $client = new $clientClass($socket, $this->timeoutSeconds);
            $result = $client->scanStream($bytes);
            if (method_exists($result, 'isOk') && $result->isOk()) {
                return [self::RESULT_CLEAN, null];
            }
            if (method_exists($result, 'isFound') && $result->isFound()) {
                return [self::RESULT_INFECTED, method_exists($result, 'getReason') ? $result->getReason() : 'infected'];
            }
            return [self::RESULT_ERROR, 'unknown clamav verdict'];
        } catch (\Throwable $e) {
            Log::warning('clamav scan failed', ['error' => $e->getMessage()]);
            return [self::RESULT_ERROR, $e->getMessage()];
        }
    }
}
