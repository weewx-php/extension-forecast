<?php

declare(strict_types=1);

namespace WeewxPhp\Extensions\Forecast;

use WeewxPhp\Admin\Service;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Config\Section;

/** Explicit migration of the former core settings. Never called by registration. */
final class Migration
{
    public static function apply(ConfFile $file): int
    {
        $archives = $file->root()->optionalSection('Archives')?->sections() ?? [];
        $legacy = array_filter($archives, static fn(Section $archive): bool => $archive->optionalSection('forecast') !== null);
        if ($legacy === []) {
            return 0;
        }
        $extension = $file->root()->section('Extensions')->section('forecast');
        if ($extension->has('legacy_migrated') || $extension->optionalSection('options') !== null || $extension->optionalSection('archive_options') !== null) {
            throw new \RuntimeException('Existing forecast extension settings conflict with migration');
        }
        $rows = [];
        $enabled = false;
        foreach ($archives as $id => $archive) {
            $source = $archive->optionalSection('forecast');
            $row = new Section($id, 4);
            $row->set('enabled', ($source?->optional('enabled')?->bool() ?? false) ? 'true' : 'false');
            $row->set('days', (string) ($source?->optional('days')?->int() ?? 7));
            $row->set('every', (string) ($source?->optional('every')?->duration() ?? 3600));
            $row->set('timeout', (string) ($source?->optional('timeout')?->int() ?? 8));
            $options = new Options($row);
            $enabled = $enabled || $options->enabled;
            $rows[$id] = $row;
        }
        $overrides = Service::section($extension, 'archive_options');
        foreach ($rows as $id => $row) {
            $target = Service::section($overrides, $id);
            foreach ($row->values() as $key => $value) {
                $target->set($key, $value->raw());
            }
            $archives[$id]->remove('forecast');
        }
        Service::section($extension, 'options')->set('enabled', 'false');
        $extension->set('enabled', $enabled ? 'true' : 'false');
        $extension->set('legacy_migrated', 'true');
        return count($legacy);
    }
}
