# Forecast

Weltweite Wettervorhersagen von Open-Meteo als Erweiterung für weewx-php.
PHP 8.1+, JSON und Erweiterungs-API 2. Kein Composer, ZIP oder eigener Cronjob
auf dem Webhost nötig. Installation im Admin unter **Erweiterungen**.

## Einstellungen

Unter **Erweiterungen → Forecast** konfigurieren und anschließend aktivieren.
Einstellungen funktionieren auch vor der Aktivierung. **Standardwerte** gelten
für alle Archive; einzelne Archive können abweichen. Koordinaten und Zeitzone
stammen aus der jeweiligen Archivkonfiguration.

| Feld | Standard | Bereich |
|---|---|---|
| Vorhersage | aktiv | aktiv / inaktiv je Archiv |
| Tage | 7 | 1–16, einschließlich heute |
| Abrufintervall | 3600 Sekunden | 1800–86400 Sekunden |
| Timeout | 8 Sekunden | 1–30 Sekunden, zusätzlich durch Tick-Budget begrenzt |
| API-Schlüssel | leer | optionaler Open-Meteo-Kundenschlüssel |

Die kostenlose API ist für nicht kommerzielle Nutzung. Ein Kundenschlüssel
aktiviert den festen HTTPS-Kundenendpunkt. Datenlizenz CC BY 4.0 und
Zugangsbedingungen sind getrennt zu beachten.
[Zugang](https://open-meteo.com/en/pricing), [API-Dokumentation](https://open-meteo.com/en/docs).

Manuelle Konfiguration:

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

## Tick und Daten

Pro Archiv höchstens eine Anfrage am bestehenden Tick, außerhalb der
Archivsperre und unter der Erweiterungssperre. Auch der Besucher-Tick nutzt
diese Hintergrundspur. Kein eigener Cron. `extensions run` führt optional
einen Schritt aus. Fehler und unterbrochene Anfragen warten zehn Minuten.
Gültige alte Daten bleiben als `stale` verfügbar. Der Abrufzeitpunkt bestimmt
die Aktualität; zusätzlich wird ab lokalem Mitternacht neu geladen.

Standort, Zeitzone, Tagesanzahl oder Schlüsseländerungen invalidieren den Cache.
Dateien liegen privat in `data/extensions/forecast/<archiv-hash>/`. Maximal 1 MiB
Antwortgröße, atomare Veröffentlichung nach Prüfung der Zeitachsen und Werte.
Messarchive und Analytics enthalten diese Vorhersagen nicht. Nach einem Restore
können die Cache-Daten erneut geladen werden.

## Theme-Tags

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

`forecast.day`: Index 0–15 ab heute. Nicht verfügbare Tage liefern einen leeren
Report ohne `date`. Werte: `outTempMin`, `outTempMax`, `rain`, `rainProbability`,
`windSpeed`, `windGust`, `windDir`, `sunshineDur`, `weatherCode`.
Metadaten: `start`, `end`, `date`, `source`, `fetched_at`.

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

`forecast.hourly`: 1–384 Stunden, begrenzt durch den Cache. Werte: `outTemp`,
`dewpoint`, `outHumidity`, `rain`, `rainProbability`, `radiation`, `cloudcover`,
`windSpeed`, `windGust`, `windDir`, `weatherCode`, `snow`, `visibility`.
Momentanwerte haben `start = end`; Niederschlag, Schnee, Wahrscheinlichkeit,
Strahlung und Böen beziehen sich auf die vorangehende Stunde. Lokale Tage haben
bei Sommerzeitwechseln 23 oder 25 Stunden. Nullwerte und echte Nullen bleiben
erhalten. Ausgabeprofile und Einheitenumrechnung gelten auch für diese Tags.

Reader laden nur lokale Daten. Ein historischer Messdatenbezug ändert den
aktuellen Vorhersagezeitpunkt nicht. Das Demo-Theme zeigt die Tags automatisch
an und blendet den Bereich bei deaktivierter Erweiterung aus.
Quellen- und Lizenzangabe bei veröffentlichter Darstellung beibehalten:

```html
<a href="https://open-meteo.com/">Open-Meteo</a> ·
<a href="https://creativecommons.org/licenses/by/4.0/">CC BY 4.0</a>
```

## Umstieg aus dem früheren Core

Zuerst das Paket installieren und noch keine Optionen speichern. Nach dem
Core-Update mit Erweiterungs-API 2 einmal ausdrücklich aufrufen:

```bash
php /path/to/installed/forecast/tools/migrate.php /path/to/weewx-php /path/to/weewx-php.conf
```

Das Werkzeug prüft die Paketdateien, übernimmt `enabled`, `days`, `every` und
`timeout` je Archiv und entfernt die alten `[[[forecast]]]`-Abschnitte.
Archive ohne alte Vorhersage bleiben deaktiviert. Gültige Caches werden kopiert;
Messdaten bleiben unverändert. Bestehende neue Paketoptionen verursachen einen
Konflikt. Wiederholung ohne Altabschnitte ändert nichts. Revision, Sperren und
Recovery kommen vom Core. Themes wechseln von `$wx->forecast()` auf die Tags.
`forecast fetch` wird durch `extensions run` ersetzt.

## Tests

Run the suite in Docker using the core test image and the separate demo theme:

```bash
export WEEWX_PHP_ROOT=/path/to/weewx-php
export WEEWX_DEMO_THEME_ROOT=/path/to/theme-demo
docker compose -f "$WEEWX_PHP_ROOT/tests/docker/compose.yml" build unit
docker compose -f tests/docker/compose.yml run --rm unit
```

[Prüfbericht](docs/review.md). Die ursprünglichen Cache- und Darstellungsregeln
stammen aus dem bisherigen weewx-php-Core.
