import { useEffect, useState, type FormEvent } from 'react';

export type LicensingMode = 'direct' | 'platform_account' | 'hybrid';
export type PlatformAccountLinkStatus = 'pending' | 'approved' | 'rejected' | 'revoked';

const platformAccountLinkStatuses: readonly PlatformAccountLinkStatus[] = [
  'pending',
  'approved',
  'rejected',
  'revoked',
];

export interface PlatformAccountActivationCallbacks {
  beginLink(activationCode?: string): Promise<{ linkId: string; status?: PlatformAccountLinkStatus }>;
  getLinkStatus(linkId: string): Promise<PlatformAccountLinkStatus>;
  completeActivation(linkId: string): Promise<void>;
  unlinkAccount?(linkId: string): Promise<void>;
}

interface ActivationScreenBaseProps {
  mode: LicensingMode;
  productName: string;
  organizationName?: string | null;
  activationCodeRequired?: boolean;
  initialAccountLink?: { linkId: string; status: PlatformAccountLinkStatus } | null;
}

type DirectActivationProps = {
  mode: 'direct';
  onActivateWithCode: (activationCode: string) => Promise<void>;
  platformAccount?: never;
};

type PlatformActivationProps = {
  mode: 'platform_account';
  onActivateWithCode?: never;
  platformAccount: PlatformAccountActivationCallbacks;
};

type HybridActivationProps = {
  mode: 'hybrid';
  onActivateWithCode: (activationCode: string) => Promise<void>;
  platformAccount: PlatformAccountActivationCallbacks;
};

export type ActivationScreenProps = ActivationScreenBaseProps
  & (DirectActivationProps | PlatformActivationProps | HybridActivationProps);

