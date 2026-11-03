<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit\Model\Directory;

use DmLab\AdminSsoGoogle\Exception\DirectoryException;
use DmLab\AdminSsoGoogle\Model\Directory\AccessTokenClient;
use DmLab\AdminSsoGoogle\Model\Directory\ServiceAccountKey;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AccessTokenClientTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const CLIENT_EMAIL = 'sso-groups@demo.iam.gserviceaccount.com';
    private const SUBJECT = 'groups-reader@example.com';

    private function key(?string $keyId = 'abc123'): ServiceAccountKey
    {
        return new ServiceAccountKey(self::CLIENT_EMAIL, ServiceAccountKeyParserTest::rsaPem(), $keyId);
    }

    private function client(ClientInterface $http): AccessTokenClient
    {
        $factory = $this->createStub(ClientFactory::class);
        $factory->method('create')->willReturn($http);
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        return new AccessTokenClient($factory, new Json(), $dateTime);
    }

    /**
     * @param array<string, mixed>|null $log
     */
    private function recordingHttp(?array &$log, string $body, int $status = 200): ClientInterface
    {
        $log = ['timeout' => null, 'options' => [], 'headers' => [], 'url' => null, 'params' => []];
        $http = $this->createStub(ClientInterface::class);
        $http->method('setTimeout')->willReturnCallback(function ($value) use (&$log): void {
            $log['timeout'] = $value;
        });
        $http->method('setOption')->willReturnCallback(function ($key, $value) use (&$log): void {
            $log['options'][$key] = $value;
        });
        $http->method('addHeader')->willReturnCallback(function ($name, $value) use (&$log): void {
            $log['headers'][$name] = $value;
        });
        $http->method('post')->willReturnCallback(function ($uri, $params) use (&$log): void {
            $log['url'] = $uri;
            $log['params'] = $params;
        });
        $http->method('getStatus')->willReturn($status);
        $http->method('getBody')->willReturn($body);

        return $http;
    }

    private function okBody(): string
    {
        return (string)json_encode(['access_token' => 'ya29.token', 'expires_in' => 3599, 'token_type' => 'Bearer']);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function verifiedAssertion(string $assertion): array
    {
        $jws = (new CompactSerializer())->unserialize($assertion);
        $publicKey = openssl_pkey_get_details(openssl_pkey_get_private(ServiceAccountKeyParserTest::rsaPem()));
        self::assertIsArray($publicKey);
        $verifier = new JWSVerifier(new AlgorithmManager([new RS256()]));
        self::assertTrue($verifier->verifyWithKey($jws, JWKFactory::createFromKey($publicKey['key']), 0));

        return [$jws->getSignature(0)->getProtectedHeader(), json_decode((string)$jws->getPayload(), true)];
    }

    public function testPostsSignedAssertionAndReturnsAccessToken(): void
    {
        $http = $this->recordingHttp($log, $this->okBody());

        $token = $this->client($http)->fetch($this->key(), self::SUBJECT);

        self::assertSame('ya29.token', $token);
        self::assertSame('https://oauth2.googleapis.com/token', $log['url']);
        self::assertSame(['grant_type', 'assertion'], array_keys($log['params']));
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $log['params']['grant_type']);
        self::assertSame('application/json', $log['headers']['Accept']);

        [$header, $claims] = $this->verifiedAssertion($log['params']['assertion']);
        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'abc123'], $header);
        self::assertSame([
            'iss' => self::CLIENT_EMAIL,
            'sub' => self::SUBJECT,
            'scope' => 'https://www.googleapis.com/auth/admin.directory.group.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => self::NOW,
            'exp' => self::NOW + 300,
        ], $claims);
    }

    public function testKidOmittedWithoutPrivateKeyId(): void
    {
        $http = $this->recordingHttp($log, $this->okBody());

        $this->client($http)->fetch($this->key(null), self::SUBJECT);

        [$header] = $this->verifiedAssertion($log['params']['assertion']);
        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);
    }

    public function testHttpClientIsHttpsOnlyAndBounded(): void
    {
        $http = $this->recordingHttp($log, $this->okBody());

        $this->client($http)->fetch($this->key(), self::SUBJECT);

        self::assertSame(10, $log['timeout']);
        self::assertSame(5, $log['options'][CURLOPT_CONNECTTIMEOUT] ?? null);
        self::assertSame(CURLPROTO_HTTPS, $log['options'][CURLOPT_PROTOCOLS] ?? null);
    }

    public function testTransportFailureIsChained(): void
    {
        $http = $this->createStub(ClientInterface::class);
        $cause = new \RuntimeException('Operation timed out after 10001 milliseconds');
        $http->method('post')->willThrowException($cause);

        try {
            $this->client($http)->fetch($this->key(), self::SUBJECT);
            self::fail('Expected DirectoryException');
        } catch (DirectoryException $e) {
            self::assertStringContainsString('Failed to reach the Google token endpoint', $e->getMessage());
            self::assertStringContainsString('timed out', $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
        }
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: string}>
     */
    public static function errorResponseProvider(): array
    {
        return [
            'unauthorized_client' => [
                ['error' => 'unauthorized_client',
                    'error_description' => 'Client is unauthorized to retrieve access tokens using this method.'],
                'Google token endpoint returned HTTP 400 (unauthorized_client): '
                    . 'Client is unauthorized to retrieve access tokens using this method.',
            ],
            'invalid_grant' => [
                ['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'],
                'Google token endpoint returned HTTP 400 (invalid_grant): Invalid JWT Signature.',
            ],
            'non-string error' => [
                ['error' => ['code' => 400], 'error_description' => ['x']],
                'Google token endpoint returned HTTP 400 (unknown_error).',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $body
     */
    #[DataProvider('errorResponseProvider')]
    public function testErrorResponseCarriesCodeButNeverTheAssertion(array $body, string $expected): void
    {
        $http = $this->recordingHttp($log, (string)json_encode($body), 400);

        try {
            $this->client($http)->fetch($this->key(), self::SUBJECT);
            self::fail('Expected DirectoryException');
        } catch (DirectoryException $e) {
            self::assertSame($expected, $e->getMessage());
            self::assertStringNotContainsString($log['params']['assertion'], $e->getMessage());
            self::assertStringNotContainsString(explode('.', $log['params']['assertion'])[2], $e->getMessage());
        }
    }

    public function testErrorWithoutJsonBody(): void
    {
        $http = $this->recordingHttp($log, '<html>Bad Gateway</html>', 502);

        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Google token endpoint returned HTTP 502 (unknown_error).');

        $this->client($http)->fetch($this->key(), self::SUBJECT);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function badBodyProvider(): array
    {
        return [
            'invalid JSON' => ['not json', 'Google token response is not a JSON object.'],
            'JSON array' => ['["ya29.token"]', 'Google token response is not a JSON object.'],
            'JSON scalar' => ['"ya29.token"', 'Google token response is not a JSON object.'],
            'no access_token' => ['{"token_type":"Bearer"}', 'Google token response is missing "access_token".'],
            'empty access_token' => ['{"access_token":""}', 'Google token response is missing "access_token".'],
            'non-string access_token' => ['{"access_token":42}', 'Google token response is missing "access_token".'],
        ];
    }

    #[DataProvider('badBodyProvider')]
    public function testMalformedSuccessBody(string $body, string $message): void
    {
        $http = $this->recordingHttp($log, $body);

        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage($message);

        $this->client($http)->fetch($this->key(), self::SUBJECT);
    }

    public function testUnusableKeyFailsBeforeAnyRequest(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::never())->method('post');

        try {
            $this->client($http)->fetch(new ServiceAccountKey(self::CLIENT_EMAIL, 'garbage'), self::SUBJECT);
            self::fail('Expected DirectoryException');
        } catch (DirectoryException $e) {
            self::assertSame('Could not sign the service account assertion.', $e->getMessage());
        }
    }
}
