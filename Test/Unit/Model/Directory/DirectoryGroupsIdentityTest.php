<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit\Model\Directory;

use DmLab\AdminSsoGoogle\Model\Directory\DirectoryGroupsIdentity;
use DmLab\SsoCore\Api\Data\IdentityInterface;
use PHPUnit\Framework\TestCase;

class DirectoryGroupsIdentityTest extends TestCase
{
    /**
     * @param string[] $groups
     */
    private function inner(array $groups = [], bool $distributed = false): IdentityInterface
    {
        $inner = $this->createStub(IdentityInterface::class);
        $inner->method('getSubjectId')->willReturn('1234567890');
        $inner->method('getIssuer')->willReturn('https://accounts.google.com');
        $inner->method('getEmail')->willReturn('jane@example.com');
        $inner->method('isEmailVerified')->willReturn(true);
        $inner->method('getName')->willReturn('Jane Doe');
        $inner->method('getGroups')->willReturn($groups);
        $inner->method('hasDistributedClaim')->willReturnCallback(static fn (string $name): bool => $name === 'x');
        $inner->method('hasDistributedGroups')->willReturn($distributed);

        return $inner;
    }

    public function testDelegatesEveryMethod(): void
    {
        $identity = new DirectoryGroupsIdentity($this->inner([], true), []);

        self::assertSame('1234567890', $identity->getSubjectId());
        self::assertSame('https://accounts.google.com', $identity->getIssuer());
        self::assertSame('jane@example.com', $identity->getEmail());
        self::assertTrue($identity->isEmailVerified());
        self::assertSame('Jane Doe', $identity->getName());
        self::assertTrue($identity->hasDistributedClaim('x'));
        self::assertFalse($identity->hasDistributedClaim('groups'));
        self::assertTrue($identity->hasDistributedGroups());
        self::assertSame([], $identity->getGroups());
    }

    public function testDirectoryGroupsOnly(): void
    {
        $identity = new DirectoryGroupsIdentity($this->inner(), ['engineering@example.com', 'ops@example.com']);

        self::assertSame(['engineering@example.com', 'ops@example.com'], $identity->getGroups());
    }

    public function testMergesTokenAndDirectoryGroupsDedupedInOrder(): void
    {
        $identity = new DirectoryGroupsIdentity(
            $this->inner(['token-group', 'ops@example.com']),
            ['engineering@example.com', 'ops@example.com', 'token-group', 'qa@example.com']
        );

        self::assertSame(
            ['token-group', 'ops@example.com', 'engineering@example.com', 'qa@example.com'],
            $identity->getGroups()
        );
    }
}
