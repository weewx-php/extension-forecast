<?php

declare(strict_types=1);

namespace WeewxPhp\Extensions\Forecast;

use RuntimeException;
use Throwable;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Extension\Context;

/** Local cache only. Writers hold the extension worker lock; readers never fetch. */
final class Store
{
    public function __construct(private readonly Context $context, private readonly Options $options) {}

    public function path(ArchiveConfig $archive): string
    {
        return $this->context->directory . '/forecast.json';
    }

    public function read(ArchiveConfig $archive, int $now): ?Data
    {
        if (!$archive->enabled || !$this->options->enabled || $archive->latitude === null || $archive->longitude === null) {
            return null;
        }
        $cached = self::object($this->path($archive));
        if (($cached['fingerprint'] ?? null) !== OpenMeteo::fingerprint($archive, $this->options)
            || !is_int($cached['fetched_at'] ?? null) || $cached['fetched_at'] > $now
            || !is_string($cached['body'] ?? null)) {
            return null;
        }
        try {
            return Data::parse($cached['body'], $archive, $cached['fetched_at']);
        } catch (Throwable) {
            return null;
        }
    }

    public function retryAt(ArchiveConfig $archive, int $now): ?int
    {
        $attempt = self::object($this->path($archive) . '.attempt');
        $at = $attempt['at'] ?? null;
        return ($attempt['fingerprint'] ?? null) === OpenMeteo::fingerprint($archive, $this->options) && is_int($at) && $at <= $now
            ? $at + 600 : null;
    }

    public function attempt(ArchiveConfig $archive, int $now): void
    {
        $this->write($this->path($archive) . '.attempt', ['fingerprint' => OpenMeteo::fingerprint($archive, $this->options), 'at' => $now]);
    }

    public function save(ArchiveConfig $archive, string $body, int $now): void
    {
        Data::parse($body, $archive, $now);
        $this->write($this->path($archive), ['fingerprint' => OpenMeteo::fingerprint($archive, $this->options), 'fetched_at' => $now, 'body' => $body]);
        @unlink($this->path($archive) . '.attempt');
    }

    /** @return array<string, mixed> */
    private static function object(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $body = @file_get_contents($path, false, null, 0, OpenMeteo::MAX_BYTES * 2 + 1);
        if ($body === false || strlen($body) > OpenMeteo::MAX_BYTES * 2) {
            return [];
        }
        try {
            $value = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($value)) {
                return [];
            }
            $result = [];
            foreach ($value as $key => $item) {
                $result[(string) $key] = $item;
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $data */
    private function write(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create forecast cache');
        }
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (@file_put_contents($temp, $body, LOCK_EX) !== strlen($body) || !@rename($temp, $path)) {
                throw new RuntimeException('Cannot write forecast cache');
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
