<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Forecast;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\Config;
use WeewxPhp\Config\Section;
use WeewxPhp\DemoTheme\Forecast;
use WeewxPhp\Extension\Context;
use WeewxPhp\Extensions\Forecast\Data;
use WeewxPhp\Extensions\Forecast\OpenMeteo;
use WeewxPhp\Extensions\Forecast\Options;
use WeewxPhp\Extensions\Forecast\Store;
use WeewxPhp\Frontend\Output;
use WeewxPhp\Frontend\Weather;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\FakeHttpClient;
use WeewxPhp\Tests\Support\TempDir;
use WeewxPhp\Tick\Lock;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Tick\Tick;
use WeewxPhp\Time\FixedClock;

$demoTheme = getenv('WEEWX_DEMO_THEME_ROOT');
require_once ($demoTheme === false ? (string) getenv('WEEWX_PHP_ROOT') . '/themes/demo' : $demoTheme) . '/Forecast.php';
require_once dirname(__DIR__) . '/extension.php';

final class ForecastTest extends TestCase
{
    private string $dir;
    private string $path;
    private Config $config;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->dir = TempDir::create('forecast');
        $this->path = $this->dir . '/weather.conf';
        $entry = str_replace('\\', '/', dirname(__DIR__) . '/extension.php');
        file_put_contents($this->path, "data_dir = {$this->dir}/data\ntimezone = Europe/Berlin\nbackup_enabled = false\n[Archives]\n    [[berlin]]\n        latitude = 52.52\n        longitude = 13.41\n[Extensions]\n [[forecast]]\n  enabled = true\n  entry = $entry\n  [[[options]]]\n   enabled = true\n");
        $this->config = Config::load($this->path);
        $this->clock = new FixedClock((new DateTimeImmutable('2026-09-06 12:00', new DateTimeZone('Europe/Berlin')))->getTimestamp());
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);
    }

    /** Provider-shaped data with its fixed response offset, including at DST transitions.
     * @return array<string, mixed>
     */
    private function response(string $date = '2026-09-06', string $zone = 'Europe/Berlin'): array
    {
        $start = new DateTimeImmutable($date, new DateTimeZone($zone));
        $hourly = ['time' => range($start->getTimestamp(), $start->getTimestamp() + 71 * 3600, 3600)];
        $daily = ['time' => range($start->getTimestamp(), $start->getTimestamp() + 2 * 86400, 86400)];
        foreach (OpenMeteo::HOURLY as $name => $key) {
            $hourly[$key] = array_fill(0, 72, $name === 'rain' ? 0.0 : 10.0);
        }
        $hourly['temperature_2m'][13] = null;
        foreach (OpenMeteo::DAILY as $name => $key) {
            $daily[$key] = array_fill(0, 3, match ($name) {
                'rain', 'rainProbability', 'weatherCode' => 0.0,
                'outTempMax' => 20.0,
                default => 10.0,
            });
        }
        return ['utc_offset_seconds' => $start->getOffset(), 'hourly' => $hourly, 'daily' => $daily];
    }

    private function body(): string
    {
        return json_encode($this->response(), JSON_THROW_ON_ERROR);
    }

    public function testTickFetchesOnceAndThemeTagsReadOnlyConvertUnitsAndPreserveNullAndZero(): void
    {
        $http = (new FakeHttpClient())->answer(200, $this->body());
        $runtime = Runtime::of($this->config, $this->clock, new MemoryLogger(), $http);
        try {
            $outcome = (new Tick($runtime))->run('cli');
            self::assertSame('updated', $outcome->extensions['forecast/berlin']['status']);
            self::assertSame('fresh', (new Tick($runtime))->run('cli')->extensions['forecast/berlin']['status']);
            self::assertCount(1, $http->requests);
            parse_str((string) parse_url($http->last()->url, PHP_URL_QUERY), $query);
            self::assertSame('Europe/Berlin', $query['timezone']);
            self::assertSame('ms', $query['wind_speed_unit']);
            self::assertSame('7', $query['forecast_days']);
            self::assertSame(OpenMeteo::MAX_BYTES, $http->last()->maxResponseBytes);
            $before = glob($this->context()->directory . '/*');
            $wx = new Weather($this->config, clock: $this->clock);
            $forecast = new Forecast($wx->output(new Output('de', units: ['group_temperature' => 'degree_F'])));
            self::assertSame('ready', $forecast->status);
            self::assertSame($this->clock->now(), $forecast->fetchedAt);
            self::assertSame(68.0, $forecast->daily()[0]->value('outTempMax')->raw);
            self::assertSame(0.0, $forecast->daily()[0]->value('rain')->raw);
            self::assertSame(36.0, $forecast->daily()[0]->value('windGust')->to('km_per_hour')->raw);
            self::assertSame([50.0, null, 50.0], array_column($forecast->hourly('outTemp', 3)->points, 'value'));
            $rain = $forecast->hourly('rain', 1)->points[0];
            self::assertSame(3600, $rain['end'] - $rain['start']);
            $temp = $forecast->hourly('outTemp', 1)->points[0];
            self::assertSame($temp['end'], $temp['start']);
            self::assertSame('2026-09-06', $forecast->daily()[0]->meta['date']);
            self::assertSame($before, glob($this->context()->directory . '/*'));
            self::assertCount(1, $http->requests);
            self::assertSame(0, $outcome->archives['berlin']['records']);
            $wx->close();
        } finally {
            $runtime->close();
        }
    }

    public function testFailureKeepsLastForecastAndUsesRetryDelayInsteadOfFileMtime(): void
    {
        $http = (new FakeHttpClient())->answer(200, $this->body())->answer(503, '<html>down</html>')->answer(200, $this->body());
        $runtime = Runtime::of($this->config, $this->clock, new MemoryLogger(), $http);
        $run = fn(): array => $runtime->extensions(Budget::of($this->clock, 8, PHP_INT_MAX));
        self::assertSame('updated', $run()['forecast/berlin']['status']);
        $at = $this->clock->now();
        $archive = $this->config->archives['berlin'];
        touch((new Store($this->context(), new Options($this->context()->options)))->path($archive), $at + 86400);
        $this->clock->advance(3600);
        self::assertSame('error', $run()['forecast/berlin']['status']);
        self::assertSame('retry', $run()['forecast/berlin']['status']);
        self::assertCount(2, $http->requests);
        $forecast = (new Forecast(new Weather($this->config, clock: $this->clock)));
        self::assertSame('stale', $forecast->status);
        self::assertSame($at, $forecast->fetchedAt);
        self::assertSame(20.0, $forecast->daily()[0]->value('outTempMax')->raw);
        $this->clock->advance(600);
        self::assertSame('updated', $run()['forecast/berlin']['status']);
        $runtime->close();
    }

    public function testMalformedResponseCannotReplaceGoodDataAndExpiredDaysDisappear(): void
    {
        $store = new Store($this->context(), new Options($this->context()->options));
        $archive = $this->config->archives['berlin'];
        $store->save($archive, $this->body(), $this->clock->now());
        $http = (new FakeHttpClient())->answer(200, '{"hourly":{"time":[]}}');
        $this->clock->advance(3600);
        $runtime = Runtime::of($this->config, $this->clock, new MemoryLogger(), $http);
        self::assertSame('error', $runtime->extensions(Budget::of($this->clock, 8, PHP_INT_MAX))['forecast/berlin']['status']);
        self::assertNotNull($store->read($archive, $this->clock->now()));
        $this->clock->advance(4 * 86400);
        $forecast = (new Forecast(new Weather($this->config, clock: $this->clock)));
        self::assertSame('stale', $forecast->status);
        self::assertSame([], $forecast->daily());
        self::assertSame([], $forecast->hourly()->points);
        $runtime->close();
    }

    public function testMissingCacheReadsWithoutCreatingFilesAndConfigurationChangesInvalidateCache(): void
    {
        self::assertSame('unavailable', (new Forecast(new Weather($this->config, clock: $this->clock)))->status);
        self::assertDirectoryDoesNotExist($this->dir . '/data');
        $store = new Store($this->context(), new Options($this->context()->options));
        $store->save($this->config->archives['berlin'], $this->body(), $this->clock->now());
        $text = (string) file_get_contents($this->path);
        file_put_contents($this->path, str_replace('52.52', '48.0', $text));
        $changed = Config::load($this->path);
        self::assertSame('unavailable', (new Forecast(new Weather($changed, clock: $this->clock)))->status);
        file_put_contents($this->path, str_replace('enabled = true', 'enabled = false', $text));
        $disabled = Config::load($this->path);
        self::assertSame('disabled', (new Forecast(new Weather($disabled, clock: $this->clock)))->status);
        $http = new FakeHttpClient();
        $runtime = Runtime::of($disabled, $this->clock, new MemoryLogger(), $http);
        self::assertSame([], $runtime->extensions(Budget::of($this->clock, 8, PHP_INT_MAX)));
        self::assertSame([], $http->requests);
        $runtime->close();
    }

    public function testBudgetDefersWithoutAttemptAndCapsTheActualHttpTimeout(): void
    {
        $http = (new FakeHttpClient())->answer(200, $this->body());
        $runtime = Runtime::of($this->config, $this->clock, new MemoryLogger(), $http);
        self::assertSame('budget', $runtime->extensions(Budget::of($this->clock, 0.5, PHP_INT_MAX))['forecast/berlin']['status']);
        self::assertFileDoesNotExist($this->context()->directory . '/forecast.json.attempt');
        self::assertSame('updated', $runtime->extensions(Budget::of($this->clock, 2.5, PHP_INT_MAX))['forecast/berlin']['status']);
        self::assertSame(2, $http->last()->timeout);
        self::assertSame(OpenMeteo::MAX_BYTES, $http->last()->maxResponseBytes);
        $runtime->close();
    }

    public function testTickLockPreventsNetworkCalls(): void
    {
        $http = new FakeHttpClient();
        $runtime = Runtime::of($this->config, $this->clock, new MemoryLogger(), $http);
        $runtime->ensureDataDir();
        $lock = Lock::tryAcquire($this->config->settings->lockPath());
        self::assertNotNull($lock);
        try {
            self::assertSame('busy', (new Tick($runtime))->run('cli')->status);
            self::assertSame([], $http->requests);
        } finally {
            $lock->release();
            $runtime->close();
        }
    }

    public function testDailyDatesAndDayLengthsAcrossBothDstChangesAndNegativeOffset(): void
    {
        foreach ([['2026-03-28', 'Europe/Berlin', 23], ['2026-10-24', 'Europe/Berlin', 25], ['2026-09-06', 'America/Los_Angeles', 24]] as [$date, $zone, $hours]) {
            file_put_contents($this->path, str_replace('Europe/Berlin', $zone, (string) file_get_contents($this->path)));
            $config = Config::load($this->path);
            $start = new DateTimeImmutable($date, new DateTimeZone($zone));
            $data = Data::parse(json_encode($this->response($date, $zone), JSON_THROW_ON_ERROR), $config->archives['berlin'], $start->getTimestamp() + 3600);
            self::assertSame($hours * 3600, $data->daily[1]['end'] - $data->daily[1]['start']);
            self::assertSame($start->modify('+1 day')->getTimestamp(), $data->daily[1]['start']);
            self::assertSame(3600, $data->hourly[28]['end'] - $data->hourly[27]['end']);
        }
    }

    public function testInvalidForecastOptionsAreRejectedWithoutExposingSecrets(): void
    {
        foreach (['days' => '17', 'every' => '1s', 'timeout' => '31', 'enabled' => 'perhaps', 'api_key' => "secret\ninvalid"] as $key => $invalid) {
            $options = new Section('options', 3);
            $options->set($key, $invalid);
            try {
                new Options($options);
                self::fail('Accepted invalid option');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Invalid forecast options', $error->getMessage());
            }
        }
    }

    private function context(): Context
    {
        $archive = $this->config->archives['berlin'];
        return new Context($archive, $this->dir . '/data/extensions/forecast/' . hash('sha256', $archive->id), $this->clock->now(), $this->config->extensions['forecast']->optionsFor('berlin'));
    }

    public function testMalformedAndOversizedProviderDataAreRejected(): void
    {
        $good = $this->response();
        foreach (['{}', '{"error":true}', str_repeat(' ', OpenMeteo::MAX_BYTES + 1),
            str_replace('"temperature_2m":[10', '"temperature_2m":["<script>"', json_encode($good, JSON_THROW_ON_ERROR)),
            str_replace('"time":[', '"time":[1,', json_encode($good, JSON_THROW_ON_ERROR))] as $body) {
            try {
                Data::parse($body, $this->config->archives['berlin'], $this->clock->now());
                self::fail('Accepted invalid provider data');
            } catch (\RuntimeException | \JsonException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testSingleDayForecastIsStillUsableAfterItsFinalHourlyInstant(): void
    {
        $response = $this->response();
        foreach (['hourly' => 24, 'daily' => 1] as $key => $count) {
            self::assertIsArray($response[$key]);
            foreach ($response[$key] as $field => $values) {
                self::assertIsArray($values);
                $response[$key][$field] = array_slice($values, 0, $count);
            }
        }
        $now = (new DateTimeImmutable('2026-09-06 23:30', new DateTimeZone('Europe/Berlin')))->getTimestamp();
        $data = Data::parse(json_encode($response, JSON_THROW_ON_ERROR), $this->config->archives['berlin'], $now);
        self::assertCount(1, $data->daily);
    }

    public function testMidnightRefreshesTheForecastBeforeItsNormalInterval(): void
    {
        $this->clock->set((new DateTimeImmutable('2026-09-06 23:30', new DateTimeZone('Europe/Berlin')))->getTimestamp());
        $store = new Store($this->context(), new Options($this->context()->options));
        $store->save($this->config->archives['berlin'], $this->body(), $this->clock->now());
        $this->clock->advance(35 * 60);
        self::assertSame('stale', (new Forecast(new Weather($this->config, clock: $this->clock)))->status);
        $http = (new FakeHttpClient())->answer(200, json_encode($this->response('2026-09-07'), JSON_THROW_ON_ERROR));
        $runtime = Runtime::of($this->config, $this->clock, new MemoryLogger(), $http);
        self::assertSame('updated', $runtime->extensions(Budget::of($this->clock, 8, PHP_INT_MAX))['forecast/berlin']['status']);
        self::assertSame('2026-09-07', (new Forecast(new Weather($this->config, clock: $this->clock)))->daily()[0]->meta['date']);
        $runtime->close();
    }

    public function testCustomerEndpointAndTransportFailureNeverExposeApiKey(): void
    {
        file_put_contents($this->path, (string) file_get_contents($this->path) . "   api_key = private_customer_key\n");
        $config = Config::load($this->path);
        $http = (new FakeHttpClient())->fail('https://customer-api.open-meteo.com/v1/forecast?apikey=private_customer_key');
        $log = new MemoryLogger();
        $runtime = Runtime::of($config, $this->clock, $log, $http);
        try {
            $result = $runtime->extensions(Budget::of($this->clock, 8, PHP_INT_MAX));
            self::assertSame('error', $result['forecast/berlin']['status']);
            self::assertStringStartsWith('https://customer-api.open-meteo.com/v1/forecast?', $http->last()->url);
            self::assertStringContainsString('apikey=private_customer_key', $http->last()->url);
            self::assertStringNotContainsString('private_customer_key', json_encode([$result, $log->messages()], JSON_THROW_ON_ERROR));
            $wx = new Weather($config, clock: $this->clock);
            self::assertStringNotContainsString('private_customer_key', json_encode($wx->tag('forecast.status'), JSON_THROW_ON_ERROR));
            $wx->close();
        } finally {
            $runtime->close();
        }
    }

    public function testArchiveOptionsControlTheWorkerAndReader(): void
    {
        $file = \WeewxPhp\Config\ConfFile::read($this->path);
        $extension = $file->root()->section('Extensions')->section('forecast');
        $overrides = \WeewxPhp\Admin\Service::section($extension, 'archive_options');
        \WeewxPhp\Admin\Service::section($overrides, 'berlin')->set('days', '10');
        $file->write($this->path);
        $config = Config::load($this->path);
        $http = (new FakeHttpClient())->answer(200, $this->body());
        $runtime = Runtime::of($config, $this->clock, new MemoryLogger(), $http);
        self::assertSame('updated', $runtime->extensions(Budget::of($this->clock, 8, PHP_INT_MAX))['forecast/berlin']['status']);
        self::assertStringContainsString('forecast_days=10', $http->last()->url);
        $runtime->close();
        $overrides->section('berlin')->set('enabled', 'false');
        $file->write($this->path);
        $config = Config::load($this->path);
        $runtime = Runtime::of($config, $this->clock, new MemoryLogger(), $http);
        self::assertSame('disabled', $runtime->extensions(Budget::of($this->clock, 8, PHP_INT_MAX))['forecast/berlin']['status']);
        self::assertSame('disabled', (new Weather($config, clock: $this->clock))->tag('forecast.status')->status);
        self::assertCount(1, $http->requests);
        $runtime->close();
    }

    public function testInvalidTagArgumentsAndMissingCoordinates(): void
    {
        $wx = new Weather($this->config, clock: $this->clock);
        foreach ([['forecast.day', ['index' => -1]], ['forecast.day', ['index' => '0']], ['forecast.status', ['url' => 'http://localhost']],
            ['forecast.hourly', ['hours' => 500]], ['forecast.hourly', ['observation' => 'unknown']]] as [$tag, $args]) {
            try {
                $wx->tag($tag, $args);
                self::fail('Invalid tag arguments accepted');
            } catch (\WeewxPhp\Frontend\QueryError $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        $wx->close();
        file_put_contents($this->path, str_replace('        latitude = 52.52' . "\n", '', (string) file_get_contents($this->path)));
        $config = Config::load($this->path);
        $http = new FakeHttpClient();
        $runtime = Runtime::of($config, $this->clock, new MemoryLogger(), $http);
        self::assertSame('coordinates_missing', $runtime->extensions(Budget::of($this->clock, 8, PHP_INT_MAX))['forecast/berlin']['status']);
        self::assertSame('unavailable', (new Weather($config, clock: $this->clock))->tag('forecast.status')->status);
        self::assertSame([], $http->requests);
        $runtime->close();
    }
}
