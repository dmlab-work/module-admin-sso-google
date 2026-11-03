<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit\Model\Directory;

use DmLab\AdminSsoGoogle\Model\Directory\ServiceAccountKeyParser;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ServiceAccountKeyParserTest extends TestCase
{
    /** @var string|null Generated once per run; 2048-bit generation is slow. */
    private static ?string $rsaPem = null;

    public static function rsaPem(): string
    {
        if (self::$rsaPem === null) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
            self::assertNotFalse($key);
            openssl_pkey_export($key, $pem);
            self::$rsaPem = $pem;
        }

        return self::$rsaPem;
    }

    private static function ecPem(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    /**
     * @param array<string, mixed> $override
     */
    public static function keyJson(array $override = []): string
    {
        $data = array_merge([
            'type' => 'service_account',
            'project_id' => 'demo',
            'private_key_id' => 'abc123',
            'private_key' => self::rsaPem(),
            'client_email' => 'sso-groups@demo.iam.gserviceaccount.com',
            'client_id' => '1234567890',
            'token_uri' => 'https://evil.example.com/token',
        ], $override);

        return (string)json_encode(array_filter($data, static fn($v) => $v !== null));
    }

    private function parser(): ServiceAccountKeyParser
    {
        return new ServiceAccountKeyParser(new Json());
    }

    public function testValidKeyIsParsed(): void
    {
        $key = $this->parser()->parse(self::keyJson());

        self::assertSame('sso-groups@demo.iam.gserviceaccount.com', $key->getClientEmail());
        self::assertSame(self::rsaPem(), $key->getPrivateKey());
        self::assertSame('abc123', $key->getPrivateKeyId());
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function keyIds(): array
    {
        return [
            'missing' => [null, null],
            'blank' => ['   ', null],
            'padded' => [' abc123 ', 'abc123'],
        ];
    }

    #[DataProvider('keyIds')]
    public function testKeyIdIsOptionalAndTrimmed(?string $keyId, ?string $expected): void
    {
        $key = $this->parser()->parse(self::keyJson(['private_key_id' => $keyId]));

        self::assertSame($expected, $key->getPrivateKeyId());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidKeys(): array
    {
        $garbagePem = "-----BEGIN PRIVATE KEY-----\nZ2FyYmFnZQ==\n-----END PRIVATE KEY-----\n";
        $notObject = 'The service account key is not a JSON object.';
        $badRsa = 'The service account private_key is not a valid RSA private key.';

        return [
            'non-JSON' => ['{not json', $notObject],
            'JSON array' => ['["service_account"]', $notObject],
            'JSON scalar' => ['"service_account"', $notObject],
            'empty object' => ['{}', 'The key is not a service account key (type must be "service_account").'],
            'wrong type' => [
                self::keyJson(['type' => 'authorized_user']),
                'The key is not a service account key (type must be "service_account").',
            ],
            'missing client_email' => [
                self::keyJson(['client_email' => null]),
                'The service account key has no valid client_email.',
            ],
            'invalid client_email' => [
                self::keyJson(['client_email' => 'not-an-email']),
                'The service account key has no valid client_email.',
            ],
            'missing private_key' => [
                self::keyJson(['private_key' => null]),
                'The service account key has no private_key.',
            ],
            'non-string private_key' => [
                self::keyJson(['private_key' => ['x']]),
                'The service account key has no private_key.',
            ],
            'garbage PEM' => [self::keyJson(['private_key' => $garbagePem]), $badRsa],
            'EC key' => [self::keyJson(['private_key' => self::ecPem()]), $badRsa],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeyIsRejected(string $json, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->parser()->parse($json);
    }

    public function testRsaPublicKeyIsRejected(): void
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::rsaPem()));
        self::assertIsArray($details);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The service account private_key is not a valid RSA private key.');

        $this->parser()->parse(self::keyJson(['private_key' => $details['key']]));
    }
}
