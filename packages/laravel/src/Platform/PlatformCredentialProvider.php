<?php

namespace KernelBridge\LicensingClient\Platform;

interface PlatformCredentialProvider
{
    public function credential(): string;
}
