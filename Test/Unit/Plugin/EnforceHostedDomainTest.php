<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit\Plugin;

use DmLab\AdminSsoGoogle\Model\Config;
use DmLab\AdminSsoGoogle\Model\GooglePreset;
use DmLab\AdminSsoGoogle\Plugin\EnforceHostedDomain;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use DmLab\SsoCore\Model\Oidc\IdentityFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use PHPUnit\Framework\TestCase;

class EnforceHostedDomainTest extends TestCase
{
    /** @var IdentityFactory identity factory the plugin wraps (unused subject). */
    private IdentityFactory $subject;

    /** @var GooglePreset real Google preset the plugin matches on. */
    private GooglePreset $googlePreset;

    protected function setUp(): void
    {
        $this->subject = new IdentityFactory();
        $this->googlePreset = new GooglePreset($this->createStub(AssetRepository::class));
    }

    /**
     * @param string|null $allowedDomain
     */
    private function plugin(?string $allowedDomain): EnforceHostedDomain
    {
        $config = $this->createStub(Config::class);
        $config->method('getAllowedDomain')->willReturn($allowedDomain);

        return new EnforceHostedDomain($config);
    }

    public function testNoRestrictionPassesAnyGoogleAccountThrough(): void
    {
        $claims = ['sub' => 'u1'];
        $result = $this->plugin(null)->beforeCreate($this->subject, $claims, $this->googlePreset);

        self::assertSame([$claims, $this->googlePreset], $result);
    }

    public function testMatchingHostedDomainIsAllowed(): void
    {
        $claims = ['sub' => 'u1', 'hd' => 'example.com'];
        $result = $this->plugin('example.com')->beforeCreate($this->subject, $claims, $this->googlePreset);

        self::assertSame([$claims, $this->googlePreset], $result);
    }

    public function testMatchingHostedDomainWithSurroundingWhitespaceIsAllowed(): void
    {
        $claims = ['sub' => 'u1', 'hd' => '  example.com  '];
        $result = $this->plugin('example.com')->beforeCreate($this->subject, $claims, $this->googlePreset);

        self::assertSame([$claims, $this->googlePreset], $result);
    }

    public function testHostedDomainMatchIsCaseInsensitive(): void
    {
        $claims = ['sub' => 'u1', 'hd' => 'Example.COM'];
        $result = $this->plugin('example.com')->beforeCreate($this->subject, $claims, $this->googlePreset);

        self::assertSame([$claims, $this->googlePreset], $result);
    }

    public function testMismatchedHostedDomainIsRejected(): void
    {
        $this->expectException(LocalizedException::class);

        $this->plugin('example.com')->beforeCreate(
            $this->subject,
            ['sub' => 'u1', 'hd' => 'evil.com'],
            $this->googlePreset
        );
    }

    public function testPersonalAccountWithoutHostedDomainIsRejected(): void
    {
        $this->expectException(LocalizedException::class);

        $this->plugin('example.com')->beforeCreate(
            $this->subject,
            ['sub' => 'u1'],
            $this->googlePreset
        );
    }

    public function testBlankHostedDomainClaimIsRejected(): void
    {
        $this->expectException(LocalizedException::class);

        $this->plugin('example.com')->beforeCreate(
            $this->subject,
            ['sub' => 'u1', 'hd' => '   '],
            $this->googlePreset
        );
    }

    public function testNonScalarHostedDomainClaimIsRejected(): void
    {
        $this->expectException(LocalizedException::class);

        $this->plugin('example.com')->beforeCreate(
            $this->subject,
            ['sub' => 'u1', 'hd' => ['example.com']],
            $this->googlePreset
        );
    }

    public function testNonGooglePresetIsNeverChecked(): void
    {
        $otherPreset = $this->createStub(ProviderPresetInterface::class);

        $claims = ['sub' => 'u1'];
        $result = $this->plugin('example.com')->beforeCreate($this->subject, $claims, $otherPreset);

        self::assertSame([$claims, $otherPreset], $result);
    }

    public function testDiXmlRegistersPluginOnIdentityFactory(): void
    {
        $diXml = dirname(__DIR__, 3) . '/etc/di.xml';
        self::assertFileExists($diXml);

        $dom = new \DOMDocument();
        self::assertTrue($dom->load($diXml));

        $plugin = $this->findPluginOnType($dom, 'DmLab\\SsoCore\\Model\\Oidc\\IdentityFactory');

        self::assertNotNull($plugin, 'EnforceHostedDomain is not registered as a plugin on IdentityFactory.');
        self::assertSame(EnforceHostedDomain::class, ltrim(trim($plugin->getAttribute('type')), '\\'));
    }

    private function findPluginOnType(\DOMDocument $dom, string $typeName): ?\DOMElement
    {
        foreach ($dom->getElementsByTagName('type') as $type) {
            if (ltrim($type->getAttribute('name'), '\\') !== $typeName) {
                continue;
            }
            foreach ($type->getElementsByTagName('plugin') as $plugin) {
                if (ltrim($plugin->getAttribute('type'), '\\') === EnforceHostedDomain::class) {
                    return $plugin;
                }
            }
        }

        return null;
    }
}
