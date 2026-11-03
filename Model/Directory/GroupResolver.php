<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Model\Directory;

use DmLab\AdminSsoGoogle\Exception\DirectoryException;
use DmLab\AdminSsoGoogle\Model\Config;

/**
 * Reads a user's Workspace group emails: service account token for the configured admin, then `groups.list`.
 */
class GroupResolver
{
    private const NOT_CONFIGURED = 'Google group lookup is enabled but the service account key or admin subject '
        . 'is not configured/valid.';

    /**
     * @param Config $config
     * @param ServiceAccountKeyParser $keyParser
     * @param AccessTokenClient $accessTokenClient
     * @param GroupsClient $groupsClient
     */
    public function __construct(
        private readonly Config $config,
        private readonly ServiceAccountKeyParser $keyParser,
        private readonly AccessTokenClient $accessTokenClient,
        private readonly GroupsClient $groupsClient
    ) {
    }

    /**
     * Group emails of the user, lowercased and deduplicated.
     *
     * @param string $email
     * @return string[]
     * @throws DirectoryException
     */
    public function resolve(string $email): array
    {
        $json = $this->config->getServiceAccountKey();
        $subject = $this->config->getDirectoryAdminSubject();
        if ($json === null || $subject === null) {
            throw new DirectoryException(self::NOT_CONFIGURED);
        }

        try {
            $key = $this->keyParser->parse($json);
        } catch (\InvalidArgumentException $e) {
            throw new DirectoryException(self::NOT_CONFIGURED . ' ' . $e->getMessage(), 0, $e);
        }

        return $this->groupsClient->listGroupEmails($this->accessTokenClient->fetch($key, $subject), $email);
    }
}
