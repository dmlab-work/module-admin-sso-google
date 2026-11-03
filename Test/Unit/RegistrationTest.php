<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;

class RegistrationTest extends TestCase
{
    public function testModuleIsRegistered(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);

        self::assertArrayHasKey('DmLab_AdminSsoGoogle', $paths);
    }

    public function testRegisteredPathPointsAtThisModule(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);
        $path = $paths['DmLab_AdminSsoGoogle'] ?? null;

        self::assertNotNull($path);
        self::assertDirectoryExists($path);
        self::assertFileExists($path . '/etc/module.xml');
    }

    public function testModuleSequencesAfterSsoCoreAndAdminSso(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);
        $moduleXml = ($paths['DmLab_AdminSsoGoogle'] ?? '') . '/etc/module.xml';

        $dom = new \DOMDocument();
        self::assertTrue($dom->load($moduleXml));

        $sequenced = [];
        foreach ($dom->getElementsByTagName('sequence') as $sequence) {
            foreach ($sequence->getElementsByTagName('module') as $module) {
                $sequenced[] = $module->getAttribute('name');
            }
        }

        self::assertContains('DmLab_AdminSso', $sequenced);
        self::assertContains('DmLab_SsoCore', $sequenced);
    }

    public function testComposerDeclaresDirectDependenciesWithoutVersion(): void
    {
        $composer = json_decode(
            (string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $composer['version'] ?? '');
        self::assertSame('^0.2.0||^0.3.0', $composer['require']['dmlab/module-sso-core'] ?? null);
        self::assertArrayHasKey('magento/module-config', $composer['require']);
    }

    public function testSetupVersionMatchesComposerVersion(): void
    {
        $root = dirname(__DIR__, 2);
        $dom = new \DOMDocument();
        self::assertTrue($dom->load($root . '/etc/module.xml'));
        $setupVersion = $dom->getElementsByTagName('module')->item(0)?->getAttribute('setup_version');
        $composer = json_decode((string)file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($composer['version'] ?? null, $setupVersion);
    }
}
