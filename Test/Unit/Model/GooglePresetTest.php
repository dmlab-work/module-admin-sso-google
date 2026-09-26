<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit\Model;

use DmLab\AdminSsoGoogle\Model\GooglePreset;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use PHPUnit\Framework\TestCase;

class GooglePresetTest extends TestCase
{
    /** @var GooglePreset preset under test. */
    private GooglePreset $preset;

    protected function setUp(): void
    {
        $assetRepository = $this->createStub(AssetRepository::class);
        $assetRepository->method('getUrl')
            ->willReturnCallback(
                static fn (string $fileId) => 'https://magento.loc/static/' . $fileId
            );

        $this->preset = new GooglePreset($assetRepository);
    }

    public function testImplementsProviderPresetContract(): void
    {
        self::assertInstanceOf(ProviderPresetInterface::class, $this->preset);
    }

    public function testExposesIdentity(): void
    {
        self::assertSame('google', $this->preset->getCode());
        self::assertSame('Google Workspace', $this->preset->getLabel());
    }

    public function testDiscoveryUrlIsFixedGoogleEndpoint(): void
    {
        self::assertSame(
            'https://accounts.google.com/.well-known/openid-configuration',
            $this->preset->buildDiscoveryUrl([])
        );
    }

    public function testDiscoveryUrlIgnoresPassedConfig(): void
    {
        self::assertSame(
            'https://accounts.google.com/.well-known/openid-configuration',
            $this->preset->buildDiscoveryUrl(['domain' => 'example.com', 'hd' => 'acme.com'])
        );
    }

    public function testDefaultScopesAreOpenidProfileEmail(): void
    {
        self::assertSame(
            ['openid', 'profile', 'email'],
            $this->preset->getDefaultScopes()
        );
    }

    public function testGroupsClaimIsNull(): void
    {
        self::assertNull($this->preset->getGroupsClaim());
    }

    public function testButtonLabel(): void
    {
        self::assertSame('Sign in with Google', $this->preset->getButtonLabel());
    }

    public function testButtonIconResolvesShippedGoogleLogo(): void
    {
        self::assertSame(
            'https://magento.loc/static/DmLab_AdminSsoGoogle::images/google.svg',
            $this->preset->getButtonIconUrl()
        );
    }
}
