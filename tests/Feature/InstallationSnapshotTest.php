<?php

namespace KernelBridge\LicensingClient\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeApiException;
use KernelBridge\LicensingClient\Http\Controllers\LicenseController;
use KernelBridge\LicensingClient\Http\Middleware\LicenseMiddleware;
use KernelBridge\LicensingClient\KernelBridgeLicensingServiceProvider;
use KernelBridge\LicensingClient\Services\DeploymentProfile;
use KernelBridge\LicensingClient\Services\DeploymentProfileException;
use KernelBridge\LicensingClient\Services\LicenseCacheService;
use KernelBridge\LicensingClient\Services\LicenseVerificationService;
use KernelBridge\LicensingClient\Services\LicensingPolicyService;
use Orchestra\Testbench\TestCase;

final class InstallationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private ?string $testSigningSecretKey = null;

    private ?string $testSigningPublicKey = null;

    protected function getPackageProviders($app): array
    {
        return [KernelBridgeLicensingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('kernelbridge-licensing.product_code', 'TESTPRODUCT');
        $app['config']->set('kernelbridge-licensing.api_token', 'test-api-token');
        $app['config']->set('kernelbridge-licensing.signature_key', 'test-cache-signing-key');
        $app['config']->set('kernelbridge-licensing.offline_grace_hours', 72);
        $app['config']->set('kernelbridge-licensing.routes.enabled', false);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(DeploymentProfile::class)->persist();
    }

    public function test_it_verifies_and_encrypts_a_bound_installation_snapshot(): void
    {
        Carbon::setTestNow('2027-01-01T00:00:00Z');
        $cache = app(LicenseCacheService::class);
        $state = $cache->state();
        $signed = $this->signedSnapshot($state->installation_identifier);

        $stored = $cache->storeInstallationSnapshot($signed, 'installation.secret');

        self::assertSame('installation_bearer', $stored->credential_kind);
        self::assertSame('installation.secret', $stored->encrypted_installation_credential);
        self::assertSame('active', $stored->status);
        self::assertTrue($cache->hasUsableLicense());
        self::assertTrue($cache->hasFeature('feature.test'));
        self::assertNotSame(
            'installation.secret',
            DB::table('kernelbridge_license_states')->where('id', $stored->id)->value('encrypted_installation_credential'),
        );
    }

    public function test_it_accepts_and_preserves_a_valid_signed_framework_extension(): void
    {
        $cache = app(LicenseCacheService::class);
        $signed = $this->signedSnapshot($cache->state()->installation_identifier, [
            'entitlements' => [['key' => 'legacy.only', 'name' => 'Legacy only', 'enabled' => true, 'limit' => null]],
            'entitlement_framework' => [
                'schema_version' => 1,
                'source' => 'direct',
                'catalog_revision' => 2,
                'source_revision' => 4,
                'grants' => [
                    'feature.test' => ['type' => 'capability', 'enabled' => true],
                    'feature.disabled' => ['type' => 'capability', 'enabled' => false],
                    'branches.max' => [
                        'type' => 'limit',
                        'state' => 'finite',
                        'value' => 5,
                        'unit' => 'branches',
                        'scope' => 'business',
                    ],
                ],
            ],
        ]);

        $stored = $cache->storeInstallationSnapshot($signed, 'installation.secret');

        $storedClaims = json_decode($stored->server_signed_entitlements['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('direct', $storedClaims['entitlement_framework']['source']);
        self::assertTrue($cache->hasFeature('feature.test'));
        self::assertSame('granted', $cache->entitlementDecision('feature.test')['status']);
        self::assertSame('absent', $cache->entitlementDecision('legacy.only')['status']);
        self::assertTrue($cache->hasFeature('branches.max'));
        self::assertSame('disabled', $cache->entitlementDecision('feature.disabled')['status']);
        self::assertFalse($cache->hasFeature('feature.disabled'));
        self::assertSame([
            'status' => 'finite',
            'unit' => 'branches',
            'scope' => 'business',
            'value' => 5,
        ], $cache->limitGrant('branches.max'));
        self::assertSame(5, $cache->limit('branches.max'));
    }

    public function test_it_rejects_a_signed_framework_that_does_not_match_its_outer_source_binding(): void
    {
        $cache = app(LicenseCacheService::class);
        $signed = $this->signedSnapshot($cache->state()->installation_identifier, [
            'entitlement_framework' => [
                'schema_version' => 1,
                'source' => 'product_platform',
                'catalog_revision' => 1,
                'source_revision' => 1,
                'grants' => (object) [],
            ],
        ]);

        $this->expectException(KernelBridgeApiException::class);
        $cache->storeInstallationSnapshot($signed);
    }

    public function test_it_accepts_an_empty_grant_object_without_treating_it_as_a_json_list(): void
    {
        $cache = app(LicenseCacheService::class);
        $signed = $this->signedSnapshot($cache->state()->installation_identifier, [
            'entitlement_framework' => [
                'schema_version' => 1,
                'source' => 'direct',
                'catalog_revision' => 1,
                'source_revision' => 1,
                'grants' => (object) [],
            ],
        ]);

        $stored = $cache->storeInstallationSnapshot($signed, 'installation.secret');
        $claims = json_decode($stored->server_signed_entitlements['payload_json'], false, 512, JSON_THROW_ON_ERROR);

        self::assertInstanceOf(\stdClass::class, $claims->entitlement_framework->grants);
        self::assertSame([], get_object_vars($claims->entitlement_framework->grants));
    }

    public function test_legacy_snapshot_accessors_remain_compatible_and_default_deny_missing_grants(): void
    {
        $cache = app(LicenseCacheService::class);
        $cache->storeInstallationSnapshot($this->signedSnapshot($cache->state()->installation_identifier), 'installation.secret');

        self::assertSame('granted', $cache->entitlementDecision('feature.test')['status']);
        self::assertSame('absent', $cache->entitlementDecision('missing.feature')['status']);
        self::assertFalse($cache->hasFeature('missing.feature'));
        self::assertSame(0, $cache->limit('missing.feature'));
        self::assertSame('unlimited', $cache->limitGrant('feature.test')['status']);
    }

    public function test_stale_signed_snapshots_do_not_extend_offline_access(): void
    {
        Carbon::setTestNow('2027-01-10T00:00:00Z');
        $cache = app(LicenseCacheService::class);
        try {
            $cache->storeInstallationSnapshot($this->signedSnapshot(
                $cache->state()->installation_identifier,
                ['issued_at' => '2027-01-01T00:00:00.000Z'],
            ));
            self::fail('A snapshot older than the offline grace window should be rejected.');
        } catch (KernelBridgeApiException $exception) {
            self::assertSame('stale_entitlement_snapshot', $exception->errorCode);
        }

        $installation = $cache->state()->installation_identifier;
        $cache->storeInstallationSnapshot($this->signedSnapshot($installation, [
            'entitlement_framework' => [
                'schema_version' => 1,
                'source' => 'direct',
                'catalog_revision' => 1,
                'source_revision' => 2,
                'grants' => ['feature.test' => ['type' => 'capability', 'enabled' => true]],
            ],
        ]), 'installation.secret');
        try {
            $cache->storeInstallationSnapshot($this->signedSnapshot($installation, [
                'issued_at' => '2027-01-09T00:00:00.000Z',
                'entitlement_framework' => [
                    'schema_version' => 1,
                    'source' => 'direct',
                    'catalog_revision' => 1,
                    'source_revision' => 1,
                    'grants' => (object) [],
                ],
            ]));
            self::fail('An older snapshot must not replace the current cached grants.');
        } catch (KernelBridgeApiException $exception) {
            self::assertSame('stale_entitlement_snapshot', $exception->errorCode);
        }
        self::assertTrue($cache->hasFeature('feature.test'));
    }

    public function test_cached_framework_grants_remain_available_during_a_bounded_kernelbridge_outage(): void
    {
        $cache = app(LicenseCacheService::class);
        $state = $cache->state();
        $cache->storeInstallationSnapshot($this->signedSnapshot($state->installation_identifier, [
            'entitlement_framework' => [
                'schema_version' => 1,
                'source' => 'direct',
                'catalog_revision' => 1,
                'source_revision' => 1,
                'grants' => ['feature.test' => ['type' => 'capability', 'enabled' => true]],
            ],
        ]), 'installation.secret');
        Http::fake(['*' => Http::response([], 503)]);

        app(LicenseVerificationService::class)->verify(force: true);

        self::assertTrue($cache->hasFeature('feature.test'));
    }

    public function test_newer_signed_snapshot_replaces_framework_grants_on_renewal(): void
    {
        Carbon::setTestNow('2027-01-01T00:00:00Z');
        $cache = app(LicenseCacheService::class);
        $installation = $cache->state()->installation_identifier;
        $cache->storeInstallationSnapshot($this->signedSnapshot($installation, [
            'entitlement_framework' => [
                'schema_version' => 1,
                'source' => 'direct',
                'catalog_revision' => 1,
                'source_revision' => 1,
                'grants' => ['feature.old' => ['type' => 'capability', 'enabled' => true]],
            ],
        ]), 'installation.secret');

        Carbon::setTestNow('2027-01-02T00:00:00Z');
        $cache->storeInstallationSnapshot($this->signedSnapshot($installation, [
            'expires_at' => '2031-01-01T00:00:00.000Z',
            'entitlement_framework' => [
                'schema_version' => 1,
                'source' => 'direct',
                'catalog_revision' => 1,
                'source_revision' => 2,
                'grants' => ['feature.new' => ['type' => 'capability', 'enabled' => true]],
            ],
        ]));

        self::assertFalse($cache->hasFeature('feature.old'));
        self::assertTrue($cache->hasFeature('feature.new'));
    }

    public function test_expired_and_revoked_framework_snapshots_default_deny_access(): void
    {
        $cache = app(LicenseCacheService::class);
        $installation = $cache->state()->installation_identifier;
        foreach (['expired', 'revoked'] as $status) {
            $cache->storeInstallationSnapshot($this->signedSnapshot($installation, [
                'license_status' => $status,
                'expires_at' => '2025-01-01T00:00:00.000Z',
                'entitlement_framework' => [
                    'schema_version' => 1,
                    'source' => 'direct',
                    'catalog_revision' => 1,
                    'source_revision' => 1,
                    'grants' => ['feature.test' => ['type' => 'capability', 'enabled' => true]],
                ],
            ]));

            self::assertFalse($cache->hasFeature('feature.test'));
            self::assertSame('license_unavailable', $cache->entitlementDecision('feature.test')['status']);
        }
    }

    public function test_it_rejects_malformed_and_unsupported_signed_framework_extensions(): void
    {
        $cache = app(LicenseCacheService::class);
        foreach ([
            [
                'schema_version' => 2,
                'source' => 'direct',
                'catalog_revision' => 1,
                'source_revision' => 1,
                'grants' => [],
            ],
            [
                'schema_version' => 1,
                'source' => 'direct',
                'catalog_revision' => 1,
                'source_revision' => 1,
                'grants' => ['feature.test' => ['type' => 'capability', 'enabled' => 'yes']],
            ],
        ] as $framework) {
            try {
                $cache->storeInstallationSnapshot($this->signedSnapshot(
                    $cache->state()->installation_identifier,
                    ['entitlement_framework' => $framework],
                ));
                self::fail('Malformed or unsupported signed framework should be rejected.');
            } catch (KernelBridgeApiException $exception) {
                self::assertSame('invalid_entitlement_signature', $exception->errorCode);
            }
        }
    }

    public function test_it_verifies_the_signed_effective_licensing_mode_without_a_product_token(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $payload = json_encode([
            'version' => 1,
            'policy_type' => 'licensing_mode',
            'issued_at' => now()->toIso8601String(),
            'product_code' => 'TESTPRODUCT',
            'licensing_mode' => 'platform_account',
            'licensing_policy_revision' => 3,
            'licensing_policy_effective_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signed = [
            'payload_json' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($keyPair))),
            'algorithm' => 'ed25519',
            'key_id' => 'test-key',
        ];
        config([
            'kernelbridge-licensing.api_token' => null,
            'kernelbridge-licensing.entitlement_public_keys' => [
                'test-key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
            ],
        ]);
        app(DeploymentProfile::class)->persist();
        Http::fake(['*' => Http::response(['success' => true, 'data' => $signed])]);

        $policy = app(LicensingPolicyService::class)->current();

        self::assertSame('platform_account', $policy['licensing_mode']);
        self::assertSame(3, $policy['licensing_policy_revision']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/licensing/products/TESTPRODUCT/policy')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_activation_screen_uses_verified_direct_and_platform_modes(): void
    {
        config([
            'kernelbridge-licensing.api_token' => null,
            'kernelbridge-licensing.portal_urls.platform_activation' => 'https://platform.example.test/account/licensing',
        ]);
        app(DeploymentProfile::class)->persist();
        Route::middleware('web')->get('/license', [LicenseController::class, 'show'])->name('kernelbridge.license.show');
        Route::post('/license', fn () => response()->noContent())->name('kernelbridge.license.activate');
        Route::post('/license/verify', fn () => response()->noContent())->name('kernelbridge.license.verify');
        Route::delete('/license', fn () => response()->noContent())->name('kernelbridge.license.deactivate');

        $keyPair = sodium_crypto_sign_keypair();
        $platformPolicy = $this->signedLicensingPolicy($keyPair, 'platform_account');
        $directPolicy = $this->signedLicensingPolicy($keyPair, 'direct');
        Http::fake(['*' => Http::sequence()
            ->push(['success' => true, 'data' => $platformPolicy])
            ->push(['success' => true, 'data' => $directPolicy])]);
        $platformPage = $this->get('/license')->assertOk();
        $platformPage->assertSee('Continue with your product account')
            ->assertSee('https://platform.example.test/account/licensing')
            ->assertDontSee('KernelBridge installation code');

        $directPage = $this->get('/license')->assertOk();
        $directPage->assertSee('KernelBridge installation code')
            ->assertDontSee('Continue with your product account');
    }

    public function test_it_rejects_an_unknown_signing_key(): void
    {
        $cache = app(LicenseCacheService::class);
        $signed = $this->signedSnapshot($cache->state()->installation_identifier);
        $signed['key_id'] = 'untrusted-key';

        $this->expectException(KernelBridgeApiException::class);
        $cache->storeInstallationSnapshot($signed, 'installation.secret');
    }

    public function test_it_accepts_overlapping_signing_keys_during_key_rotation(): void
    {
        $cache = app(LicenseCacheService::class);
        $state = $cache->state();
        $payloadJson = $this->signedSnapshot($state->installation_identifier)['payload_json'];
        $keys = [];
        $snapshots = [];
        foreach (['old-key', 'new-key'] as $keyId) {
            $keyPair = sodium_crypto_sign_keypair();
            $secretKey = sodium_crypto_sign_secretkey($keyPair);
            $keys[$keyId] = base64_encode(sodium_crypto_sign_publickey($keyPair));
            $snapshots[$keyId] = [
                'algorithm' => 'ed25519',
                'key_id' => $keyId,
                'payload_json' => $payloadJson,
                'signature' => base64_encode(sodium_crypto_sign_detached($payloadJson, $secretKey)),
            ];
        }
        config(['kernelbridge-licensing.entitlement_public_keys' => $keys]);

        $cache->storeInstallationSnapshot($snapshots['old-key'], 'installation.secret');
        $stored = $cache->storeInstallationSnapshot($snapshots['new-key'], 'installation.secret');

        self::assertSame('new-key', $stored->server_signed_entitlements['key_id']);
        self::assertTrue($cache->hasUsableLicense());
    }

    public function test_it_renders_the_reusable_kernelbridge_platform_activation_screen(): void
    {
        $html = view('kernelbridge-licensing::installation', [
            'productName' => 'MarketLens',
            'businessName' => 'Test Business',
            'license' => null,
            'errors' => new ViewErrorBag,
        ])->render();

        self::assertStringContainsString('Connect MarketLens to KernelBridge.', $html);
        self::assertStringContainsString('KernelBridge activation code', $html);
        self::assertStringContainsString('wire:submit="activate"', $html);
        self::assertStringContainsString('wire:model="activationCode"', $html);
        self::assertStringNotContainsString('Licensing platform URL', $html);
    }

    public function test_expired_license_redirects_protected_pages_to_the_code_free_renewal_screen(): void
    {
        Carbon::setTestNow('2027-01-01T00:00:00Z');
        config([
            'kernelbridge-licensing.routes.prefix' => 'license',
            'kernelbridge-licensing.routes.name' => 'kernelbridge.license.',
            'kernelbridge-licensing.redirects.renewal_route' => 'kernelbridge.license.renewal',
            'kernelbridge-licensing.portal_urls.installation_code' => 'https://kernelbridge.test/account/licenses#installations',
            'kernelbridge-licensing.portal_urls.activation_code' => 'https://kernelbridge.test/account/licenses#activation-codes',
            'kernelbridge-licensing.portal_urls.renewal' => 'https://kernelbridge.test/account/licenses#renewal',
        ]);
        $cache = app(LicenseCacheService::class);
        $signed = $this->signedSnapshot($cache->state()->installation_identifier, [
            'expires_at' => '2026-12-31T23:59:59Z',
        ]);
        $cache->storeInstallationSnapshot($signed, 'expired-installation.secret');

        Route::get('/license', fn () => response('activation'))->name('kernelbridge.license.show');
        Route::get('/license/renewal', [LicenseController::class, 'renewal'])->name('kernelbridge.license.renewal');
        Route::get('/protected', fn () => response('protected'))->middleware(LicenseMiddleware::class);

        $this->get('/protected')->assertRedirect(route('kernelbridge.license.renewal'));
        $this->get('/license/renewal')
            ->assertOk()
            ->assertSee('Your TESTPRODUCT license needs attention.')
            ->assertSee('Renew license in KernelBridge')
            ->assertSee('Get an installation code')
            ->assertSee('Get an activation code')
            ->assertSee('/account/licenses#renewal')
            ->assertDontSee('name="activation_code"');
    }

    public function test_renewal_screen_rejects_non_https_portal_urls(): void
    {
        config(['kernelbridge-licensing.portal_urls.renewal' => 'javascript:alert(1)']);

        $this->expectException(\LogicException::class);
        app(LicenseController::class)->renewal(app(LicenseCacheService::class));
    }

    public function test_public_key_map_can_be_loaded_from_deployment_environment(): void
    {
        $keyMap = ['deployment-key' => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES))];
        $environmentValue = json_encode($keyMap, JSON_THROW_ON_ERROR);
        $previousValue = getenv('KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS');
        $previousEnv = $_ENV['KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS'] ?? null;
        $previousServer = $_SERVER['KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS'] ?? null;

        putenv('KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS='.$environmentValue);
        $_ENV['KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS'] = $environmentValue;
        $_SERVER['KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS'] = $environmentValue;

        try {
            $config = require __DIR__.'/../../config/kernelbridge-licensing.php';
        } finally {
            if ($previousValue === false) {
                putenv('KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS');
            } else {
                putenv('KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS='.$previousValue);
            }

            if ($previousEnv === null) {
                unset($_ENV['KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS']);
            } else {
                $_ENV['KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS'] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER['KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS']);
            } else {
                $_SERVER['KERNELBRIDGE_ENTITLEMENT_PUBLIC_KEYS'] = $previousServer;
            }
        }

        self::assertSame($keyMap, $config['entitlement_public_keys']);
    }

    public function test_it_accepts_the_shared_ed25519_protocol_fixture(): void
    {
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        $fixture = json_decode(
            file_get_contents(__DIR__.'/../../../../protocol/fixtures/ed25519-entitlement-v1.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $der = base64_decode(preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\\s/', '', $fixture['public_key_pem']), true);
        self::assertIsString($der);
        $publicKey = substr($der, -SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        config(['kernelbridge-licensing.entitlement_public_keys' => [$fixture['key_id'] => base64_encode($publicKey)]]);

        $cache = app(LicenseCacheService::class);
        $state = $cache->state();
        $state->forceFill(['installation_identifier' => '11111111-1111-4111-8111-111111111111'])->save();
        $stored = $cache->storeInstallationSnapshot([
            'algorithm' => 'ed25519',
            'key_id' => $fixture['key_id'],
            'payload_json' => $fixture['payload_json'],
            'signature' => $fixture['signature'],
        ], 'fixture-installation.secret');

        self::assertSame('active', $stored->status);
        self::assertTrue($cache->hasFeature('feature.test'));
    }

    public function test_it_rejects_a_snapshot_bound_to_another_installation(): void
    {
        $cache = app(LicenseCacheService::class);
        $signed = $this->signedSnapshot('another-installation');

        $this->expectException(KernelBridgeApiException::class);
        $cache->storeInstallationSnapshot($signed, 'installation.secret');
    }

    public function test_it_rejects_a_snapshot_rebound_to_a_different_license(): void
    {
        $cache = app(LicenseCacheService::class);
        $state = $cache->state();
        $cache->storeInstallationSnapshot($this->signedSnapshot($state->installation_identifier), 'installation.secret');
        $changedLicense = $this->signedSnapshot($state->installation_identifier, [
            'license_uuid' => '99999999-9999-4999-8999-999999999999',
        ]);

        $this->expectException(KernelBridgeApiException::class);
        $cache->storeInstallationSnapshot($changedLicense, 'installation.secret');
    }

    public function test_grace_status_uses_signed_grace_expiry_not_elapsed_subscription_expiry(): void
    {
        Carbon::setTestNow('2027-01-01T00:00:00Z');
        $cache = app(LicenseCacheService::class);
        $state = $cache->state();
        $signed = $this->signedSnapshot($state->installation_identifier, [
            'license_status' => 'grace',
            'expires_at' => '2026-12-31T23:59:59Z',
            'grace_expires_at' => '2027-01-03T00:00:00Z',
        ]);

        $cache->storeInstallationSnapshot($signed, 'installation.secret');
        self::assertTrue($cache->hasUsableLicense());

        Carbon::setTestNow('2027-01-04T00:00:00Z');
        self::assertFalse($cache->hasUsableLicense());
        self::assertSame('offline_grace_expired', $cache->activationRequirementReason());
    }

    public function test_grace_status_without_a_signed_grace_expiry_is_rejected(): void
    {
        $cache = app(LicenseCacheService::class);
        $state = $cache->state();
        $signed = $this->signedSnapshot($state->installation_identifier, [
            'license_status' => 'grace',
            'grace_expires_at' => null,
        ]);

        $this->expectException(KernelBridgeApiException::class);
        $cache->storeInstallationSnapshot($signed, 'installation.secret');
    }

    public function test_renewal_notice_uses_configured_threshold_months(): void
    {
        Carbon::setTestNow('2027-01-01T00:00:00Z');
        config(['kernelbridge-licensing.renewal_notice.threshold_months' => [6, 4]]);
        $cache = app(LicenseCacheService::class);
        $signed = $this->signedSnapshot($cache->state()->installation_identifier, [
            'expires_at' => '2027-07-01T00:00:00.000Z',
        ]);
        $cache->storeInstallationSnapshot($signed, 'installation.secret');

        self::assertSame('6_months', $cache->renewalNoticeLevel());
        self::assertStringContainsString('about 6 months', $cache->renewalNoticeMessage());
    }

    public function test_strict_deployment_profile_requires_explicit_initialization(): void
    {
        Carbon::setTestNow('2027-01-01T00:00:00Z');
        \KernelBridge\LicensingClient\Models\DeploymentProfile::query()->delete();
        config(['kernelbridge-licensing.api_token' => null]);
        $profile = app(DeploymentProfile::class);
        $cache = app(LicenseCacheService::class);
        $cache->storeInstallationSnapshot(
            $this->signedSnapshot($cache->state()->installation_identifier),
            'installation.secret',
        );

        try {
            $profile->assertReady();
            self::fail('A strict deployment profile must not initialize itself.');
        } catch (DeploymentProfileException $exception) {
            self::assertSame('deployment_profile_missing', $exception->reason);
        }
        self::assertFalse($cache->hasUsableLicense());
        self::assertSame('deployment_profile_missing', $cache->activationRequirementReason());

        $this->artisan('kernelbridge:initialize-profile')->assertExitCode(0);
        $profile->assertReady();
        self::assertTrue($cache->hasUsableLicense());
    }

    public function test_compatibility_mode_allows_installation_activation_without_a_platform_token(): void
    {
        \KernelBridge\LicensingClient\Models\DeploymentProfile::query()->delete();
        config([
            'kernelbridge-licensing.deployment.strict_profile' => false,
            'kernelbridge-licensing.api_token' => null,
        ]);

        app(DeploymentProfile::class)->assertReady();
        self::assertFalse(DB::table('kernelbridge_deployment_profiles')->where('product_code', 'TESTPRODUCT')->exists());
    }

    public function test_compatibility_mode_still_rejects_a_changed_existing_profile(): void
    {
        config(['kernelbridge-licensing.deployment.strict_profile' => false]);
        $profile = app(DeploymentProfile::class);
        $profile->persist();
        config(['kernelbridge-licensing.api_url' => 'https://changed.kernelbridge.test/api/v1']);

        try {
            $profile->assertReady();
            self::fail('An existing deployment profile must always match the current configuration.');
        } catch (DeploymentProfileException $exception) {
            self::assertSame('configuration_tampered', $exception->reason);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{algorithm: string, key_id: string, payload_json: string, signature: string}
     */
    private function signedSnapshot(string $installationUuid, array $overrides = []): array
    {
        if ($this->testSigningSecretKey === null || $this->testSigningPublicKey === null) {
            $keyPair = sodium_crypto_sign_keypair();
            $this->testSigningSecretKey = sodium_crypto_sign_secretkey($keyPair);
            $this->testSigningPublicKey = sodium_crypto_sign_publickey($keyPair);
        }
        config(['kernelbridge-licensing.entitlement_public_keys' => ['test-key' => base64_encode($this->testSigningPublicKey)]]);

        $claims = array_replace([
            'version' => 1,
            'issued_at' => now()->toIso8601String(),
            'product_code' => 'TESTPRODUCT',
            'licensing_mode' => 'direct',
            'licensing_policy_revision' => 1,
            'licensing_policy_effective_at' => null,
            'installation_uuid' => $installationUuid,
            'platform_account_link_uuid' => null,
            'business_uuid' => null,
            'license_uuid' => '22222222-2222-4222-8222-222222222222',
            'license_status' => 'active',
            'subscription_uuid' => '33333333-3333-4333-8333-333333333333',
            'plan' => ['uuid' => '44444444-4444-4444-8444-444444444444', 'name' => 'Fixture'],
            'expires_at' => '2030-01-01T00:00:00.000Z',
            'grace_expires_at' => null,
            'entitlements' => [['key' => 'feature.test', 'name' => 'Test feature', 'enabled' => true, 'limit' => null]],
            'entitlement_revision' => 1,
            'entitlement_digest' => 'test',
        ], $overrides);
        $payloadJson = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'algorithm' => 'ed25519',
            'key_id' => 'test-key',
            'payload_json' => $payloadJson,
            'signature' => base64_encode(sodium_crypto_sign_detached($payloadJson, $this->testSigningSecretKey)),
        ];
    }

    /** @return array{payload_json: string, signature: string, algorithm: string, key_id: string} */
    private function signedLicensingPolicy(string $keyPair, string $mode): array
    {
        $payload = json_encode([
            'version' => 1,
            'policy_type' => 'licensing_mode',
            'issued_at' => now()->toIso8601String(),
            'product_code' => 'TESTPRODUCT',
            'licensing_mode' => $mode,
            'licensing_policy_revision' => 3,
            'licensing_policy_effective_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        config(['kernelbridge-licensing.entitlement_public_keys' => [
            'test-key' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
        ]]);

        return [
            'payload_json' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($keyPair))),
            'algorithm' => 'ed25519',
            'key_id' => 'test-key',
        ];
    }
}
