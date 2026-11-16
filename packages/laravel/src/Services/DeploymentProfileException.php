<?php

namespace KernelBridge\LicensingClient\Services;

use LogicException;

final class DeploymentProfileException extends LogicException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
