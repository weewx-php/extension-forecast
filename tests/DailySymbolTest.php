<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Forecast;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\{Config, Section};
use WeewxPhp\Extensions\Forecast\{Data, OpenMeteo, Options, Reader};
use WeewxPhp\Tests\Support\TempDir;

require_once dirname(__DIR__) . '/extension.php';

final class DailySymbolTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('forecast-symbol');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    /** @param list<float|null> $codes */
    private function reader(array $codes, ?float $fallback = 45.0, string $date = '2026-09-06', string $zone = 'Europe/Berlin', float $latitude = 48.4596, float $longitude = 11.6539, int $nowHour = 12): Reader
    {
        $path = $this->dir . '/weather.conf';
        file_put_contents($path, "data_dir = {$this->dir}/data\ntimezone = $zone\n[Archives]\n [[station]]\n  latitude = $latitude\n  longitude = $longitude\n");
        $archive = Config::load($path)->archives['station'];
        $start = new DateTimeImmutable($date, new DateTimeZone($zone));
        $hourly = ['time' => range($start->getTimestamp(), $start->getTimestamp() + 71 * 3600, 3600)];
        $daily = ['time' => range($start->getTimestamp(), $start->getTimestamp() + 2 * 86400, 86400)];
        foreach (OpenMeteo::HOURLY as $field) {
            $hourly[$field] = array_fill(0, 72, 0.0);
        }
        foreach ($hourly['time'] as $i => $at) {
            $hour = (int) (new DateTimeImmutable('@' . $at))->setTimezone($archive->timezone)->format('G');
            $hourly['weather_code'][$i] = $codes[$hour];
        }
        foreach (OpenMeteo::DAILY as $field) {
            $daily[$field] = array_fill(0, 3, 0.0);
        }
        $daily['weather_code'] = array_fill(0, 3, $fallback);
        $daily['sunshine_duration'] = array_fill(0, 3, 43200.0);
        $now = $start->setTime($nowHour, 30)->getTimestamp();
        $data = Data::parse(json_encode(['utc_offset_seconds' => $start->getOffset(), 'hourly' => $hourly, 'daily' => $daily], JSON_THROW_ON_ERROR), $archive, $now);
        return new Reader($data, $archive, $now, new Options(new Section('options', 3)));
    }

    public function testLiveSeptemberSixMorningFogDoesNotOverrideSunnyDay(): void
    {
        // ALL-INKL cache fetched 2026-09-06 17:32:09 UTC, local hourly WMO codes.
        $codes = [0.0, 0.0, 0.0, 0.0, 1.0, 1.0, 1.0, 45.0, 45.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0, 3.0, 3.0, 3.0, 1.0, 3.0, 1.0, 0.0];
        foreach ([0, 12, 23] as $hour) {
            $reader = $this->reader($codes, nowHour: $hour);
            self::assertSame(0.0, $reader->day(0)->value('weatherCode')->raw);
            self::assertSame(43200.0, $reader->day(0)->value('sunshineDur')->raw);
            self::assertSame(0.0, $reader->day(0)->value('rain')->raw);
        }
        self::assertSame([45.0, 45.0], array_column(array_slice($this->reader($codes, nowHour: 0)->hourly('weatherCode', 24)->points, 6, 2), 'value'));
    }

    public function testPrevailingConditionsAndBriefDaytimeHazards(): void
    {
        $sunny = array_fill(0, 24, 45.0);
        foreach (range(9, 17) as $hour) {
            $sunny[$hour] = 0.0;
        }
        $cases = [
            'morning and night fog' => [$sunny, 0.0],
            'all-day fog' => [array_fill(0, 24, 45.0), 45.0],
            'all-day overcast' => [array_fill(0, 24, 3.0), 3.0],
            'all-day partly cloudy' => [array_fill(0, 24, 2.0), 2.0],
            'brief daytime shower' => [array_replace($sunny, [13 => 80.0]), 0.0],
            'daytime thunderstorm' => [array_replace($sunny, [13 => 95.0]), 95.0],
            'daytime freezing rain' => [array_replace($sunny, [13 => 66.0]), 66.0],
            'night thunderstorm' => [array_replace($sunny, [1 => 95.0]), 0.0],
            'daytime rain' => [array_replace($sunny, array_fill(9, 9, 61.0)), 61.0],
            'daytime snow' => [array_replace($sunny, array_fill(9, 9, 73.0)), 73.0],
        ];
        foreach ($cases as $label => [$codes, $expected]) {
            self::assertSame($expected, $this->reader($codes)->day(0)->value('weatherCode')->raw, $label);
        }
    }

    public function testClearCodesCombineAndSolarNoonOutweighsDaylightEdges(): void
    {
        $codes = array_fill(0, 24, 45.0);
        foreach (range(11, 16) as $hour) {
            $codes[$hour] = $hour % 2 === 0 ? 0.0 : 1.0;
        }
        self::assertSame(1.0, $this->reader($codes)->day(0)->value('weatherCode')->raw);
    }

    public function testInsufficientOrInvalidHourlyCodesPreserveProviderFallbackIncludingNullAndZero(): void
    {
        foreach ([null, 10.0, 1.5, 999.0, 1.0e100, -1.0e100] as $invalid) {
            $codes = array_fill(0, 24, $invalid);
            $codes[12] = 0.0;
            foreach ([null, 0.0, 45.0] as $fallback) {
                self::assertSame($fallback, $this->reader($codes, $fallback)->day(0)->value('weatherCode')->raw);
            }
        }
        $codes = array_fill(0, 24, 0.0);
        $codes[12] = null;
        self::assertSame(0.0, $this->reader($codes)->day(0)->value('weatherCode')->raw);
    }

    public function testLocalDayBoundariesDstAndPolarConditions(): void
    {
        $codes = array_fill(0, 24, 45.0);
        foreach (range(9, 17) as $hour) {
            $codes[$hour] = 0.0;
        }
        foreach ([
            ['2026-03-29', 'Europe/Berlin', 48.4596, 11.6539],
            ['2026-10-25', 'Europe/Berlin', 48.4596, 11.6539],
            ['2026-09-06', 'America/Los_Angeles', 34.05, -118.24],
            ['2026-09-06', 'Pacific/Kiritimati', 1.87, -157.4],
            ['2026-09-06', 'Asia/Kolkata', 28.61, 77.21],
        ] as [$date, $zone, $latitude, $longitude]) {
            $reader = $this->reader($codes, date: $date, zone: $zone, latitude: $latitude, longitude: $longitude);
            self::assertSame($date, $reader->day(0)->meta['date']);
            self::assertSame(0.0, $reader->day(0)->value('weatherCode')->raw, $date . ' ' . $zone);
        }
        self::assertSame(45.0, $this->reader($codes, date: '2026-12-21', latitude: 90.0)->day(0)->value('weatherCode')->raw);
        self::assertSame(0.0, $this->reader(array_fill(0, 24, 0.0), date: '2026-06-21', latitude: 90.0)->day(0)->value('weatherCode')->raw);
    }
}
