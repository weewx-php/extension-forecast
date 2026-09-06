<?php

declare(strict_types=1);

namespace WeewxPhp\Extensions\Forecast;

use DateTimeImmutable;
use RuntimeException;
use WeewxPhp\Config\ArchiveConfig;

/** Validated numeric rows, with actual local calendar boundaries for daily values. */
final class Data
{
    /** @param list<array{start: int, end: int, values: array<string, float|null>}> $hourly
     * @param list<array{start: int, end: int, values: array<string, float|null>}> $daily
     */
    private function __construct(
        public readonly array $hourly,
        public readonly array $daily,
        public readonly int $fetchedAt,
    ) {}

    public function fresh(int $now, int $every): bool
    {
        return $now >= $this->fetchedAt && $now - $this->fetchedAt < $every && $this->daily[0]['end'] > $now;
    }

    public static function parse(string $body, ArchiveConfig $archive, int $fetchedAt): self
    {
        if (strlen($body) > OpenMeteo::MAX_BYTES) {
            throw new RuntimeException('Forecast response too large');
        }
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['error'] ?? false) !== false || !is_int($data['utc_offset_seconds'] ?? null)
            || abs($data['utc_offset_seconds']) > 86400) {
            throw new RuntimeException('Invalid forecast response');
        }
        $hourly = self::rows($data['hourly'] ?? null, OpenMeteo::HOURLY, 400, 3600);
        $daily = self::rows($data['daily'] ?? null, OpenMeteo::DAILY, 16, 86400);
        // Open-Meteo daily stamps use the response offset, including across DST.
        // Resolve each resulting calendar date in the archive zone independently.
        foreach ($daily as &$day) {
            $date = gmdate('Y-m-d', $day['end'] + $data['utc_offset_seconds']);
            $start = new DateTimeImmutable($date, $archive->timezone);
            $day['start'] = $start->getTimestamp();
            $day['end'] = $start->modify('+1 day')->getTimestamp();
        }
        unset($day);
        if ($hourly[count($hourly) - 1]['end'] + 3600 <= $fetchedAt || $hourly[0]['end'] > $fetchedAt + 3600
            || $daily[0]['start'] > $fetchedAt || $daily[count($daily) - 1]['end'] <= $fetchedAt) {
            throw new RuntimeException('Forecast does not cover the current time');
        }
        return new self($hourly, $daily, $fetchedAt);
    }

    /** @param array<string, string> $fields
     * @return list<array{start: int, end: int, values: array<string, float|null>}>
     */
    private static function rows(mixed $data, array $fields, int $limit, int $step): array
    {
        if (!is_array($data) || !is_array($data['time'] ?? null) || !array_is_list($data['time'])
            || $data['time'] === [] || count($data['time']) > $limit) {
            throw new RuntimeException('Invalid forecast time axis');
        }
        $count = count($data['time']);
        $series = [];
        foreach ($fields as $field) {
            if (!is_array($data[$field] ?? null) || !array_is_list($data[$field]) || count($data[$field]) !== $count) {
                throw new RuntimeException('Invalid forecast series');
            }
            $series[$field] = $data[$field];
        }
        $rows = [];
        $previous = null;
        foreach ($data['time'] as $i => $stamp) {
            if (!is_int($stamp) || $stamp < 0 || $stamp > 4102444800 || ($previous !== null && $stamp - $previous !== $step)) {
                throw new RuntimeException('Invalid forecast timestamp');
            }
            $previous = $stamp;
            $values = [];
            foreach ($fields as $name => $field) {
                $value = $series[$field][$i];
                if ($value !== null && ((!is_float($value) && !is_int($value)) || !is_finite((float) $value))) {
                    throw new RuntimeException('Invalid forecast value');
                }
                $values[$name] = $value === null ? null : (float) $value;
            }
            $rows[] = ['start' => $stamp - $step, 'end' => $stamp, 'values' => $values];
        }
        return $rows;
    }
}
