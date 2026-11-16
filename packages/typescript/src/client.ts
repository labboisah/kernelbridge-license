import { KernelBridgeApiError, KernelBridgeUnavailableError } from './errors.js';
import type {
  DeactivationResponse,
  EntitlementResponse,
  InstallationActivationResponse,
  SignedInstallationEntitlements,
  LicenseResponse,
  LicensingClientOptions,
  SubscriptionResponse,
} from './types.js';

export class KernelBridgeLicenseClient {
  private readonly fetcher: typeof globalThis.fetch;

  constructor(private readonly options: LicensingClientOptions) {
    if (!options.apiUrl || !options.productCode) {
      throw new TypeError('KernelBridge API URL and product code are required.');
    }

    this.fetcher = options.fetch ?? globalThis.fetch;
  }

  activate(licenseKey: string, installationIdentifier: string, deviceName?: string, machineFingerprint?: string): Promise<LicenseResponse> {
    return this.request('POST', '/licenses/activate', {
      license_key: licenseKey,
      device_id: installationIdentifier,
      ...(deviceName ? { device_name: deviceName } : {}),
      ...(machineFingerprint ? { machine_fingerprint: machineFingerprint } : {}),
    });
  }

  verify(licenseKey: string, installationIdentifier: string, machineFingerprint?: string): Promise<LicenseResponse> {
    return this.request('POST', '/licenses/verify', {
      license_key: licenseKey,
      device_id: installationIdentifier,
      ...(machineFingerprint ? { machine_fingerprint: machineFingerprint } : {}),
    });
  }

  deactivate(licenseKey: string, installationIdentifier: string, machineFingerprint?: string): Promise<DeactivationResponse> {
    return this.request('POST', '/licenses/deactivate', {
      license_key: licenseKey,
      device_id: installationIdentifier,
      ...(machineFingerprint ? { machine_fingerprint: machineFingerprint } : {}),
    });
  }

  exchangeActivationCode(activationCode: string, installationUuid: string, deviceName?: string): Promise<InstallationActivationResponse> {
    return this.request('POST', '/installations/activate', {
      activation_code: activationCode,
      product_code: this.options.productCode.toUpperCase(),
      installation_uuid: installationUuid,
      ...(deviceName ? { device_name: deviceName } : {}),
    }, null);
  }

  getLicensingPolicy(): Promise<SignedInstallationEntitlements> {
    return this.request(
      'GET',
      `/licensing/products/${encodeURIComponent(this.options.productCode.toUpperCase())}/policy`,
      {},
      null,
    );
  }

  verifyInstallation(credential: string): Promise<SignedInstallationEntitlements> {
    if (!credential) throw new TypeError('An installation credential is required for verification.');
    return this.request('POST', '/installations/verify', {}, credential);
  }
  deactivateInstallation(credential: string): Promise<{ installation_uuid: string; status: string }> {
    if (!credential) throw new TypeError('An installation credential is required for deactivation.');
    return this.request('POST', '/installations/deactivate', {}, credential);
  }

  getEntitlements(subscriptionIdentifier: string): Promise<EntitlementResponse> {
    return this.request('GET', '/entitlements', { subscription_uuid: subscriptionIdentifier });
  }

  getSubscription(subscriptionIdentifier: string): Promise<SubscriptionResponse> {
    return this.request('GET', '/subscriptions/status', { subscription_uuid: subscriptionIdentifier });
  }

  private async request<T>(
    method: 'GET' | 'POST',
    path: string,
    data: Record<string, string>,
    credential?: string | null,
  ): Promise<T> {
    const token = credential === null
      ? null
      : credential ?? await this.defaultCredential();
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.options.timeoutMs ?? 10_000);
    const url = new URL(`${this.options.apiUrl.replace(/\/$/, '')}${path}`);
    if (method === 'GET') {
      Object.entries(data).forEach(([key, value]) => url.searchParams.set(key, value));
    }

    let response: Response;
    try {
      response = await this.fetcher(url, {
        method,
        redirect: 'error',
        headers: {
          Accept: 'application/json',
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
          'Content-Type': 'application/json',
          'X-KernelBridge-Product': this.options.productCode.toUpperCase(),
        },
        ...(method === 'POST' ? { body: JSON.stringify(data) } : {}),
        signal: controller.signal,
      });
    } catch (error) {
      throw new KernelBridgeUnavailableError(undefined, { cause: error });
    } finally {
      clearTimeout(timeout);
    }

    if (response.status >= 500) throw new KernelBridgeUnavailableError();

    let body: { success?: boolean; data?: T; error?: { code?: string; message?: string } };
    try {
      body = await response.json() as typeof body;
    } catch (error) {
      throw new KernelBridgeUnavailableError('KernelBridge returned an unreadable response.', { cause: error });
    }

    if (!response.ok || body.success !== true || body.data === undefined) {
      throw new KernelBridgeApiError(
        body.error?.message ?? 'KernelBridge rejected the licensing request.',
        body.error?.code ?? 'license_rejected',
        response.status,
      );
    }

    return body.data;
  }

  private async defaultCredential(): Promise<string> {
    const token = this.options.tokenProvider
      ? await this.options.tokenProvider.getToken()
      : this.options.apiToken;

    if (!token) throw new TypeError('A credential is required for this legacy licensing operation.');

    return token;
  }
}
