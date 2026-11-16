<?php

namespace KernelBridge\LicensingClient\Models;

use Illuminate\Database\Eloquent\Model;

final class LicenseState extends Model
{
    protected $table = 'kernelbridge_license_states';

    protected $guarded = [];

    protected $hidden = ['encrypted_license_key', 'encrypted_installation_credential', 'entitlement_payload', 'entitlement_signature', 'server_signed_entitlements', 'config_fingerprint'];

    protected function casts(): array
    {
        return [
            'encrypted_license_key' => 'encrypted',
            'encrypted_installation_credential' => 'encrypted',
            'entitlement_payload' => 'encrypted:array',
            'server_signed_entitlements' => 'encrypted:array',
            'entitlement_revision' => 'integer',
            'last_successful_verification_at' => 'datetime',
            'expires_at' => 'datetime',
            'offline_grace_expires_at' => 'datetime',
            'config_fingerprinted_at' => 'datetime',
        ];
    }
}
