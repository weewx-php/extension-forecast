<?php

declare(strict_types=1);

namespace WeewxPhp\Extensions\Forecast;

use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Frontend\{QueryError, Report, Series, Span, Value};
use WeewxPhp\Weewx\Sun;

/** Read-only forecast tags. Neither archive statistics nor analytics include these values. */
final class Reader
{
    public readonly string $status;
    public readonly ?int $fetchedAt;

    public function __construct(
        private readonly ?Data $data,
        private readonly ArchiveConfig $archive,
        private readonly int $now,
        private readonly Options $options,
    ) {
        $this->fetchedAt = $data?->fetchedAt;
        $this->status = !$archive->enabled || !$options->enabled ? 'disabled'
            : ($data === null ? 'unavailable' : ($data->fresh($now, $options->every) ? 'ready' : 'stale'));
    }

    /** Days from today onward; each report carries start/end/date in meta.
     * @return list<Report>
     */
    public function daily(int $days = 7): array
    {
        if ($days < 1 || $days > 16) {
            throw new QueryError('Forecast days must be 1..16');
        }
        $reports = [];
        $today = Span::date($this->now, $this->archive->timezone)->setTime(0, 0)->getTimestamp();
        $until = Span::date($today, $this->archive->timezone)->modify('+' . $days . ' days')->getTimestamp();
        foreach ($this->data->daily ?? [] as $day) {
            if ($day['start'] < $today || $day['start'] >= $until) {
                continue;
            }
            $values = [];
            foreach ($day['values'] as $name => $raw) {
                if ($name === 'weatherCode') {
                    $raw = $this->dayWeatherCode($day['start'], $day['end'], $raw);
                }
                [$unit, $group] = OpenMeteo::UNITS[$name];
                $value = new Value(
                    $raw,
                    $unit,
                    $group,
                    $this->status,
                    $day['start'],
                    $this->fetchedAt,
                    timezone: $this->archive->timezone->getName(),
                    observation: $name,
                );
                $values[$name] = $value;
            }
            $reports[] = new Report(
                new Series([], status: $this->status),
                $values,
                ['start' => $day['start'], 'end' => $day['end'], 'date' => Span::date($day['start'], $this->archive->timezone)->format('Y-m-d'),
                    'source' => 'Open-Meteo', 'fetched_at' => $this->fetchedAt],
                $this->status,
            );
        }
        return $reports;
    }

    /** Select the prevailing daylight condition, with greater weight near solar noon. */
    private function dayWeatherCode(int $start, int $end, ?float $fallback): ?float
    {
        if ($this->archive->latitude === null || $this->archive->longitude === null) {
            return $fallback;
        }
        $weights = [];
        for ($at = $start; $at < $end; $at += 3600) {
            [$altitude] = Sun::position($at, $this->archive->latitude, $this->archive->longitude);
            if ($altitude >= -0.833) {
                $weights[$at] = 1.0 + sin(deg2rad(max(0.0, $altitude)));
            }
        }
        // During polar night, use the prevailing condition over the local calendar day.
        if ($weights === []) {
            $weights = array_fill_keys(range($start, $end - 1, 3600), 1.0);
        }
        $codes = [];
        $covered = 0.0;
        $significant = null;
        foreach ($this->data->hourly ?? [] as $hour) {
            // Weather codes describe the instant at end, not the preceding interval.
            $weight = $weights[$hour['end']] ?? null;
            $raw = $hour['values']['weatherCode'];
            if ($weight === null || $raw === null || $raw < 0.0 || $raw > 99.0 || $raw !== floor($raw)) {
                continue;
            }
            $code = (int) $raw;
            $group = match ($code) {
                0, 1 => 'clear',
                2 => 'partly',
                3 => 'overcast',
                45, 48 => 'fog',
                51, 53, 55, 61, 63, 65, 80, 81, 82 => 'rain',
                56, 57, 66, 67 => 'freezing',
                71, 73, 75, 77, 85, 86 => 'snow',
                95, 96, 99 => 'storm',
                default => null,
            };
            if ($group === null) {
                continue;
            }
            $covered += $weight;
            $codes[$group][$code] = ($codes[$group][$code] ?? 0.0) + $weight;
            // Short daytime thunderstorms and freezing precipitation remain visible.
            if ($group === 'storm' || $group === 'freezing') {
                $significant = max($significant ?? $code, $code);
            }
        }
        // Sparse or missing hourly data must not invent a representative condition.
        if ($covered < array_sum($weights) * 0.75) {
            return $fallback;
        }
        if ($significant !== null) {
            return (float) $significant;
        }
        $winner = $fallback;
        $bestGroup = -1.0;
        $bestCode = -1.0;
        foreach ($codes as $groupCodes) {
            $score = array_sum($groupCodes);
            foreach ($groupCodes as $code => $codeScore) {
                if ($score > $bestGroup || ($score === $bestGroup && ($codeScore > $bestCode
                    || ($codeScore === $bestCode && $code > $winner)))) {
                    $winner = (float) $code;
                    $bestGroup = $score;
                    $bestCode = $codeScore;
                }
            }
        }
        return $winner;
    }

    /** Instantaneous fields have start=end; precipitation and averages cover the preceding hour. */
    public function hourly(string $observation = 'outTemp', int $hours = 48): Series
    {
        if (!isset(OpenMeteo::HOURLY[$observation]) || $hours < 1 || $hours > 384) {
            throw new QueryError('Unknown forecast field or hours outside 1..384');
        }
        [$unit, $group] = OpenMeteo::UNITS[$observation];
        $points = [];
        foreach ($this->data->hourly ?? [] as $hour) {
            if ($hour['end'] < $this->now || $hour['end'] >= $this->now + $hours * 3600) {
                continue;
            }
            $points[] = ['start' => in_array($observation, OpenMeteo::INTERVALS, true) ? $hour['start'] : $hour['end'],
                'end' => $hour['end'], 'value' => $hour['values'][$observation], 'coverage' => null];
        }
        $series = new Series($points, $unit, $group, $this->status, $this->now, $this->fetchedAt, observation: $observation);
        return $series;
    }

    public function summary(): Report
    {
        return new Report(
            new Series([], status: $this->status),
            [],
            ['fetched_at' => $this->fetchedAt, 'days' => count($this->daily(16)),
                'source' => 'Open-Meteo', 'source_url' => 'https://open-meteo.com/',
                'license' => 'CC BY 4.0', 'license_url' => 'https://creativecommons.org/licenses/by/4.0/'],
            $this->status,
        );
    }

    public function day(int $index): Report
    {
        if ($index < 0 || $index > 15) {
            throw new QueryError('Forecast day index must be 0..15');
        }
        return $this->daily(16)[$index] ?? new Report(new Series([], status: $this->status), [], [], $this->status);
    }
}
