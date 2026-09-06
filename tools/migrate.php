<?php

declare(strict_types=1);

// Usage: php tools/migrate.php /path/to/weewx-php /path/to/weewx-php.conf
if (PHP_SAPI !== 'cli' || !isset($argv) || count($argv) !== 3) {
    throw new RuntimeException('Usage: php tools/migrate.php CORE_DIRECTORY CONFIG_FILE');
}
require rtrim($argv[1], '/\\') . '/src/autoload.php';
require dirname(__DIR__) . '/extension.php';
require dirname(__DIR__) . '/src/Migration.php';

use WeewxPhp\Admin\{Changes, ReadModel};
use WeewxPhp\Config\{ConfFile, Config};
use WeewxPhp\Extension\{Context, Files, Installer};
use WeewxPhp\Extensions\Forecast\{Migration, OpenMeteo, Options, Store};
use WeewxPhp\Upload\Http\Http;

$path = $argv[2];
$read = new ReadModel($path);
$files = new Files($read->config->settings->dataDir . '/extension-store');
$files->directory();
$operationLock = \WeewxPhp\Tick\Lock::tryAcquire($files->root . '/operation.lock') ?? throw new RuntimeException('Extension operation in progress');
$read = new ReadModel($path);
$installer = new Installer(new Files($read->config->settings->dataDir . '/extension-store'), Http::client());
$section = $read->file->root()->section('Extensions')->section('forecast');
$release = $installer->installed('forecast', $section);
if ($release === null || realpath($installer->entry($release)) !== realpath(dirname(__DIR__) . '/extension.php')) {
    throw new RuntimeException('Install this approved forecast package first');
}
$installer->verify($release);
$preview = ConfFile::parse($read->file->toString());
$count = Migration::apply($preview);
if ($count === 0) {
    echo "No legacy forecast settings.\n";
    exit(0);
}
$copied = 0;
// Cache copies precede the configuration switch. Invalid caches are left for the tick.
foreach ($read->config->archives as $archive) {
    $options = new Options($preview->root()->section('Extensions')->section('forecast')->section('archive_options')->section($archive->id));
    if (!$options->enabled || $archive->latitude === null || $archive->longitude === null) {
        continue;
    }
    $context = new Context($archive, $read->config->settings->dataDir . '/extensions/forecast/' . hash('sha256', $archive->id), time());
    $old = Files::read($read->config->settings->dataDir . '/forecast/' . hash('sha256', $archive->id) . '.json', OpenMeteo::MAX_BYTES * 2);
    try {
        $data = $old === null ? [] : json_decode($old, true, 32, JSON_THROW_ON_ERROR);
        if (is_array($data) && ($data['fingerprint'] ?? '') === OpenMeteo::fingerprint($archive, $options)
            && is_string($data['body'] ?? null) && is_int($data['fetched_at'] ?? null) && $data['fetched_at'] <= time()) {
            (new Store($context, $options))->save($archive, $data['body'], $data['fetched_at']);
            ++$copied;
        }
    } catch (Throwable) {
        // Invalid or obsolete cache; the extension fetches a replacement.
    }
}
(new Changes($path, time()))->apply($read->revision, 'extension.forecast.migrate', static function (ConfFile $file, Config $config): void {
    Migration::apply($file);
});
echo json_encode(['migrated_archives' => $count, 'copied_caches' => $copied], JSON_THROW_ON_ERROR), "\n";
$operationLock->release();
