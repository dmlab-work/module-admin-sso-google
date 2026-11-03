<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\AdminSsoGoogle\Exception;

/**
 * Google Workspace group lookup failure; the message is operator detail and never carries secrets.
 */
class DirectoryException extends \RuntimeException
{
}
