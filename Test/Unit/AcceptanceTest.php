<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit;

use DmLab\AdminSso\Model\ActiveProviderResolver;
use DmLab\AdminSso\Model\Config as AdminSsoConfig;
use DmLab\AdminSso\Model\Config\Source\ActiveProvider;
use DmLab\AdminSso\Model\Oidc\AuthorizationStarter;
use DmLab\AdminSso\Model\PresetRegistry;
use DmLab\AdminSsoGoogle\Model\GooglePreset;
use DmLab\SsoCore\Api\AuthorizationStateStorageInterface;
use DmLab\SsoCore\Model\Oidc\AuthorizationRequestFactory;
use DmLab\SsoCore\Model\Oidc\DiscoveryClient;
use DmLab\SsoCore\Model\Oidc\ProviderMetadata;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance test for the module's contract with `admin-sso`: with the Google
 * preset registered (as di.xml declares) into the capability core, `google`
 * shows up in the provider dropdown and drives the OIDC authorization URL
 * end-to-end.
 *
 * Discovery and token exchange are stubbed — this proves the seam (registry →
 * dropdown, registry → resolver → discovery URL → auth request), not the network.
 */
class AcceptanceTest extends TestCase
{
    /** Google's fixed, tenant-independent OIDC discovery document. */
    private const DISCOVERY_URL = 'https://accounts.google.com/.well-known/openid-configuration';

    /** OIDC client id issued by the IdP. */
    private const CLIENT_ID = 'example.apps.googleusercontent.com';

    /** Authorization endpoint the stubbed discovery document advertises. */
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    /** Admin callback URL registered as the redirect URI. */
    private const CALLBACK_URL = 'https://magento.loc/admin/adminsso/sso/callback';

    public function testGoogleAppearsInProviderDropdown(): void
    {
        $source = new ActiveProvider($this->registry());

        $options = $source->toOptionArray();
        $google = $this->optionByValue($options, 'google');

        self::assertNotNull($google, 'The "google" option is missing from the provider dropdown.');
        self::assertSame('Google Workspace', (string)$google['label']);
    }

    public function testGoogleDrivesTheAuthorizationUrl(): void
    {
        $registry = $this->registry();
        $capturedDiscoveryUrl = null;

        $discoveryClient = $this->createStub(DiscoveryClient::class);
        $discoveryClient->method('discover')->willReturnCallback(
            function (string $url) use (&$capturedDiscoveryUrl): ProviderMetadata {
                $capturedDiscoveryUrl = $url;

                return new ProviderMetadata(
                    'https://accounts.google.com',
                    self::AUTH_ENDPOINT,
                    'https://oauth2.googleapis.com/token',
                    'https://www.googleapis.com/oauth2/v3/certs'
                );
            }
        );

        $starter = new AuthorizationStarter(
            $this->adminConfig(),
            new ActiveProviderResolver($this->activeProviderScopeConfig(), $registry),
            $discoveryClient,
            new AuthorizationRequestFactory($this->fixedRandom()),
            $this->createStub(AuthorizationStateStorageInterface::class),
            $this->backendUrl()
        );

        $url = $starter->start();

        // The Google preset resolved by the registry built the fixed discovery URL.
        self::assertSame(self::DISCOVERY_URL, $capturedDiscoveryUrl);

        // The auth URL is anchored on the discovered endpoint and carries the
        // Google default scopes (no groups — Google emits none) and client id.
        self::assertStringStartsWith(self::AUTH_ENDPOINT . '?', $url);
        self::assertStringContainsString('client_id=' . rawurlencode(self::CLIENT_ID), $url);
        self::assertStringContainsString('scope=openid%20profile%20email', $url);
        self::assertStringNotContainsString('groups', $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
        self::assertStringContainsString('redirect_uri=' . rawurlencode(self::CALLBACK_URL), $url);
    }

    /**
     * PresetRegistry seeded with the Google preset exactly as etc/di.xml wires it.
     */
    private function registry(): PresetRegistry
    {
        $assetRepository = $this->createStub(AssetRepository::class);
        $assetRepository->method('getUrl')->willReturn('https://magento.loc/static/google.svg');

        return new PresetRegistry(['google' => new GooglePreset($assetRepository)]);
    }

    /**
     * admin-sso config stub: SSO enabled with a configured client id.
     */
    private function adminConfig(): AdminSsoConfig
    {
        $config = $this->createStub(AdminSsoConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getClientId')->willReturn(self::CLIENT_ID);

        return $config;
    }

    /**
     * Scope-config stub selecting `google` as the active provider.
     */
    private function activeProviderScopeConfig(): ScopeConfigInterface
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string =>
                $path === ActiveProviderResolver::XML_PATH_ACTIVE_PROVIDER ? 'google' : null
        );

        return $scopeConfig;
    }

    /**
     * Random stub returning fixed bytes so the auth request is deterministic.
     */
    private function fixedRandom(): Random
    {
        $random = $this->createStub(Random::class);
        $random->method('getRandomBytes')->willReturn(str_repeat("\x01", 32));

        return $random;
    }

    /**
     * Backend-URL stub returning the fixed admin callback URL.
     */
    private function backendUrl(): BackendUrlInterface
    {
        $backendUrl = $this->createStub(BackendUrlInterface::class);
        $backendUrl->method('getUrl')->willReturn(self::CALLBACK_URL);

        return $backendUrl;
    }

    /**
     * Find a dropdown option by its value.
     *
     * @param array<int,array{value:mixed,label:mixed}> $options
     * @param string $value
     * @return array{value:mixed,label:mixed}|null
     */
    private function optionByValue(array $options, string $value): ?array
    {
        foreach ($options as $option) {
            if (($option['value'] ?? null) === $value) {
                return $option;
            }
        }

        return null;
    }
}
