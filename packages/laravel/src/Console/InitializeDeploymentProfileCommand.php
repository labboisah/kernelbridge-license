<?php

namespace KernelBridge\LicensingClient\Console;

use Illuminate\Console\Command;
use KernelBridge\LicensingClient\Models\DeploymentProfile;
use KernelBridge\LicensingClient\Services\DeploymentProfile as DeploymentProfileService;

final class InitializeDeploymentProfileCommand extends Command
{
    protected $signature = 'kernelbridge:initialize-profile';

    protected $description = 'Initialize the trusted deployment profile for this product.';

    public function handle(DeploymentProfileService $profile): int
    {
        $existing = DeploymentProfile::query()->where('product_code', $profile->productCode())->first();
        if ($existing) {
            $this->error('A deployment profile already exists for this product. Use kernelbridge:reprovision-profile to replace it.');

            return self::FAILURE;
        }

        $profile->assertConfigurationComplete();
        $record = $profile->persist();

        $this->info('Deployment profile initialized for '.$record->product_code.'.');
        $this->line('Fingerprint: '.$record->fingerprint);

        return self::SUCCESS;
    }
}
