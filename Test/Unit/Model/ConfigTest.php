<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit\Model;

use DmLab\AdminSsoGoogle\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @param array<string,mixed> $values
     */
    private function config(array $values): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(static fn (string $path) => $values[$path] ?? null);

        return new Config($scopeConfig);
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
}
