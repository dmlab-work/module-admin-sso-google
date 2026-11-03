<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit;

use DmLab\AdminSso\Model\Config as AdminSsoConfig;
use DmLab\AdminSsoGoogle\Model\Config as GoogleConfig;
use DmLab\AdminSsoGoogle\Model\Directory\AccessTokenClient;
use DmLab\AdminSsoGoogle\Model\Directory\GroupResolver;
use DmLab\AdminSsoGoogle\Model\Directory\GroupsClient;
use DmLab\AdminSsoGoogle\Model\Directory\ServiceAccountKeyParser;
use DmLab\AdminSsoGoogle\Model\GooglePreset;
use DmLab\AdminSsoGoogle\Plugin\AddDirectoryGroups;
use DmLab\AdminSsoGoogle\Plugin\EnforceHostedDomain;
use DmLab\AdminSsoGoogle\Test\Unit\Model\Directory\ServiceAccountKeyParserTest;
use DmLab\SsoCore\Api\Data\IdentityInterface;
use DmLab\SsoCore\Model\Mapping\MappingEngine;
use DmLab\SsoCore\Model\Oidc\IdentityFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Google login with group mapping on: both IdentityFactory plugins in Magento order, mocked Google HTTP,
 * and the resulting groups through admin-sso's group → role map.
 */
class DirectoryGroupsFlowTest extends TestCase
{
    private const CLAIMS = [
        'sub' => '1234567890',
        'iss' => 'https://accounts.google.com',
        'email' => 'jane@example.com',
        'email_verified' => true,
        'hd' => 'example.com',
    ];

    /** @var list<array{method: string, url: string}> */
    private array $requests = [];

    /**
     * @param list<array{0: int, 1: string}> $responses
     */
    private function httpFactory(array $responses): ClientFactory
    {
        $this->requests = [];
        $factory = $this->createStub(ClientFactory::class);
        $factory->method('create')->willReturnCallback(function () use (&$responses): ClientInterface {
            $response = array_shift($responses);
            self::assertNotNull($response, 'Unexpected extra HTTP request.');
            $http = $this->createStub(ClientInterface::class);
            $http->method('post')->willReturnCallback(function ($url): void {
                $this->requests[] = ['method' => 'POST', 'url' => $url];
            });
            $http->method('get')->willReturnCallback(function ($url): void {
                $this->requests[] = ['method' => 'GET', 'url' => $url];
            });
            $http->method('getStatus')->willReturn($response[0]);
            $http->method('getBody')->willReturn($response[1]);

            return $http;
        });

        return $factory;
    }

