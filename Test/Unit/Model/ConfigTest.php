<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit\Model;

use DmLab\AdminSsoGoogle\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @param array<string,mixed> $values
     */
    private function config(array $values, ?EncryptorInterface $encryptor = null): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(static fn (string $path) => $values[$path] ?? null);
        $scopeConfig->method('isSetFlag')
            ->willReturnCallback(static fn (string $path) => !empty($values[$path]));

        return new Config($scopeConfig, $encryptor ?? $this->createStub(EncryptorInterface::class));
    }

    public function testGetAllowedDomainTrimsAndTreatsBlankAsNull(): void
    {
        self::assertSame(
            'example.com',
            $this->config([Config::XML_PATH_ALLOWED_DOMAIN => '  example.com '])->getAllowedDomain()
        );
        self::assertNull($this->config([Config::XML_PATH_ALLOWED_DOMAIN => '   '])->getAllowedDomain());
        self::assertNull($this->config([])->getAllowedDomain());
    }

    public function testIsDirectoryGroupsEnabledReadsFlag(): void
    {
        self::assertTrue(
            $this->config([Config::XML_PATH_DIRECTORY_GROUPS_ENABLED => '1'])->isDirectoryGroupsEnabled()
        );
        self::assertFalse(
            $this->config([Config::XML_PATH_DIRECTORY_GROUPS_ENABLED => '0'])->isDirectoryGroupsEnabled()
        );
        self::assertFalse($this->config([])->isDirectoryGroupsEnabled());
    }

    public function testGetServiceAccountKeyDecrypts(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects(self::once())->method('decrypt')->with('0:3:cipher')->willReturn(' {"type":"x"} ');

        self::assertSame(
            '{"type":"x"}',
            $this->config([Config::XML_PATH_SERVICE_ACCOUNT_KEY => '0:3:cipher'], $encryptor)->getServiceAccountKey()
        );
    }

    public function testGetServiceAccountKeyBlankIsNull(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects(self::never())->method('decrypt');

        self::assertNull($this->config([], $encryptor)->getServiceAccountKey());
        self::assertNull(
            $this->config([Config::XML_PATH_SERVICE_ACCOUNT_KEY => '  '], $encryptor)->getServiceAccountKey()
        );
    }

    public function testGetServiceAccountKeyUndecryptableIsNull(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('');

        self::assertNull(
            $this->config([Config::XML_PATH_SERVICE_ACCOUNT_KEY => '0:3:cipher'], $encryptor)->getServiceAccountKey()
        );
    }

    public function testGetDirectoryAdminSubjectTrimsAndTreatsBlankAsNull(): void
    {
        self::assertSame(
            'admin@example.com',
            $this->config([Config::XML_PATH_DIRECTORY_ADMIN_SUBJECT => ' admin@example.com '])
                ->getDirectoryAdminSubject()
        );
        self::assertNull($this->config([Config::XML_PATH_DIRECTORY_ADMIN_SUBJECT => ' '])->getDirectoryAdminSubject());
        self::assertNull($this->config([])->getDirectoryAdminSubject());
    }
}
