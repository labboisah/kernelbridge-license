import { KernelBridgeApiError, KernelBridgeUnavailableError } from './errors.js';

export type PlatformAccountLinkStatus = 'pending' | 'approved' | 'rejected' | 'revoked';

export interface PlatformAccountLink {
  uuid: string;
  license_uuid: string;
  product_code: string;
  business_uuid: string;
  status: PlatformAccountLinkStatus;
  license_status: string;
  subscription_uuid: string | null;
  expires_at: string | null;
  grace_expires_at: string | null;
  entitlement_revision: number | null;
  entitlements: Array<{ key: string; name: string; enabled: boolean; limit: string | number | null }>;
  requested_at: string | null;
  approved_at: string | null;
  rejected_at: string | null;
  revoked_at: string | null;
}

export interface InstallationActivationCode {
  link_uuid: string;
  installation_uuid: string;
  activation_code: string;
  expires_at: string;
}

export interface PlatformAccountLinkUnlinkResult {
  uuid: string;
  license_uuid: string;
  business_uuid: string;
  status: 'revoked';
  installations_revoked: number;
  activation_codes_revoked: number;
}

export interface PlatformAccountLinkClientOptions {
  apiUrl: string;
  productCode: string;
  getCredential: () => Promise<string> | string;
  fetch?: typeof globalThis.fetch;
  timeoutMs?: number;
}

export class PlatformAccountLinkClient {
  private readonly fetcher: typeof globalThis.fetch;

  constructor(private readonly options: PlatformAccountLinkClientOptions) {
    if (!options.apiUrl || !options.productCode || !options.getCredential) {
      throw new TypeError('KernelBridge API URL, product code, and server credential provider are required.');
    }

    this.fetcher = options.fetch ?? globalThis.fetch;
  }

  requestLink(licenseUuid: string, businessUuid: string): Promise<PlatformAccountLink> {
    return this.request('POST', '/platform/account-links/requests', {
      license_uuid: licenseUuid,
      business_uuid: businessUuid,
    });
  }

  requestLinkWithActivationCode(activationCode: string, businessUuid: string): Promise<PlatformAccountLink> {
    return this.request('POST', '/platform/account-links/requests', {
      activation_code: activationCode,
      business_uuid: businessUuid,
    });
  }

  getLinkStatus(linkUuid: string): Promise<PlatformAccountLink> {
    return this.request('GET', `/platform/account-links/${encodeURIComponent(linkUuid)}`);
  }

  unlink(linkUuid: string): Promise<PlatformAccountLinkUnlinkResult> {
    return this.request('DELETE', `/platform/account-links/${encodeURIComponent(linkUuid)}`);
  }

  issueInstallationCode(
    linkUuid: string,
    installationUuid: string,
    deviceName?: string,
  ): Promise<InstallationActivationCode> {
    return this.request(
      'POST',
      `/platform/account-links/${encodeURIComponent(linkUuid)}/activation-codes`,
      {
        installation_uuid: installationUuid,
        ...(deviceName ? { device_name: deviceName } : {}),
      },
    );
  }

  private async request<T>(
    method: 'GET' | 'POST' | 'DELETE',
    path: string,
    body?: Record<string, string>,
  ): Promise<T> {
    const credential = await this.options.getCredential();
    if (!credential) {
      throw new TypeError('The KernelBridge platform integration credential is unavailable.');
    }

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.options.timeoutMs ?? 10_000);
    const url = `${this.options.apiUrl.replace(/\/$/, '')}${path}`;
    let response: Response;

    try {
      response = await this.fetcher(url, {
        method,
        redirect: 'error',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${credential}`,
          'X-KernelBridge-Product': this.options.productCode.toUpperCase(),
          ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
        signal: controller.signal,
      });
    } catch (error) {
      throw new KernelBridgeUnavailableError(undefined, { cause: error });
    } finally {
      clearTimeout(timeout);
    }

    if (response.status >= 500) {
      throw new KernelBridgeUnavailableError();
    }

    let envelope: {
      success?: boolean;
      data?: T;
      error?: { code?: string; message?: string };
    };

    try {
      envelope = await response.json() as typeof envelope;
    } catch (error) {
      throw new KernelBridgeUnavailableError('KernelBridge returned an unreadable response.', { cause: error });
    }

    if (!response.ok || envelope.success !== true || envelope.data === undefined) {
      throw new KernelBridgeApiError(
        envelope.error?.message ?? 'KernelBridge rejected the platform licensing request.',
        envelope.error?.code ?? 'platform_account_link_rejected',
        response.status,
      );
    }

    return envelope.data;
  }
}
