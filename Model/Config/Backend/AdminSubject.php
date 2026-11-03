<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Normalizes the impersonated Workspace admin to a lowercase email on save.
 */
class AdminSubject extends Value
{
    /**
     * @inheritdoc
     *
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = strtolower(trim((string)$this->getValue()));

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new LocalizedException(__('"%1" is not a valid email address.', $value));
        }

        $this->setValue($value);

        return parent::beforeSave();
    }
}
