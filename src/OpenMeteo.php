<?php

declare(strict_types=1);

namespace WeewxPhp\Extensions\Forecast;

use RuntimeException;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Upload\Http\HttpRequest;

/** Fixed HTTPS provider and the units/period semantics of its response. */
final class OpenMeteo
{
    public const MAX_BYTES = 1048576;
    public const HOURLY = [
        'outTemp' => 'temperature_2m', 'dewpoint' => 'dew_point_2m',
        'outHumidity' => 'relative_humidity_2m', 'rain' => 'precipitation',
        'rainProbability' => 'precipitation_probability', 'radiation' => 'shortwave_radiation',
        'cloudcover' => 'cloud_cover', 'windSpeed' => 'wind_speed_10m',
        'windGust' => 'wind_gusts_10m', 'windDir' => 'wind_direction_10m',
        'weatherCode' => 'weather_code', 'snow' => 'snowfall', 'visibility' => 'visibility',
    ];
    public const DAILY = [
        'outTempMin' => 'temperature_2m_min', 'outTempMax' => 'temperature_2m_max',
        'rain' => 'precipitation_sum', 'rainProbability' => 'precipitation_probability_max',
        'sunshineDur' => 'sunshine_duration', 'windSpeed' => 'wind_speed_10m_max',
        'windGust' => 'wind_gusts_10m_max', 'windDir' => 'wind_direction_10m_dominant',
        'weatherCode' => 'weather_code',
    ];
    public const UNITS = [
        'outTemp' => ['degree_C', 'group_temperature'], 'dewpoint' => ['degree_C', 'group_temperature'],
        'outTempMin' => ['degree_C', 'group_temperature'], 'outTempMax' => ['degree_C', 'group_temperature'],
        'outHumidity' => ['percent', 'group_percent'], 'rain' => ['mm', 'group_rain'],
        'rainProbability' => ['percent', 'group_percent'], 'cloudcover' => ['percent', 'group_percent'],
        'radiation' => ['watt_per_meter_squared', 'group_radiation'],
        'windSpeed' => ['meter_per_second', 'group_speed'], 'windGust' => ['meter_per_second', 'group_speed'],
        'windDir' => ['degree_compass', 'group_direction'], 'weatherCode' => ['count', 'group_count'],
        'snow' => ['cm', 'group_rain'], 'visibility' => ['meter', 'group_distance'],
        'sunshineDur' => ['second', 'group_deltatime'],
    ];
    /** These values describe the preceding hour; other fields are instantaneous. */
    public const INTERVALS = ['rain', 'rainProbability', 'radiation', 'windGust', 'snow'];

    public static function request(ArchiveConfig $archive, Options $options): HttpRequest
    {
        if ($archive->latitude === null || $archive->longitude === null) {
            throw new RuntimeException('Forecast requires coordinates');
        }
        return new HttpRequest('GET', ($options->apiKey === '' ? 'https://api.open-meteo.com/v1/forecast?' : 'https://customer-api.open-meteo.com/v1/forecast?') . http_build_query([
            'latitude' => $archive->latitude, 'longitude' => $archive->longitude,
            'hourly' => implode(',', self::HOURLY), 'daily' => implode(',', self::DAILY),
            'timezone' => $archive->timezone->getName(), 'timeformat' => 'unixtime',
            'forecast_days' => $options->days, 'temperature_unit' => 'celsius',
            'precipitation_unit' => 'mm', 'wind_speed_unit' => 'ms',
        ] + ($options->apiKey === '' ? [] : ['apikey' => $options->apiKey]), '', '&', PHP_QUERY_RFC3986), timeout: $options->timeout, maxResponseBytes: self::MAX_BYTES);
    }

    public static function fingerprint(ArchiveConfig $archive, Options $options): string
    {
        return hash('sha256', self::request($archive, $options)->url);
    }
}
