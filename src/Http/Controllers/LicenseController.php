<?php

namespace KernelBridge\LicensingClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeApiException;
use KernelBridge\LicensingClient\Exceptions\KernelBridgeUnavailableException;
use KernelBridge\LicensingClient\Services\DeploymentProfileException;
use KernelBridge\LicensingClient\Services\LicenseActivationService;
use KernelBridge\LicensingClient\Services\LicenseCacheService;
use KernelBridge\LicensingClient\Services\LicenseVerificationService;
use KernelBridge\LicensingClient\Services\LicensingPolicyService;

final class LicenseController extends Controller
{
    public function show(LicenseCacheService $cache, LicensingPolicyService $policyService): View
    {
        $policy = null;
        $policyError = null;
        if (! $cache->hasUsableLicense()) {
            try {
                $policy = $policyService->current();
            } catch (KernelBridgeApiException|KernelBridgeUnavailableException|DeploymentProfileException $exception) {
                $policyError = $exception->getMessage();
            }
        }

        return view((string) config('kernelbridge-licensing.ui.view', 'kernelbridge-licensing::activation'), [
            'state' => $cache->state(),
            'hasUsableLicense' => $cache->hasUsableLicense(),
            'activationReason' => $cache->activationRequirementReason(),
            'activationMessage' => $cache->activationRequirementMessage(),
            'licenseStatus' => $cache->status(),
            'productCode' => config('kernelbridge-licensing.product_code'),
            'legacyActivationEnabled' => (bool) config('kernelbridge-licensing.legacy_activation_enabled', true),
            'licensingPolicy' => $policy,
            'licensingPolicyError' => $policyError,
            'platformActivationUrl' => $this->platformActivationUrl(),
        ]);
    }

    public function renewal(LicenseCacheService $cache): View
    {
        return view('kernelbridge-licensing::renewal', [
            'state' => $cache->state(),
            'productCode' => config('kernelbridge-licensing.product_code'),
            'renewalRequired' => $cache->requiresRenewal(),
            'renewalMessage' => $cache->activationRequirementMessage(),
            'portalUrls' => $this->portalUrls(),
        ]);
    }

    public function activate(Request $request, LicenseActivationService $activation, LicenseCacheService $cache, LicensingPolicyService $policyService): RedirectResponse
    {
        try {
            $policy = $policyService->current();
        } catch (KernelBridgeApiException|KernelBridgeUnavailableException|DeploymentProfileException $exception) {
            $field = $request->filled('activation_code') ? 'activation_code' : 'license_key';

            return back()->withInput($request->except(['activation_code', 'license_key']))->withErrors([
                $field => $exception->getMessage(),
            ]);
        }

        if ($request->filled('activation_code') && $policy['licensing_mode'] === 'legacy') {
            return back()->withErrors([
                'activation_code' => 'This product requires its configured legacy licensing flow.',
            ]);
        }

        if (! $request->filled('activation_code')
            && $policy['licensing_mode'] === 'platform_account') {
            return back()->withInput($request->except('license_key'))->withErrors([
                'license_key' => 'This product must be activated through its authenticated platform account.',
            ]);
        }

        if ($request->filled('activation_code')) {
            $data = $request->validate([
                'activation_code' => ['required', 'string', 'max:64'],
                'device_name' => ['nullable', 'string', 'max:255'],
            ]);

            try {
                $activation->activateWithCode($data['activation_code'], $data['device_name'] ?? config('app.name'));
            } catch (KernelBridgeApiException|KernelBridgeUnavailableException|DeploymentProfileException $exception) {
                return back()->withInput($request->except('activation_code'))->withErrors([
                    'activation_code' => $this->friendlyError($exception),
                ]);
            }

            return redirect()->intended('/')->with('kernelbridge_license_status', 'Installation activated successfully.');
        }

        $isEdunexa = strtoupper((string) config('kernelbridge-licensing.product_code')) === 'EDUNEXA';
        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:255'],
            'device_name' => [$isEdunexa ? 'required' : 'nullable', 'string', 'max:255'],
            'platform_url' => [$isEdunexa ? 'required' : 'nullable', 'url', 'max:2048'],
        ]);
        $state = $cache->state();
        $wasPreviouslyActivated = $state->license_identifier !== null || $state->activation_identifier !== null;

        try {
            $activation->activate($data['license_key'], $data['device_name'] ?? config('app.name'), $data['platform_url'] ?? null);
        } catch (KernelBridgeApiException|KernelBridgeUnavailableException|DeploymentProfileException $exception) {
            return back()->withInput($request->except('license_key'))->withErrors([
                'license_key' => $this->friendlyError($exception),
            ]);
        }

        $redirect = (string) config('kernelbridge-licensing.redirects.after_activation', '/');
        $response = ! $wasPreviouslyActivated && $redirect !== '/'
            ? redirect($redirect)
            : redirect()->intended('/');

        return $response->with('kernelbridge_license_status', 'License activated successfully.');
    }

    public function verify(LicenseVerificationService $verification): RedirectResponse
    {
        try {
            $verification->verify(true);
        } catch (KernelBridgeApiException|KernelBridgeUnavailableException|DeploymentProfileException $exception) {
            return back()->withErrors([
                'license_key' => $this->friendlyError($exception),
            ]);
        }

        return redirect()->route($this->routeName('show'))->with('kernelbridge_license_status', 'License verified successfully.');
    }

    public function deactivate(LicenseVerificationService $verification): RedirectResponse
    {
        try {
            $verification->deactivate();
        } catch (KernelBridgeApiException|KernelBridgeUnavailableException|DeploymentProfileException $exception) {
            return back()->withErrors([
                'license_key' => $this->friendlyError($exception),
            ]);
        }

        return redirect()->route($this->routeName('show'))->with('kernelbridge_license_status', 'License deactivated successfully.');
    }

    private function friendlyError(KernelBridgeApiException|KernelBridgeUnavailableException $exception): string
    {
        if ($exception instanceof KernelBridgeApiException && $exception->errorCode === 'activation_limit_reached') {
            return 'This license has reached its device activation limit. Deactivate another installation in KernelBridge before trying again.';
        }

        return $exception->getMessage();
    }

    private function platformActivationUrl(): ?string
    {
        $url = config('kernelbridge-licensing.portal_urls.platform_activation');
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        return $url;
    }

    private function routeName(string $name): string
    {
        return (string) config('kernelbridge-licensing.routes.name', 'kernelbridge.license.').$name;
    }

    /**
     * @return array{installation_code: string, activation_code: string, renewal: string}
     */
    private function portalUrls(): array
    {
        $urls = config('kernelbridge-licensing.portal_urls', []);
        $validated = [];

        foreach (['installation_code', 'activation_code', 'renewal'] as $action) {
            $url = is_array($urls) ? ($urls[$action] ?? null) : null;
            $parts = is_string($url) ? parse_url($url) : false;
            if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
                || ! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || blank($parts['host'] ?? null)) {
                throw new \LogicException("KernelBridge portal URL [$action] must be a valid HTTPS URL.");
            }

            $validated[$action] = $url;
        }

        return $validated;
    }
}
