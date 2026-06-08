<?php

namespace App\Services\Destinations;

/**
 * Resolves a Destination implementation by kind string. Registered classes
 * live in config/inkwell.php under `destinations`.
 */
final class DestinationRegistry
{
    /** @var array<string, Destination> */
    private array $byKind = [];

    public function __construct(iterable $destinations)
    {
        foreach ($destinations as $destination) {
            $this->byKind[$destination->kind()] = $destination;
        }
    }

    public static function fromConfig(): self
    {
        $classes = config('inkwell.destinations', []);
        $instances = array_map(static fn (string $c) => app($c), $classes);
        return new self($instances);
    }

    public function get(string $kind): ?Destination
    {
        return $this->byKind[$kind] ?? null;
    }
}
