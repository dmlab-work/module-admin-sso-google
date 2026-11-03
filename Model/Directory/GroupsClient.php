<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Model\Directory;

use DmLab\AdminSsoGoogle\Exception\DirectoryException;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Lists a user's direct Google Workspace group memberships (Directory API `groups.list?userKey=`).
 */
class GroupsClient
{
    public const GROUPS_URL = 'https://admin.googleapis.com/admin/directory/v1/groups';
    public const PAGE_SIZE = 200;

    /**
     * @param ClientFactory $httpClientFactory
     * @param SerializerInterface $serializer
     * @param int $connectTimeout seconds
     * @param int $timeout seconds
     * @param int $maxPages a user with more pages fails the lookup (groups would be incomplete)
     */
    public function __construct(
        private readonly ClientFactory $httpClientFactory,
        private readonly SerializerInterface $serializer,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 10,
        private readonly int $maxPages = 25
    ) {
    }

    /**
     * Return the user's group emails, lowercased and deduplicated in API order.
     *
     * @param string $accessToken
     * @param string $userKey user email
     * @return string[]
     * @throws DirectoryException
     */
    public function listGroupEmails(string $accessToken, string $userKey): array
    {
        $emails = [];
        $seenTokens = [];
        $pageToken = null;
        $page = 0;

        do {
            if (++$page > $this->maxPages) {
                throw new DirectoryException(sprintf(
                    'User %s has more than %d pages of Google groups; the group list would be incomplete.',
                    $userKey,
                    $this->maxPages
                ));
            }

            $data = $this->fetchPage($accessToken, $userKey, $pageToken);
            foreach ($this->extractEmails($data) as $email) {
                $emails[$email] = true;
            }

            $pageToken = $data['nextPageToken'] ?? null;
            if ($pageToken !== null && !is_string($pageToken)) {
                throw new DirectoryException('Google Directory API returned a non-string "nextPageToken".');
            }
            if ($pageToken === '') {
                $pageToken = null;
            }
            if ($pageToken !== null) {
                if (isset($seenTokens[$pageToken])) {
                    throw new DirectoryException('Google Directory API repeated a "nextPageToken".');
                }
                $seenTokens[$pageToken] = true;
            }
        } while ($pageToken !== null);

        return array_map('strval', array_keys($emails));
    }

    /**
     * Fetch and decode one page.
     *
     * @param string $accessToken
     * @param string $userKey
     * @param string|null $pageToken
     * @return array<string, mixed>
     * @throws DirectoryException
     */
    private function fetchPage(string $accessToken, string $userKey, ?string $pageToken): array
    {
        $query = ['userKey' => $userKey, 'maxResults' => self::PAGE_SIZE];
        if ($pageToken !== null) {
            $query['pageToken'] = $pageToken;
        }

        $httpClient = $this->createHttpClient();
        $httpClient->addHeader('Authorization', 'Bearer ' . $accessToken);
        $httpClient->addHeader('Accept', 'application/json');

        try {
            $httpClient->get(self::GROUPS_URL . '?' . http_build_query($query));
        } catch (\Throwable $e) {
            throw new DirectoryException('Failed to reach the Google Directory API: ' . $e->getMessage(), 0, $e);
        }

        $status = (int)$httpClient->getStatus();
        $data = $this->decode((string)$httpClient->getBody());

        if ($status !== 200) {
            throw new DirectoryException($this->describeError($status, $data ?? []));
        }
        if ($data === null) {
            throw new DirectoryException('Google Directory API response is not a JSON object.');
        }

        return $data;
    }

    /**
     * Collect normalized group emails from one page; non-string entries are skipped.
     *
     * @param array<string,mixed> $data
     * @return string[]
     * @throws DirectoryException
     */
    private function extractEmails(array $data): array
    {
        if (!array_key_exists('groups', $data)) {
            return [];
        }
        if (!is_array($data['groups']) || !array_is_list($data['groups'])) {
            throw new DirectoryException('Google Directory API response "groups" is not a list.');
        }

        $emails = [];
        foreach ($data['groups'] as $group) {
            $email = is_array($group) ? ($group['email'] ?? null) : null;
            if (!is_string($email)) {
                continue;
            }
            $email = strtolower(trim($email));
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * Create a fresh, bounded, HTTPS-only HTTP client.
     *
     * @return ClientInterface
     */
    private function createHttpClient(): ClientInterface
    {
        $client = $this->httpClientFactory->create();
        $client->setTimeout($this->timeout);
        $client->setOption(CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        $client->setOption(CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);

        return $client;
    }

    /**
     * Decode a JSON object body; null when the body is not a JSON object.
     *
     * @param string $body
     * @return array<string, mixed>|null
     */
    private function decode(string $body): ?array
    {
        // `[]` and `{}` both unserialize to an empty array; only the object is a valid response.
        if (!str_starts_with(ltrim($body), '{')) {
            return null;
        }

        try {
            $data = $this->serializer->unserialize($body);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Build an operator message from an unsuccessful Directory API response.
     *
     * @param int $status
     * @param array<string,mixed> $data
     * @return string
     */
    private function describeError(int $status, array $data): string
    {
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $code = isset($error['status']) && is_string($error['status']) ? $error['status'] : 'UNKNOWN';
        $message = isset($error['message']) && is_string($error['message'])
            ? rtrim($error['message'], '.')
            : 'no error message';

        $text = sprintf('Google Directory API returned HTTP %d (%s): %s', $status, $code, $message);
        if ($status === 403) {
            $text .= '; check domain-wide delegation, the scope and the impersonated admin\'s Groups Reader role';
        }

        return $text . '.';
    }
}
