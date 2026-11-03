<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit\Model\Directory;

use DmLab\AdminSsoGoogle\Exception\DirectoryException;
use DmLab\AdminSsoGoogle\Model\Config;
use DmLab\AdminSsoGoogle\Model\Directory\AccessTokenClient;
use DmLab\AdminSsoGoogle\Model\Directory\GroupResolver;
use DmLab\AdminSsoGoogle\Model\Directory\GroupsClient;
use DmLab\AdminSsoGoogle\Model\Directory\ServiceAccountKey;
use DmLab\AdminSsoGoogle\Model\Directory\ServiceAccountKeyParser;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GroupResolverTest extends TestCase
{
    private const SUBJECT = 'groups-reader@example.com';
    private const NOT_CONFIGURED = 'Google group lookup is enabled but the service account key or admin subject '
        . 'is not configured/valid.';

    private function config(?string $key, ?string $subject): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getServiceAccountKey')->willReturn($key);
        $config->method('getDirectoryAdminSubject')->willReturn($subject);

        return $config;
    }

    public function testWiresKeySubjectAndEmailThrough(): void
    {
        $tokenClient = $this->createMock(AccessTokenClient::class);
        $tokenClient->expects(self::once())->method('fetch')
            ->with(
                self::callback(static fn (ServiceAccountKey $key): bool =>
                    $key->getClientEmail() === 'sso-groups@demo.iam.gserviceaccount.com'),
                self::SUBJECT
            )
            ->willReturn('ya29.token');
        $groupsClient = $this->createMock(GroupsClient::class);
        $groupsClient->expects(self::once())->method('listGroupEmails')
            ->with('ya29.token', 'jane@example.com')
            ->willReturn(['engineering@example.com']);

        $resolver = new GroupResolver(
            $this->config(ServiceAccountKeyParserTest::keyJson(), self::SUBJECT),
            new ServiceAccountKeyParser(new Json()),
            $tokenClient,
            $groupsClient
        );

        self::assertSame(['engineering@example.com'], $resolver->resolve('jane@example.com'));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function missingConfig(): array
    {
        return [
            'no key' => [null, self::SUBJECT],
            'no subject' => ['{}', null],
            'neither' => [null, null],
        ];
    }

    #[DataProvider('missingConfig')]
    public function testMissingConfigIsRefused(?string $key, ?string $subject): void
    {
        $tokenClient = $this->createMock(AccessTokenClient::class);
        $tokenClient->expects(self::never())->method('fetch');

        $resolver = new GroupResolver(
            $this->config($key, $subject),
            new ServiceAccountKeyParser(new Json()),
            $tokenClient,
            $this->createStub(GroupsClient::class)
        );

        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage(self::NOT_CONFIGURED);
        $resolver->resolve('jane@example.com');
    }

    public function testInvalidKeyIsRefusedWithReasonAndNoKeyMaterial(): void
    {
        $tokenClient = $this->createMock(AccessTokenClient::class);
        $tokenClient->expects(self::never())->method('fetch');
        $json = ServiceAccountKeyParserTest::keyJson(['type' => 'authorized_user']);

        $resolver = new GroupResolver(
            $this->config($json, self::SUBJECT),
            new ServiceAccountKeyParser(new Json()),
            $tokenClient,
            $this->createStub(GroupsClient::class)
        );

        try {
            $resolver->resolve('jane@example.com');
            self::fail('Expected DirectoryException.');
        } catch (DirectoryException $e) {
            self::assertStringStartsWith(self::NOT_CONFIGURED, $e->getMessage());
            self::assertStringContainsString('service_account', $e->getMessage());
            self::assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
        }
    }

    public function testTokenFailurePropagates(): void
    {
        $failure = new DirectoryException('Google token endpoint returned HTTP 400 (invalid_grant).');
        $tokenClient = $this->createStub(AccessTokenClient::class);
        $tokenClient->method('fetch')->willThrowException($failure);
        $groupsClient = $this->createMock(GroupsClient::class);
        $groupsClient->expects(self::never())->method('listGroupEmails');

        $resolver = new GroupResolver(
            $this->config(ServiceAccountKeyParserTest::keyJson(), self::SUBJECT),
            new ServiceAccountKeyParser(new Json()),
            $tokenClient,
            $groupsClient
        );

        try {
            $resolver->resolve('jane@example.com');
            self::fail('Expected DirectoryException.');
        } catch (DirectoryException $e) {
            self::assertSame($failure, $e);
        }
    }

    public function testGroupsFailurePropagates(): void
    {
        $failure = new DirectoryException('Google Directory API returned HTTP 403 (PERMISSION_DENIED): x');
        $tokenClient = $this->createStub(AccessTokenClient::class);
        $tokenClient->method('fetch')->willReturn('ya29.token');
        $groupsClient = $this->createStub(GroupsClient::class);
        $groupsClient->method('listGroupEmails')->willThrowException($failure);

        $resolver = new GroupResolver(
            $this->config(ServiceAccountKeyParserTest::keyJson(), self::SUBJECT),
            new ServiceAccountKeyParser(new Json()),
            $tokenClient,
            $groupsClient
        );

        try {
            $resolver->resolve('jane@example.com');
            self::fail('Expected DirectoryException.');
        } catch (DirectoryException $e) {
            self::assertSame($failure, $e);
        }
    }
}
