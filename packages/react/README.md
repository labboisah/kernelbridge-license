# KernelBridge React Licensing UI

`@kernelbridge/activation-ui-react` provides a KernelBridge-branded activation screen for React applications. It displays the activation paths allowed by the mode supplied by the host and delegates all licensing operations to host callbacks.

The UI package does not call KernelBridge directly, authenticate a product-platform user, or store credentials. The host should pass the mode from a verified `LicenseActivationService.getLicensingPolicy()` result; the displayed mode is a UX choice, not a security boundary, and KernelBridge enforces the real policy server-side.

## Install

Install this package and a compatible React version. The UI package does not include the TypeScript runtime SDK or the server-only platform adapter.

Import the stylesheet once from the host application:

```ts
import '@kernelbridge/activation-ui-react/styles.css';
```

## Direct activation

Call the licensing SDK from a trusted runtime callback. In Electron, invoke a narrow, validated preload/IPC operation; do not import the Node licensing client in the renderer.

```tsx
import { ActivationScreen } from '@kernelbridge/activation-ui-react';

<ActivationScreen
  mode="direct"
  productName="MediFlow"
  onActivateWithCode={(code) => window.license.activateWithCode(code)}
/>;
```

## Platform-account activation

The customer's product-platform sign-in and account/license mapping remain owned by the product. Implement the callbacks through authenticated host routes or a trusted Electron main-process bridge:

```tsx
<ActivationScreen
  mode="platform_account"
  productName="MediFlow"
  platformAccount={{
    beginLink: () => window.license.beginPlatformAccountLink(),
    getLinkStatus: (linkId) => window.license.getPlatformAccountLinkStatus(linkId),
    completeActivation: (linkId) => window.license.completePlatformActivation(linkId),
  }}
/>;
```

`beginLink` must use the authenticated customer's trusted product-account/license mapping. `completeActivation` must request the installation-bound code from the trusted product backend and pass it directly to the client SDK for exchange. Its promise must resolve only after the SDK has verified and securely persisted the installation credential. Do not return or log the code in the React renderer. Never put the platform integration credential in the UI, renderer, or browser.

The screen checks account-link status immediately, continues polling only if an older link is still pending, and completes activation once the link is active. Hybrid mode shows both paths only when the trusted host supplies `mode="hybrid"` because KernelBridge has configured that product mode.

The UI fixes KernelBridge branding and presents the product name only as activation context. It does not expose a licensing-mode selector.