    private function scopeConfig(): ScopeConfigInterface
    {
        $values = [
            GoogleConfig::XML_PATH_ALLOWED_DOMAIN => 'example.com',
            GoogleConfig::XML_PATH_DIRECTORY_GROUPS_ENABLED => '1',
            GoogleConfig::XML_PATH_SERVICE_ACCOUNT_KEY => 'encrypted-key',
            GoogleConfig::XML_PATH_DIRECTORY_ADMIN_SUBJECT => 'groups-reader@example.com',
            AdminSsoConfig::XML_PATH_GROUP_ROLE_MAP => "admins@example.com=1\nengineering@example.com=3",
            AdminSsoConfig::XML_PATH_DEFAULT_ROLE => '7',
        ];
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $path) => $values[$path] ?? null);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => (bool)($values[$path] ?? false)
        );

        return $scopeConfig;
    }

    private function encryptor(): EncryptorInterface
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(
            static fn (string $value): string =>
                $value === 'encrypted-key' ? ServiceAccountKeyParserTest::keyJson() : ''
        );

        return $encryptor;
    }

    /**
     * Magento interception order: before-plugins, the original method, after-plugins.
     *
     * @param array<string,mixed> $claims
     */
    private function login(
        array $claims,
        ClientFactory $httpFactory,
        ?LoggerInterface $logger = null
    ): IdentityInterface {
        return $this->pipeline($httpFactory, $logger)($claims);
    }

    /**
     * @return \Closure(array<string,mixed>): IdentityInterface
     */
    private function pipeline(ClientFactory $httpFactory, ?LoggerInterface $logger = null): \Closure
    {
        $config = new GoogleConfig($this->scopeConfig(), $this->encryptor());
        $preset = new GooglePreset($this->createStub(AssetRepository::class), $config);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_800_000_000);
        $json = new Json();
        $resolver = new GroupResolver(
            $config,
            new ServiceAccountKeyParser($json),
            new AccessTokenClient($httpFactory, $json, $dateTime),
            new GroupsClient($httpFactory, $json)
        );
        $before = new EnforceHostedDomain($config);
        $after = new AddDirectoryGroups($config, $resolver, $logger ?? $this->createStub(LoggerInterface::class));
        $factory = new IdentityFactory();

        return static function (array $claims) use ($before, $after, $factory, $preset): IdentityInterface {
            [$claims, $googlePreset] = $before->beforeCreate($factory, $claims, $preset);

            return $after->afterCreate($factory, $factory->create($claims, $googlePreset), $claims, $googlePreset);
        };
    }

    /**
     * @param string[] $groups
     * @return string[]
     */
    private function roles(array $groups): array
    {
        $adminConfig = new AdminSsoConfig($this->scopeConfig(), $this->createStub(EncryptorInterface::class));

        return (new MappingEngine())->resolve(
            $groups,
            $adminConfig->getGroupRoleMap(),
            $adminConfig->getDefaultRoleId()
        );
    }

    private static function page(array $emails, ?string $next = null): array
    {
        $data = ['groups' => array_map(static fn (string $email): array => ['email' => $email], $emails)];
        if ($next !== null) {
            $data['nextPageToken'] = $next;
        }

        return [200, (string)json_encode($data)];
    }

    public function testDirectoryGroupMapsToRole(): void
    {
        $identity = $this->login(self::CLAIMS, $this->httpFactory([
            [200, '{"access_token":"ya29.token","expires_in":3599,"token_type":"Bearer"}'],
            self::page(['all@example.com'], 'page-2'),
            self::page(['Engineering@example.com']),
        ]));

        self::assertSame(['all@example.com', 'engineering@example.com'], $identity->getGroups());
        self::assertSame(['3'], $this->roles($identity->getGroups()));
        self::assertSame(
            [
                ['method' => 'POST', 'url' => AccessTokenClient::TOKEN_URL],
                ['method' => 'GET', 'url' => GroupsClient::GROUPS_URL . '?userKey=jane%40example.com&maxResults=200'],
                [
                    'method' => 'GET',
                    'url' => GroupsClient::GROUPS_URL
                        . '?userKey=jane%40example.com&maxResults=200&pageToken=page-2',
                ],
            ],
            $this->requests
        );
    }

    public function testNothingIsCachedBetweenLogins(): void
    {
        $login = $this->pipeline($this->httpFactory([
            [200, '{"access_token":"ya29.first"}'],
            self::page(['engineering@example.com']),
            [200, '{"access_token":"ya29.second"}'],
            self::page([]),
        ]));

        self::assertSame(['engineering@example.com'], $login(self::CLAIMS)->getGroups());
        self::assertSame([], $login(self::CLAIMS)->getGroups());
        self::assertSame(
            ['POST', 'GET', 'POST', 'GET'],
            array_column($this->requests, 'method')
        );
    }

    public function testForbiddenRefusesTheLoginWithoutDefaultRoleFallback(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $httpFactory = $this->httpFactory([
            [200, '{"access_token":"ya29.token"}'],
            [403, '{"error":{"code":403,"message":"Not Authorized to access this resource/api",'
                . '"status":"PERMISSION_DENIED"}}'],
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Could not read your Google Workspace groups');
        $this->login(self::CLAIMS, $httpFactory, $logger);
    }

    public function testHostedDomainMismatchNeverReachesGoogle(): void
    {
        $httpFactory = $this->httpFactory([]);

        try {
            $this->login(['hd' => 'other.com'] + self::CLAIMS, $httpFactory);
            self::fail('Expected LocalizedException.');
        } catch (LocalizedException $e) {
            self::assertSame('Your Google account is not permitted to sign in to this admin panel.', $e->getMessage());
        }

        self::assertSame([], $this->requests);
    }
}