export function ActivationScreen(props: ActivationScreenProps) {
  const [activationCode, setActivationCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activated, setActivated] = useState(false);
  const directActivation = 'onActivateWithCode' in props ? props.onActivateWithCode : undefined;
  const platformAccount = 'platformAccount' in props ? props.platformAccount : undefined;

  async function submitActivationCode(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const code = activationCode.trim();
    if (!directActivation || !code || busy) return;

    setBusy(true);
    setError(null);
    try {
      await directActivation(code);
      setActivationCode('');
      setActivated(true);
    } catch (activationError) {
      setError(safeErrorMessage(activationError));
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="kb-activation" aria-labelledby="kb-activation-title">
      <section className="kb-activation-card">
        <header className="kb-activation-brand">
          <div className="kb-activation-wordmark" aria-label="KernelBridge">
            <span className="kb-activation-logo" aria-hidden="true" />
            <span className="kb-activation-wordmark-copy">
              <span>KernelBridge</span>
              <small>LICENSING</small>
            </span>
          </div>
          <p>Secure product activation</p>
        </header>

        <div className="kb-activation-content">
          <h1 id="kb-activation-title">Activate {props.productName}</h1>
          <p className="kb-activation-intro">
            Activate this installation to continue. Your licensing mode and access are managed by KernelBridge.
          </p>

          {error && <p className="kb-activation-alert" role="alert">{error}</p>}
          {activated && (
            <p className="kb-activation-success" role="status">
              {props.productName} is activated on this installation.
            </p>
          )}

          {!activated && directActivation && (
            <form className="kb-activation-form" onSubmit={submitActivationCode}>
              <label htmlFor="kb-activation-code">Installation activation code</label>
              <input
                id="kb-activation-code"
                name="activationCode"
                type="text"
                autoComplete="off"
                autoCapitalize="characters"
                spellCheck={false}
                value={activationCode}
                onChange={(event) => setActivationCode(event.currentTarget.value)}
                disabled={busy || activated}
                required
              />
              <button type="submit" disabled={busy || activated || !activationCode.trim()}>
                {busy ? 'Activating…' : 'Activate'}
              </button>
            </form>
          )}

          {!activated && platformAccount && (
            <PlatformAccountPanel
              callbacks={platformAccount}
              organizationName={props.organizationName}
              activationCodeRequired={props.activationCodeRequired === true}
              initialAccountLink={props.initialAccountLink}
              onActivated={() => setActivated(true)}
            />
          )}
        </div>
      </section>
    </main>
  );
}

interface PlatformAccountPanelProps {
  callbacks: PlatformAccountActivationCallbacks;
  organizationName?: string | null;
  activationCodeRequired: boolean;
  initialAccountLink?: { linkId: string; status: PlatformAccountLinkStatus } | null;
  onActivated: () => void;
}

function PlatformAccountPanel({ callbacks, organizationName, activationCodeRequired, initialAccountLink, onActivated }: PlatformAccountPanelProps) {
  const [activationCode, setActivationCode] = useState('');
  const [linkId, setLinkId] = useState<string | null>(initialAccountLink?.linkId ?? null);
  const [status, setStatus] = useState<PlatformAccountLinkStatus | null>(initialAccountLink?.status ?? null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [completingLinkId, setCompletingLinkId] = useState<string | null>(null);

  async function beginLink() {
    const code = activationCode.trim();
    if (activationCodeRequired && !code) return;

    setBusy(true);
    setError(null);
    try {
      const link = await callbacks.beginLink(activationCodeRequired ? code : undefined);
      const currentLinkId = link.linkId.trim();
      if (!currentLinkId) throw new Error('The platform did not return an account-link reference.');
      setActivationCode('');
      setLinkId(currentLinkId);
      setStatus(link.status ?? 'pending');
    } catch (linkError) {
      setError(safeErrorMessage(linkError));
    } finally {
      setBusy(false);
    }
  }

  async function completeActivation(currentLinkId: string) {
    if (completingLinkId === currentLinkId) return;
    setCompletingLinkId(currentLinkId);
    setBusy(true);
    setError(null);
    try {
      await callbacks.completeActivation(currentLinkId);
      onActivated();
    } catch (activationError) {
      setCompletingLinkId(null);
      setError(safeErrorMessage(activationError));
    } finally {
      setBusy(false);
    }
  }

  async function unlinkAccount() {
    if (!callbacks.unlinkAccount || !linkId || busy) return;
    if (!window.confirm('Unlink this account? This may revoke active product installations and unused activation codes.')) return;

    setBusy(true);
    setError(null);
    try {
      await callbacks.unlinkAccount(linkId);
      setLinkId(null);
      setStatus(null);
    } catch (unlinkError) {
      setError(safeErrorMessage(unlinkError));
    } finally {
      setBusy(false);
    }
  }

  useEffect(() => {
    if (linkId && status === 'approved') {
      void completeActivation(linkId);
    }
  }, [callbacks, linkId, status]);

  useEffect(() => {
    if (!linkId || status !== 'pending') return;

    const currentLinkId = linkId;
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout>;

    async function pollStatus() {
      try {
        const nextStatus = await callbacks.getLinkStatus(currentLinkId);
        if (cancelled) return;
        if (!platformAccountLinkStatuses.includes(nextStatus)) {
          throw new Error('The platform returned an unsupported account-link status.');
        }

        if (nextStatus === 'approved') {
          setStatus('approved');
          return;
        }

        setStatus(nextStatus);
        if (nextStatus === 'pending') timer = setTimeout(pollStatus, 5_000);
      } catch (statusError) {
        if (!cancelled) {
          setError(safeErrorMessage(statusError));
          timer = setTimeout(pollStatus, 5_000);
        }
      }
    }

    timer = setTimeout(pollStatus, 5_000);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [callbacks, linkId, status]);

  return (
    <section className="kb-platform-activation" aria-labelledby="kb-platform-title">
      <h2 id="kb-platform-title">Connect your product account</h2>
      <p>
        {organizationName
          ? `Connect ${organizationName} to its KernelBridge license with the activation code supplied by your license administrator.`
          : 'Connect your product account to its KernelBridge license with the activation code supplied by your license administrator.'}
      </p>
      {error && <p className="kb-activation-alert" role="alert">{error}</p>}
      {activationCodeRequired && (!linkId || status === 'rejected' || status === 'revoked') && (
        <label className="kb-activation-form">
          <span>KernelBridge activation code</span>
          <input
            type="text"
            autoComplete="one-time-code"
            autoCapitalize="characters"
            spellCheck={false}
            value={activationCode}
            onChange={(event) => setActivationCode(event.currentTarget.value)}
            disabled={busy}
            required
          />
        </label>
      )}
      {status === 'pending' && (
        <p className="kb-activation-status" role="status">
          Waiting for the KernelBridge account link to be approved. This screen will continue automatically when it is ready.
        </p>
      )}
      {status === 'rejected' && (
        <p className="kb-activation-alert" role="alert">
          This account link is no longer active. Contact your administrator before trying again.
        </p>
      )}
      {status === 'revoked' && (
        <p className="kb-activation-alert" role="alert">
          This account link was revoked. Contact your administrator to request a new link.
        </p>
      )}
      {status === 'approved' && (
        <p className="kb-activation-status" role="status">KernelBridge account link approved. Updating license status…</p>
      )}
      {!linkId && (
        <button type="button" onClick={() => void beginLink()} disabled={busy || (activationCodeRequired && !activationCode.trim())}>
          {busy ? 'Connecting…' : 'Connect account'}
        </button>
      )}
      {(status === 'rejected' || status === 'revoked') && (
        <button type="button" onClick={() => void beginLink()} disabled={busy || (activationCodeRequired && !activationCode.trim())}>
          {busy ? 'Connecting…' : 'Request a new link'}
        </button>
      )}
      {linkId && status !== 'revoked' && callbacks.unlinkAccount && (
        <button type="button" onClick={() => void unlinkAccount()} disabled={busy}>
          {busy ? 'Unlinking…' : 'Unlink account'}
        </button>
      )}
    </section>
  );
}

function safeErrorMessage(error: unknown): string {
  const code = typeof error === 'object' && error !== null && 'code' in error
    ? error.code
    : undefined;

  switch (code) {
    case 'activation_code_invalid':
      return 'That activation code is not valid. Check the code and try again.';
    case 'activation_code_expired':
      return 'That activation code has expired. Request a new code and try again.';
    case 'activation_code_used':
      return 'That activation code has already been used. Request a new code for this installation.';
    case 'product_mismatch':
      return 'That code is for a different product.';
    case 'license_inactive':
    case 'license_inactive_or_expired':
      return 'The license is not currently active. Contact your license administrator.';
    case 'authentication_required':
      return 'AlignEx requires you to sign in before this activation request (HTTP 401).';
    case 'csrf_token_expired':
      return 'The activation security token was rejected (HTTP 419). Refresh the page and retry.';
    case 'sign_in_required':
      return 'AlignEx redirected this request to sign-in. Sign in again, then retry activation.';
    case 'forbidden':
      return 'AlignEx denied this activation request (HTTP 403). Check your organization license-manager access.';
    case 'endpoint_not_found':
      return 'AlignEx could not find the activation endpoint (HTTP 404). Refresh the deployment and try again.';
    case 'request_rejected':
      return 'The activation request was rejected. Verify the code and organization, then try again.';
    case 'rate_limited':
      return 'Too many activation requests were sent. Wait a minute, then try again.';
    case 'server_error':
      return 'AlignEx could not process the activation request (server error). Please try again later.';
    case 'unexpected_response':
      return typeof error === 'object' && error !== null && 'status' in error
        ? `AlignEx returned a non-JSON response (HTTP ${String(error.status)}) from ${'responsePath' in error ? String(error.responsePath) : 'the activation endpoint'}.`
        : 'AlignEx returned an unexpected response. Refresh the page and retry; if this continues, contact support.';
    case 'request_failed':
      return 'AlignEx could not complete the activation request. Check the connection and try again.';
    default:
      return 'Activation could not be completed. Check your connection and try again, or contact your administrator.';
  }
}
