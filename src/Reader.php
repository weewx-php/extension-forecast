<?php

declare(strict_types=1);

namespace WeewxPhp\Extensions\Forecast;

use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Frontend\{QueryError, Report, Series, Span, Value};

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
