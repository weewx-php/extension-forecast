<?php

declare(strict_types=1);

namespace WeewxPhp\Tests\Forecast;

use PHPUnit\Framework\TestCase;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Extensions\Forecast\Migration;

require_once dirname(__DIR__) . '/extension.php';
require_once dirname(__DIR__) . '/src/Migration.php';

final class MigrationTest extends TestCase
{
    private function file(): ConfFile
    {
        return ConfFile::parse("tick_token = preserved\n[Archives]\n [[active]]\n  name = Existing\n  [[[forecast]]]\n   enabled = true\n   days = 10\n   every = 2h\n   timeout = 12\n [[inactive]]\n  [[[forecast]]]\n   enabled = false\n [[without]]\n[Extensions]\n [[forecast]]\n  enabled = false\n  entry = installed/extension.php\n  managed_release = unchanged\n [[climate]]\n  enabled = true\n  entry = climate/extension.php\n");
    }

    public function testPreservesEveryArchiveAndOtherExtensionsAndIsIdempotent(): void
    {
        $file = $this->file();
        $climate = $file->root()->section('Extensions')->section('climate')->value('entry')->string();
        self::assertSame(2, Migration::apply($file));
        $extension = $file->root()->section('Extensions')->section('forecast');
        self::assertTrue($extension->value('enabled')->bool());
        self::assertSame('unchanged', $extension->value('managed_release')->string());
        self::assertFalse($extension->section('options')->value('enabled')->bool());
        $active = $extension->section('archive_options')->section('active');
        self::assertTrue($active->value('enabled')->bool());
        self::assertSame(10, $active->value('days')->int());
        self::assertSame(7200, $active->value('every')->int());
        self::assertSame(12, $active->value('timeout')->int());
        foreach (['inactive', 'without'] as $id) {
            self::assertFalse($extension->section('archive_options')->section($id)->value('enabled')->bool());
        }
        foreach ($file->root()->section('Archives')->sections() as $archive) {
            self::assertFalse($archive->has('forecast'));
        }
        self::assertSame('Existing', $file->root()->section('Archives')->section('active')->value('name')->string());
        self::assertSame('preserved', $file->root()->value('tick_token')->string());
        self::assertSame($climate, $file->root()->section('Extensions')->section('climate')->value('entry')->string());
        $after = $file->toString();
        self::assertSame(0, Migration::apply($file));
        self::assertSame($after, $file->toString());
    }

    public function testExistingExtensionSettingsAreNeverOverwritten(): void
    {
        $file = $this->file();
        \WeewxPhp\Admin\Service::section($file->root()->section('Extensions')->section('forecast'), 'options')->set('days', '14');
        $before = $file->toString();
        try {
            Migration::apply($file);
            self::fail('Conflict ignored');
        } catch (\RuntimeException) {
            self::assertSame($before, $file->toString());
        }
    }
}
