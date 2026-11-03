<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit\Model\Directory;

use DmLab\AdminSsoGoogle\Exception\DirectoryException;
use DmLab\AdminSsoGoogle\Model\Directory\GroupsClient;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GroupsClientTest extends TestCase
{
    private const TOKEN = 'ya29.token';
    private const USER = 'jane@example.com';
    private const URL = 'https://admin.googleapis.com/admin/directory/v1/groups';

    /** @var list<array<string, mixed>> one entry per HTTP client created */
    private array $requests = [];

    /**
     * @param list<array{0: int, 1: string}|\Throwable> $responses status + body per page, or a transport error
     */
    private function client(array $responses, int $maxPages = 25): GroupsClient
    {
        $this->requests = [];
        $factory = $this->createStub(ClientFactory::class);
        $factory->method('create')->willReturnCallback(function () use (&$responses): ClientInterface {
            $response = array_shift($responses);
            self::assertNotNull($response, 'Unexpected extra Directory API request.');
            $index = count($this->requests);
            $this->requests[$index] = ['timeout' => null, 'options' => [], 'headers' => [], 'url' => null];

            $http = $this->createStub(ClientInterface::class);
            $http->method('setTimeout')->willReturnCallback(function ($value) use ($index): void {
                $this->requests[$index]['timeout'] = $value;
            });
            $http->method('setOption')->willReturnCallback(function ($key, $value) use ($index): void {
                $this->requests[$index]['options'][$key] = $value;
            });
            $http->method('addHeader')->willReturnCallback(function ($name, $value) use ($index): void {
                $this->requests[$index]['headers'][$name] = $value;
            });
            $http->method('get')->willReturnCallback(function ($uri) use ($index, $response): void {
                $this->requests[$index]['url'] = $uri;
                if ($response instanceof \Throwable) {
                    throw $response;
                }
            });
            if (is_array($response)) {
                $http->method('getStatus')->willReturn($response[0]);
                $http->method('getBody')->willReturn($response[1]);
            }

            return $http;
        });

        return new GroupsClient($factory, new Json(), 5, 10, $maxPages);
    }

    /**
     * @param list<string> $emails
     * @return array{0: int, 1: string}
     */
    private static function page(array $emails, ?string $next = null): array
    {
        $data = ['kind' => 'admin#directory#groups', 'groups' => array_map(
            static fn (string $email): array => ['kind' => 'admin#directory#group', 'email' => $email],
            $emails
        )];
        if ($next !== null) {
            $data['nextPageToken'] = $next;
        }

        return [200, (string)json_encode($data)];
    }

    /**
     * @return array<string, string>
     */
    private function query(int $request): array
    {
        $url = (string)$this->requests[$request]['url'];
        self::assertStringStartsWith(self::URL . '?', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function testSinglePage(): void
    {
        $groups = $this->client([self::page(['engineering@example.com', 'ops@example.com'])])
            ->listGroupEmails(self::TOKEN, self::USER);

        self::assertSame(['engineering@example.com', 'ops@example.com'], $groups);
        self::assertCount(1, $this->requests);
        self::assertSame(['userKey' => self::USER, 'maxResults' => '200'], $this->query(0));
    }

    public function testThreePagesJoinedInOrderWithPageTokens(): void
    {
        $groups = $this->client([
            self::page(['a@example.com'], 'token-2'),
            self::page(['b@example.com'], 'token-3'),
            self::page(['c@example.com']),
        ])->listGroupEmails(self::TOKEN, self::USER);

        self::assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $groups);
        self::assertCount(3, $this->requests);
        self::assertArrayNotHasKey('pageToken', $this->query(0));
        self::assertSame('token-2', $this->query(1)['pageToken']);
        self::assertSame('token-3', $this->query(2)['pageToken']);
    }

    public function testEmptyNextPageTokenEndsPagination(): void
    {
        $groups = $this->client([self::page(['a@example.com'], '')])->listGroupEmails(self::TOKEN, self::USER);

        self::assertSame(['a@example.com'], $groups);
    }

    public function testUserWithNoGroups(): void
    {
        $groups = $this->client([[200, '{"kind":"admin#directory#groups","etag":"\"x\""}']])
            ->listGroupEmails(self::TOKEN, self::USER);

        self::assertSame([], $groups);
    }

    public function testEmailsNormalizedDedupedAndNonStringsSkipped(): void
    {
        $body = (string)json_encode(['groups' => [
            ['email' => ' Engineering@Example.COM '],
            ['email' => 42],
            ['name' => 'no email'],
            'not-an-object',
            ['email' => ''],
            ['email' => 'engineering@example.com'],
            ['email' => 'Ops@example.com'],
        ]]);

        $groups = $this->client([[200, $body]])->listGroupEmails(self::TOKEN, self::USER);

        self::assertSame(['engineering@example.com', 'ops@example.com'], $groups);
    }

    public function testDuplicatesAcrossPagesKeepFirstPosition(): void
    {
        $groups = $this->client([
            self::page(['b@example.com', 'a@example.com'], 'next'),
            self::page(['A@example.com', 'c@example.com']),
        ])->listGroupEmails(self::TOKEN, self::USER);

        self::assertSame(['b@example.com', 'a@example.com', 'c@example.com'], $groups);
    }

    public function testQueryIsUrlEncoded(): void
    {
        $this->client([self::page([])])->listGroupEmails(self::TOKEN, 'a+b@example.com');

        self::assertSame(self::URL . '?userKey=a%2Bb%40example.com&maxResults=200', $this->requests[0]['url']);
    }

    public function testBearerHeaderAndBoundedHttpsClientOnEveryPage(): void
    {
        $this->client([self::page(['a@example.com'], 'next'), self::page([])])
            ->listGroupEmails(self::TOKEN, self::USER);

        foreach ($this->requests as $request) {
            self::assertSame('Bearer ya29.token', $request['headers']['Authorization']);
            self::assertSame('application/json', $request['headers']['Accept']);
            self::assertSame(10, $request['timeout']);
            self::assertSame(5, $request['options'][CURLOPT_CONNECTTIMEOUT] ?? null);
            self::assertSame(CURLPROTO_HTTPS, $request['options'][CURLOPT_PROTOCOLS] ?? null);
        }
    }

    public function testPermissionDeniedMentionsDelegation(): void
    {
        $body = (string)json_encode(['error' => [
            'code' => 403,
            'message' => 'Not Authorized to access this resource/api',
            'status' => 'PERMISSION_DENIED',
        ]]);

        try {
            $this->client([[403, $body]])->listGroupEmails(self::TOKEN, self::USER);
            self::fail('Expected DirectoryException.');
        } catch (DirectoryException $e) {
            self::assertSame(
                'Google Directory API returned HTTP 403 (PERMISSION_DENIED): Not Authorized to access this '
                . 'resource/api; check domain-wide delegation, the scope and the impersonated admin\'s Groups '
                . 'Reader role.',
                $e->getMessage()
            );
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function httpErrorProvider(): array
    {
        return [
            '404' => [
                404,
                '{"error":{"code":404,"message":"Resource Not Found: userKey","status":"NOT_FOUND"}}',
                'Google Directory API returned HTTP 404 (NOT_FOUND): Resource Not Found: userKey.',
            ],
            '500' => [
                500,
                '{"error":{"code":500,"message":"Backend Error.","status":"INTERNAL"}}',
                'Google Directory API returned HTTP 500 (INTERNAL): Backend Error.',
            ],
            '502 non-JSON body' => [
                502,
                '<html>Bad Gateway</html>',
                'Google Directory API returned HTTP 502 (UNKNOWN): no error message.',
            ],
        ];
    }

    #[DataProvider('httpErrorProvider')]
    public function testHttpErrors(int $status, string $body, string $message): void
    {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage($message);

        $this->client([[$status, $body]])->listGroupEmails(self::TOKEN, self::USER);
    }

    public function testTransportFailureIsChained(): void
    {
        $cause = new \RuntimeException('Operation timed out after 10001 milliseconds');

        try {
            $this->client([$cause])->listGroupEmails(self::TOKEN, self::USER);
            self::fail('Expected DirectoryException.');
        } catch (DirectoryException $e) {
            self::assertSame(
                'Failed to reach the Google Directory API: Operation timed out after 10001 milliseconds',
                $e->getMessage()
            );
            self::assertSame($cause, $e->getPrevious());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedBodyProvider(): array
    {
        return [
            'invalid JSON' => ['{not json', 'Google Directory API response is not a JSON object.'],
            'JSON list' => ['[1,2]', 'Google Directory API response is not a JSON object.'],
            'empty JSON list' => ['[]', 'Google Directory API response is not a JSON object.'],
            'groups is an object' => [
                '{"groups":{"email":"a@example.com"}}',
                'Google Directory API response "groups" is not a list.',
            ],
            'groups is a string' => [
                '{"groups":"a@example.com"}',
                'Google Directory API response "groups" is not a list.',
            ],
            'non-string page token' => [
                '{"groups":[],"nextPageToken":7}',
                'Google Directory API returned a non-string "nextPageToken".',
            ],
        ];
    }

    #[DataProvider('malformedBodyProvider')]
    public function testMalformedBody(string $body, string $message): void
    {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage($message);

        $this->client([[200, $body]])->listGroupEmails(self::TOKEN, self::USER);
    }

    public function testRepeatedPageTokenFails(): void
    {
        $this->expectException(DirectoryException::class);
        $this->expectExceptionMessage('Google Directory API repeated a "nextPageToken".');

        $this->client([
            self::page(['a@example.com'], 'loop'),
            self::page(['b@example.com'], 'loop'),
        ])->listGroupEmails(self::TOKEN, self::USER);
    }

    public function testPageCapExceededFails(): void
    {
        $client = $this->client([
            self::page(['a@example.com'], 'token-2'),
            self::page(['b@example.com'], 'token-3'),
            self::page(['c@example.com']),
        ], 2);

        try {
            $client->listGroupEmails(self::TOKEN, self::USER);
            self::fail('Expected DirectoryException.');
        } catch (DirectoryException $e) {
            self::assertSame(
                'User jane@example.com has more than 2 pages of Google groups; the group list would be incomplete.',
                $e->getMessage()
            );
            self::assertCount(2, $this->requests);
        }
    }
}
