<?php

/**
 * Unit tests for GetAllSettings use case.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Admin\Application\UseCases\Settings
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Admin\Application\UseCases\Settings;

use Lwt\Modules\Admin\Application\UseCases\Settings\GetAllSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the GetAllSettings use case.
 *
 * Note: execute() uses static Settings::getWithDefault() calls and cannot
 * be fully unit-tested without a database. These tests cover the static
 * metadata methods instead.
 *
 * @since 3.0.0
 */
class GetAllSettingsTest extends TestCase
{
    #[Test]
    public function getSettingKeysReturnsNonEmptyArray(): void
    {
        $keys = GetAllSettings::getSettingKeys();
        $this->assertNotEmpty($keys);
    }

    #[Test]
    public function getSettingKeysAllStartWithSet(): void
    {
        $keys = GetAllSettings::getSettingKeys();
        foreach ($keys as $key) {
            $this->assertStringStartsWith(
                'set-',
                $key,
                "Setting key '$key' should start with 'set-'"
            );
        }
    }

    #[Test]
    public function getSettingKeysContainsAdminKeys(): void
    {
        $keys = GetAllSettings::getSettingKeys();
        $expectedSubset = [
            'set-max-articles-with-text',
            'set-max-articles-without-text',
            'set-max-texts-per-feed',
            'set-allow-registration',
        ];
        foreach ($expectedSubset as $expected) {
            $this->assertContains(
                $expected,
                $keys,
                "Admin setting keys should contain '$expected'"
            );
        }
    }

    #[Test]
    public function getSettingKeysDoesNotContainUserKeys(): void
    {
        $keys = GetAllSettings::getSettingKeys();
        $userKeys = [
            'set-tooltip-mode',
            'set-tts',
            'set-texts-per-page',
            'set-terms-per-page',
            'set-regex-mode',
        ];
        foreach ($userKeys as $userKey) {
            $this->assertNotContains(
                $userKey,
                $keys,
                "Admin setting keys should not contain user key '$userKey'"
            );
        }
    }

    #[Test]
    public function getSettingKeysHasNoDuplicates(): void
    {
        $keys = GetAllSettings::getSettingKeys();
        $unique = array_unique($keys);
        $this->assertCount(
            count($unique),
            $keys,
            'Setting keys should have no duplicates'
        );
    }

    #[Test]
    public function getSettingKeysAreAllStrings(): void
    {
        $keys = GetAllSettings::getSettingKeys();
        foreach ($keys as $key) {
            $this->assertIsString($key);
            $this->assertNotEmpty($key);
        }
    }
}
