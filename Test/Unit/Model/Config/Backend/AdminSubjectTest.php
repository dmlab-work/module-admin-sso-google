<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Test\Unit\Model\Config\Backend;

use DmLab\AdminSsoGoogle\Model\Config\Backend\AdminSubject;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminSubjectTest extends TestCase
{
    private function model(string $value): AdminSubject
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));

        $model = new AdminSubject(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class)
        );
        $model->setValue($value);

        return $model;
    }

    public function testValidEmailIsTrimmedAndLowercased(): void
    {
        $model = $this->model('  Groups.Reader@Example.COM ');
        $model->beforeSave();

        self::assertSame('groups.reader@example.com', $model->getValue());
    }

    public function testBlankSavesEmptyString(): void
    {
        $model = $this->model('   ');
        $model->beforeSave();

        self::assertSame('', $model->getValue());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEmails(): array
    {
        return [
            'no at' => ['admin.example.com'],
            'no domain' => ['admin@'],
            'spaces' => ['ad min@example.com'],
        ];
    }

    #[DataProvider('invalidEmails')]
    public function testInvalidEmailIsRejected(string $value): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('is not a valid email address.');

        $this->model($value)->beforeSave();
    }
}
