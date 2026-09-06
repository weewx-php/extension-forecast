# Forecast

Worldwide weather forecasts from Open-Meteo as a weewx-php extension.
Requires PHP 8.1+, JSON and extension API 2. The web host needs no Composer, ZIP
or separate cron job. Install under **Extensions** in the admin.

## Settings

Configure under **Extensions → Forecast**, then activate. Settings are available
before activation. **Defaults** apply to all archives; individual archives can
override them. Coordinates and time zone come from each archive's configuration.

| Field | Default | Range |
|---|---|---|
| Forecast | enabled | enabled / disabled per archive |
| Days | 7 | 1–16, including today |
| Refresh interval | 3600 seconds | 1800–86400 seconds |
| Timeout | 8 seconds | 1–30 seconds, further limited by the tick budget |
| API key | empty | optional Open-Meteo customer key |

The free API is for non-commercial use. A customer key activates the fixed HTTPS
customer endpoint. The CC BY 4.0 data license and access terms apply separately.
[Access](https://open-meteo.com/en/pricing), [API documentation](https://open-meteo.com/en/docs).

Manual configuration:

```ini
[Extensions]
    [[forecast]]
        enabled = true
        entry = extensions/forecast/extension.php
        [[[options]]]
            enabled = true
            days = 7
            every = 3600
            timeout = 8
        [[[archive_options]]]
            [[[[berlin]]]]
                days = 10
```

## Tick and data

The existing tick makes at most one request per archive, outside the archive
lock and under the extension lock. The visitor tick uses the same background
lane. No separate cron job is needed. `extensions run` optionally executes one
step. Errors and interrupted requests trigger a ten-minute wait. Valid old data
remain available as `stale`. Freshness is based on the fetch time; data are also
refreshed after local midnight.

Changes to the location, time zone, number of days or key invalidate the cache.
Files are private under `data/extensions/forecast/<archive-hash>/`. Responses are
limited to 1 MiB and published atomically after validating time axes and values.
Observation archives and analytics do not contain these forecasts. Cache data
can be downloaded again after a restore.

## Theme tags

```php
use WeewxPhp\Frontend\Report;
use WeewxPhp\Frontend\Series;

if ($wx->hasTag('forecast.status')) {
    $status = $wx->tag('forecast.status');
    // Report.status: ready | stale | unavailable | disabled
    // meta: fetched_at, days, source, source_url, license, license_url
    $today = $wx->tag('forecast.day', ['index' => 0]);
    if ($today instanceof Report && isset($today->meta['date'])) {
        echo $today->value('outTempMax')->to('degree_C')->html();
    }
    $hours = $wx->tag('forecast.hourly', ['observation' => 'outTemp', 'hours' => 48]);
    if ($hours instanceof Series) {
        $points = $hours->pairs(milliseconds: true);
    }
}
```

`forecast.day`: index 0–15 starting today. Unavailable days return an empty
report without `date`. Values: `outTempMin`, `outTempMax`, `rain`, `rainProbability`,
`windSpeed`, `windGust`, `windDir`, `sunshineDur`, `weatherCode`.
Metadata: `start`, `end`, `date`, `source`, `fetched_at`.

### Daily weather symbol

The daily `weatherCode` represents the prevailing daylight condition. Open-Meteo's
daily code instead describes the most severe condition over 24 hours, so brief
morning fog can otherwise label an entire sunny day as foggy.

Hourly codes are grouped by condition, including clear/mainly clear together.
Only instants with the sun at or above -0.833 degrees count. Their weight is
`1 + sin(max(0, solar altitude))`, giving the hours near solar noon more influence.
The winning group supplies its most strongly represented WMO code; exact ties
prefer the code with the greater numeric value. Daytime thunderstorms and
freezing precipitation take precedence even when brief.

The calculation uses the archive's coordinates and local calendar boundaries,
including 23/25-hour days. During polar night, all hours receive equal weight.
If valid hourly codes cover less than 75% of the expected weight, the original
daily code is retained. Existing caches work immediately. Hourly codes, daily
temperature ranges, precipitation totals and sunshine duration remain provider
values. Today's symbol represents the whole daylight period even in the evening.

`forecast.hourly`: 1–384 hours, limited by the cache. Values: `outTemp`,
`dewpoint`, `outHumidity`, `rain`, `rainProbability`, `radiation`, `cloudcover`,
`windSpeed`, `windGust`, `windDir`, `weatherCode`, `snow`, `visibility`.
Instantaneous values have `start = end`; precipitation, snow, probability,
radiation and gusts refer to the preceding hour. Local days have 23 or 25 hours
at daylight saving time changes. Null values and actual zeros are preserved.
Output profiles and unit conversion also apply to these tags.

Readers load local data only. A historical observation reference does not change
the current forecast reference time. The demo theme displays the tags automatically
and hides the section when the extension is disabled.
Keep source and license attribution in published displays:

```html
<a href="https://open-meteo.com/">Open-Meteo</a> ·
<a href="https://creativecommons.org/licenses/by/4.0/">CC BY 4.0</a>
```

## Migrating from the former core implementation

Install the package first, without saving any options yet. After updating the
core to extension API 2, explicitly run this command once:

```bash
php /path/to/installed/forecast/tools/migrate.php /path/to/weewx-php /path/to/weewx-php.conf
```

The tool verifies package files, imports `enabled`, `days`, `every` and `timeout`
per archive, and removes the old `[[[forecast]]]` sections. Archives without an
old forecast remain disabled. Valid caches are copied; observations remain
unchanged. Existing new package options cause a conflict. Running again without
legacy sections changes nothing. Revision handling, locks and recovery come from
the core. Themes switch from `$wx->forecast()` to the tags.
`extensions run` replaces `forecast fetch`.

## Tests

Run the suite in Docker using the core test image and the separate demo theme:

```bash
export WEEWX_PHP_ROOT=/path/to/weewx-php
export WEEWX_DEMO_THEME_ROOT=/path/to/theme-demo
docker compose -f "$WEEWX_PHP_ROOT/tests/docker/compose.yml" build unit
docker compose -f tests/docker/compose.yml run --rm unit
```

[Review](docs/review.md). The original cache and display rules come from the
former weewx-php core implementation.
