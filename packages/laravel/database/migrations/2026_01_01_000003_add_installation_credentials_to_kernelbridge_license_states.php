<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kernelbridge_license_states', function (Blueprint $table): void {
            $table->string('credential_kind')->nullable()->after('activation_identifier');
            $table->longText('encrypted_installation_credential')->nullable()->after('encrypted_license_key');
            $table->longText('server_signed_entitlements')->nullable()->after('entitlement_signature');
            $table->unsignedBigInteger('entitlement_revision')->nullable()->after('server_signed_entitlements');
        });
    }

    public function down(): void
    {
        Schema::table('kernelbridge_license_states', function (Blueprint $table): void {
            $table->dropColumn([
                'credential_kind',
                'encrypted_installation_credential',
                'server_signed_entitlements',
                'entitlement_revision',
            ]);
        });
    }
};
