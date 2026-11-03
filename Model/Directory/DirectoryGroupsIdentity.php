<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Model\Directory;

use DmLab\SsoCore\Api\Data\IdentityInterface;

/**
 * Identity decorator adding the user's Workspace groups to the token's groups.
 */
class DirectoryGroupsIdentity implements IdentityInterface
{
    /**
     * @param IdentityInterface $inner
     * @param string[] $groups Directory group emails
     */
    public function __construct(
        private readonly IdentityInterface $inner,
        private readonly array $groups
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getSubjectId(): string
    {
        return $this->inner->getSubjectId();
    }

    /**
     * @inheritDoc
     */
    public function getIssuer(): string
    {
        return $this->inner->getIssuer();
    }

    /**
     * @inheritDoc
     */
    public function getEmail(): ?string
    {
        return $this->inner->getEmail();
    }

    /**
     * @inheritDoc
     */
    public function isEmailVerified(): bool
    {
        return $this->inner->isEmailVerified();
    }

    /**
     * @inheritDoc
     */
    public function getName(): ?string
    {
        return $this->inner->getName();
    }

    /**
     * Token groups followed by Directory groups, deduplicated in order.
     *
     * @return string[]
     */
    public function getGroups(): array
    {
        return array_values(array_unique(array_merge($this->inner->getGroups(), $this->groups)));
    }

    /**
     * @inheritDoc
     */
    public function hasDistributedClaim(string $name): bool
    {
        return $this->inner->hasDistributedClaim($name);
    }

    /**
     * @inheritDoc
     */
    public function hasDistributedGroups(): bool
    {
        return $this->inner->hasDistributedGroups();
    }
}
