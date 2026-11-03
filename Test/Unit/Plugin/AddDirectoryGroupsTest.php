<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit\Plugin;

use DmLab\AdminSsoGoogle\Exception\DirectoryException;
use DmLab\AdminSsoGoogle\Model\Config;
use DmLab\AdminSsoGoogle\Model\Directory\DirectoryGroupsIdentity;
use DmLab\AdminSsoGoogle\Model\Directory\GroupResolver;
use DmLab\AdminSsoGoogle\Model\GooglePreset;
use DmLab\AdminSsoGoogle\Plugin\AddDirectoryGroups;
use DmLab\SsoCore\Api\Data\IdentityInterface;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use DmLab\SsoCore\Model\Oidc\IdentityFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AddDirectoryGroupsTest extends TestCase
{
    private const REFUSED = 'Could not read your Google Workspace groups to determine your admin role. '
        . 'Contact your administrator.';

    private function googlePreset(): GooglePreset
    {
        return new GooglePreset($this->createStub(AssetRepository::class), $this->createStub(Config::class));
    }

    private function config(bool $enabled): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isDirectoryGroupsEnabled')->willReturn($enabled);

        return $config;
    }

    private function identity(?string $email = 'jane@example.com'): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('getEmail')->willReturn($email);
        $identity->method('getGroups')->willReturn([]);

        return $identity;
    }

    public function testNonGooglePresetIsUntouched(): void
    {
        $resolver = $this->createMock(GroupResolver::class);
        $resolver->expects(self::never())->method('resolve');
        $identity = $this->identity();

        $result = (new AddDirectoryGroups($this->config(true), $resolver, $this->createStub(LoggerInterface::class)))
            ->afterCreate(
                new IdentityFactory(),
                $identity,
                [],
                $this->createStub(ProviderPresetInterface::class)
            );

        self::assertSame($identity, $result);
    }

    public function testOptionOffIsUntouched(): void
    {
        $resolver = $this->createMock(GroupResolver::class);
        $resolver->expects(self::never())->method('resolve');
        $identity = $this->identity();

        $result = (new AddDirectoryGroups($this->config(false), $resolver, $this->createStub(LoggerInterface::class)))
            ->afterCreate(new IdentityFactory(), $identity, [], $this->googlePreset());

        self::assertSame($identity, $result);
    }

    public function testDirectoryGroupsAreAttached(): void
    {
        $resolver = $this->createMock(GroupResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('jane@example.com')
            ->willReturn(['engineering@example.com']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $result = (new AddDirectoryGroups($this->config(true), $resolver, $logger))
            ->afterCreate(new IdentityFactory(), $this->identity(), [], $this->googlePreset());

        self::assertInstanceOf(DirectoryGroupsIdentity::class, $result);
        self::assertSame(['engineering@example.com'], $result->getGroups());
        self::assertSame('jane@example.com', $result->getEmail());
    }

    public function testLookupFailureRefusesWithGenericMessageAndOneLoggedError(): void
    {
        $resolver = $this->createStub(GroupResolver::class);
        $resolver->method('resolve')->willThrowException(new DirectoryException(
            'Google Directory API returned HTTP 403 (PERMISSION_DENIED): Not Authorized'
        ));
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->willReturnCallback(
            static function (string $message) use (&$logged): void {
                $logged[] = $message;
            }
        );

        try {
            (new AddDirectoryGroups($this->config(true), $resolver, $logger))
                ->afterCreate(new IdentityFactory(), $this->identity(), [], $this->googlePreset());
            self::fail('Expected LocalizedException.');
        } catch (LocalizedException $e) {
            self::assertSame(self::REFUSED, $e->getMessage());
        }

        self::assertSame(
            ['DmLab_AdminSsoGoogle: Google group lookup failed for jane@example.com: '
                . 'Google Directory API returned HTTP 403 (PERMISSION_DENIED): Not Authorized'],
            $logged
        );
    }

    public function testEmailIsTrimmedBeforeLookup(): void
    {
        $resolver = $this->createMock(GroupResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('jane@example.com')->willReturn([]);

        (new AddDirectoryGroups($this->config(true), $resolver, $this->createStub(LoggerInterface::class)))
            ->afterCreate(new IdentityFactory(), $this->identity('  jane@example.com '), [], $this->googlePreset());
    }

    public function testNonDirectoryFailurePropagates(): void
    {
        $resolver = $this->createStub(GroupResolver::class);
        $resolver->method('resolve')->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        (new AddDirectoryGroups($this->config(true), $resolver, $this->createStub(LoggerInterface::class)))
            ->afterCreate(new IdentityFactory(), $this->identity(), [], $this->googlePreset());
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function missingEmails(): array
    {
        return ['null' => [null], 'blank' => ['   ']];
    }

    #[DataProvider('missingEmails')]
    public function testMissingEmailIsRefused(?string $email): void
    {
        $resolver = $this->createMock(GroupResolver::class);
        $resolver->expects(self::never())->method('resolve');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('DmLab_AdminSsoGoogle: Google group lookup failed for (no email): The identity has no email.');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(self::REFUSED);
        (new AddDirectoryGroups($this->config(true), $resolver, $logger))
            ->afterCreate(new IdentityFactory(), $this->identity($email), [], $this->googlePreset());
    }
}
