<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit\Model\Config\Backend;

use DmLab\AdminSsoGoogle\Model\Config\Backend\ServiceAccountKey;
use DmLab\AdminSsoGoogle\Model\Directory\ServiceAccountKeyParser;
use DmLab\AdminSsoGoogle\Test\Unit\Model\Directory\ServiceAccountKeyParserTest;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ServiceAccountKeyTest extends TestCase
{
    /** @var EncryptorInterface&MockObject */
    private EncryptorInterface $encryptor;

    protected function setUp(): void
    {
        $this->encryptor = $this->createMock(EncryptorInterface::class);
    }

    private function model(string $value): ServiceAccountKey
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));

        $model = new ServiceAccountKey(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class),
            $this->encryptor,
            new ServiceAccountKeyParser(new Json())
        );
        $model->setValue($value);

        return $model;
    }

    public function testValidKeyIsTrimmedAndEncrypted(): void
    {
        $json = ServiceAccountKeyParserTest::keyJson();
        $this->encryptor->expects(self::once())->method('encrypt')->with($json)->willReturn('0:3:cipher');

        $model = $this->model("\n " . $json . " \n");
        $model->beforeSave();

        self::assertSame('0:3:cipher', $model->getValue());
        self::assertTrue($model->isSaveAllowed());
    }

    public function testObscuredValueIsNotResaved(): void
    {
        $this->encryptor->expects(self::never())->method('encrypt');

        $model = $this->model('******');
        $model->beforeSave();

        self::assertFalse($model->isSaveAllowed());
    }

    public function testBlankIsAllowed(): void
    {
        $this->encryptor->expects(self::never())->method('encrypt');

        $model = $this->model('  ');
        $model->beforeSave();

        self::assertSame('', $model->getValue());
        self::assertTrue($model->isSaveAllowed());
    }

    public function testInvalidKeyIsRejectedWithoutKeyMaterial(): void
    {
        $this->encryptor->expects(self::never())->method('encrypt');
        $json = ServiceAccountKeyParserTest::keyJson(['type' => 'authorized_user']);

        try {
            $this->model($json)->beforeSave();
            self::fail('LocalizedException expected');
        } catch (LocalizedException $e) {
            self::assertSame(
                'Service Account Key: The key is not a service account key (type must be "service_account").',
                $e->getMessage()
            );
            self::assertInstanceOf(\InvalidArgumentException::class, $e->getPrevious());
            self::assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
            self::assertStringNotContainsString('gserviceaccount', $e->getMessage());
        }
    }

    public function testInvalidRsaKeyMessageHasNoPem(): void
    {
        $this->encryptor->expects(self::never())->method('encrypt');
        $json = ServiceAccountKeyParserTest::keyJson([
            'private_key' => "-----BEGIN PRIVATE KEY-----\nZ2FyYmFnZQ==\n-----END PRIVATE KEY-----\n",
        ]);

        try {
            $this->model($json)->beforeSave();
            self::fail('LocalizedException expected');
        } catch (LocalizedException $e) {
            self::assertSame(
                'Service Account Key: The service account private_key is not a valid RSA private key.',
                $e->getMessage()
            );
            self::assertStringNotContainsString('Z2FyYmFnZQ', $e->getMessage());
        }
    }
}
