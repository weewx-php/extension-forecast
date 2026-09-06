<?php

declare(strict_types=1);

namespace WeewxPhp\Extensions\Forecast;

use WeewxPhp\Archive\Budget;
use WeewxPhp\Extension\Context;
use WeewxPhp\Frontend\QueryError;
use WeewxPhp\Upload\Http\HttpClient;

final class Forecast
{
    private ?string $readKey = null;
    private ?Reader $reader = null;

    public function read(Context $context): Reader
    {
        $options = new Options($context->options);
        $key = $context->directory . '|' . $context->now . '|' . hash('sha256', serialize([$context->archive, $context->options]));
        if ($this->readKey !== $key || $this->reader === null) {
            $this->reader = new Reader((new Store($context, $options))->read($context->archive, $context->now), $context->archive, $context->now, $options);
            $this->readKey = $key;
        }
        return $this->reader;
    }

    /** @param array<string, mixed> $args
     * @param list<string> $allowed */
    public static function arguments(array $args, array $allowed): void
    {
        if (array_diff(array_keys($args), $allowed) !== []) {
            throw new QueryError('Unknown forecast tag option');
        }
    }

    /** @param array<string, mixed> $args */
    public static function integer(array $args, string $key, int $default): int
    {
        $value = $args[$key] ?? $default;
        if (!is_int($value)) {
            throw new QueryError('Forecast option must be an integer');
        }
        return $value;
    }

    /** One bounded request per archive, called under the core's extension lock.
     * @return array<string, int|string> */
    public function run(Context $context, Budget $budget, HttpClient $http): array
    {
        $options = new Options($context->options);
        if (!$context->archive->enabled || !$options->enabled) {
            return ['status' => 'disabled'];
        }
        if ($context->archive->latitude === null || $context->archive->longitude === null) {
            return ['status' => 'coordinates_missing'];
        }
        $store = new Store($context, $options);
        $cached = $store->read($context->archive, $context->now);
        if ($cached !== null && $cached->fresh($context->now, $options->every)) {
            return ['status' => 'fresh', 'fetched_at' => $cached->fetchedAt];
        }
        $retry = $store->retryAt($context->archive, $context->now);
        if ($retry !== null && $retry > $context->now) {
            return ['status' => 'retry', 'retry_at' => $retry];
        }
        if ($budget->timeLeft() < 2) {
            return ['status' => 'budget'];
        }
        try {
            $store->attempt($context->archive, $context->now);
            $response = $http->send(OpenMeteo::request($context->archive, $options));
            if ($response->status !== 200) {
                return ['status' => 'error', 'http_status' => $response->status, 'retry_at' => $context->now + 600];
            }
            $store->save($context->archive, $response->body, $context->now);
            $this->reader = null;
            return ['status' => 'updated', 'fetched_at' => $context->now];
        } catch (\Throwable) {
            // Transport errors can include the URL and customer API key.
            return ['status' => 'error', 'retry_at' => $context->now + 600];
        }
    }
}
