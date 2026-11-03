<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Model\Directory;

use DmLab\AdminSsoGoogle\Exception\DirectoryException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Obtains a Directory API access token for an impersonated Workspace admin (OAuth 2.0 JWT bearer grant, RFC 7523).
 */
class AccessTokenClient
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const SCOPE = 'https://www.googleapis.com/auth/admin.directory.group.readonly';
    public const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    public const ASSERTION_TTL = 300;

    /**
     * @param ClientFactory $httpClientFactory
     * @param SerializerInterface $serializer
     * @param DateTime $dateTime
     * @param int $connectTimeout seconds
     * @param int $timeout seconds
     */
    public function __construct(
        private readonly ClientFactory $httpClientFactory,
        private readonly SerializerInterface $serializer,
        private readonly DateTime $dateTime,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 10
    ) {
    }

    /**
     * Exchange a signed assertion for an access token acting as `$subject`.
     *
     * @param ServiceAccountKey $key
     * @param string $subject Workspace user to impersonate
     * @return string
     * @throws DirectoryException
     */
    public function fetch(ServiceAccountKey $key, string $subject): string
    {
        $assertion = $this->buildAssertion($key, $subject);
        $httpClient = $this->createHttpClient();
        $httpClient->addHeader('Accept', 'application/json');

        try {
            $httpClient->post(self::TOKEN_URL, ['grant_type' => self::GRANT_TYPE, 'assertion' => $assertion]);
        } catch (\Throwable $e) {
            throw new DirectoryException('Failed to reach the Google token endpoint: ' . $e->getMessage(), 0, $e);
        }

        $status = (int)$httpClient->getStatus();
        $data = $this->decode((string)$httpClient->getBody());

        if ($status !== 200) {
            throw new DirectoryException($this->describeError($status, $data ?? []));
        }
        if ($data === null) {
            throw new DirectoryException('Google token response is not a JSON object.');
        }
        if (!isset($data['access_token']) || !is_string($data['access_token']) || $data['access_token'] === '') {
            throw new DirectoryException('Google token response is missing "access_token".');
        }

        return $data['access_token'];
    }

    /**
     * Build the RS256 compact JWS assertion.
     *
     * @param ServiceAccountKey $key
     * @param string $subject
     * @return string
     * @throws DirectoryException
     */
    private function buildAssertion(ServiceAccountKey $key, string $subject): string
    {
        $issuedAt = (int)$this->dateTime->gmtTimestamp();
        $claims = [
            'iss' => $key->getClientEmail(),
            'sub' => $subject,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::ASSERTION_TTL,
        ];
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if ($key->getPrivateKeyId() !== null) {
            $header['kid'] = $key->getPrivateKeyId();
        }

        try {
            $jws = (new JWSBuilder(new AlgorithmManager([new RS256()])))
                ->create()
                ->withPayload((string)$this->serializer->serialize($claims))
                ->addSignature(JWKFactory::createFromKey($key->getPrivateKey()), $header)
                ->build();

            return (new CompactSerializer())->serialize($jws, 0);
        } catch (\Throwable $e) {
            throw new DirectoryException('Could not sign the service account assertion.', 0, $e);
        }
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
        try {
            $data = $this->serializer->unserialize($body);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($data) && ($data === [] || !array_is_list($data)) ? $data : null;
    }

    /**
     * Build an operator message from an unsuccessful token response.
     *
     * @param int $status
     * @param array<string,mixed> $data
     * @return string
     */
    private function describeError(int $status, array $data): string
    {
        $error = isset($data['error']) && is_string($data['error']) ? $data['error'] : 'unknown_error';
        $description = isset($data['error_description']) && is_string($data['error_description'])
            ? ': ' . rtrim($data['error_description'], '.')
            : '';

        return sprintf('Google token endpoint returned HTTP %d (%s)%s.', $status, $error, $description);
    }
}
