<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit;

use DmLab\AdminSso\Model\PresetRegistry;
use DmLab\AdminSsoGoogle\Model\GooglePreset;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use PHPUnit\Framework\TestCase;

/**
 * Asserts di.xml wires the Google preset into admin-sso's open/closed seam and
 * that admin-sso's PresetRegistry then indexes it under the `google` code.
 */
class PresetRegistrationTest extends TestCase
{
    private const REGISTRY = 'DmLab\\AdminSso\\Model\\PresetRegistry';

    public function testDiXmlRegistersGooglePresetIntoPresetRegistry(): void
    {
        $diXml = dirname(__DIR__, 2) . '/etc/di.xml';
        self::assertFileExists($diXml);

        $dom = new \DOMDocument();
        self::assertTrue($dom->load($diXml));

        $item = $this->findPresetItem($dom);

        self::assertNotNull($item, 'No "google" preset item registered on the PresetRegistry type.');
        self::assertSame('google', $item->getAttribute('name'));
        self::assertSame(GooglePreset::class, ltrim(trim($item->textContent), '\\'));
    }

    public function testRegistryIndexesGooglePresetUnderItsCode(): void
    {
        $registry = new PresetRegistry([new GooglePreset($this->createStub(AssetRepository::class))]);

        self::assertTrue($registry->has('google'));
        self::assertInstanceOf(GooglePreset::class, $registry->get('google'));
    }

    private function findPresetItem(\DOMDocument $dom): ?\DOMElement
    {
        foreach ($dom->getElementsByTagName('type') as $type) {
            if (ltrim($type->getAttribute('name'), '\\') !== self::REGISTRY) {
                continue;
            }
            foreach ($type->getElementsByTagName('argument') as $argument) {
                if ($argument->getAttribute('name') !== 'presets') {
                    continue;
                }
                foreach ($argument->getElementsByTagName('item') as $item) {
                    if ($item->getAttribute('name') === 'google') {
                        return $item;
                    }
                }
            }
        }

        return null;
    }
}
