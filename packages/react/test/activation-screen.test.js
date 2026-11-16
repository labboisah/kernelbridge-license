import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { JSDOM } from 'jsdom';
import { ActivationScreen } from '../dist/ActivationScreen.js';

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'http://localhost' });
Object.defineProperty(globalThis, 'window', { configurable: true, value: dom.window });
Object.defineProperty(globalThis, 'document', { configurable: true, value: dom.window.document });
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: dom.window.navigator });
Object.defineProperty(globalThis, 'HTMLElement', { configurable: true, value: dom.window.HTMLElement });
Object.defineProperty(globalThis, 'MutationObserver', { configurable: true, value: dom.window.MutationObserver });
globalThis.IS_REACT_ACT_ENVIRONMENT = true;

const { cleanup, fireEvent, render, screen, waitFor } = await import('@testing-library/react');

const callbacks = {
  beginLink: async () => ({ linkId: 'link-reference' }),
  getLinkStatus: async () => 'pending',
  completeActivation: async () => {},
};

afterEach(cleanup);

test('direct mode renders a code form with KernelBridge branding', () => {
  const markup = renderToStaticMarkup(createElement(ActivationScreen, {
    mode: 'direct',
    productName: 'MediFlow',
    onActivateWithCode: async () => {},
  }));

  assert.match(markup, /KernelBridge/);
  assert.match(markup, /Activate MediFlow/);
  assert.match(markup, /Installation activation code/);
  assert.doesNotMatch(markup, /Connect your product account/);
});

test('platform-account mode renders the account-link flow without a code field', () => {
  const markup = renderToStaticMarkup(createElement(ActivationScreen, {
    mode: 'platform_account',
    productName: 'MediFlow',
    platformAccount: callbacks,
  }));

  assert.match(markup, /Connect your product account/);
  assert.match(markup, /activation code supplied by your license administrator/);
  assert.doesNotMatch(markup, /license owner may need to approve/);
  assert.doesNotMatch(markup, /Installation activation code/);
});

test('platform-account mode can require a KernelBridge activation code', () => {
  const markup = renderToStaticMarkup(createElement(ActivationScreen, {
    mode: 'platform_account',
    productName: 'AlignEx',
    organizationName: 'Example Organization',
    activationCodeRequired: true,
    platformAccount: callbacks,
  }));

  assert.match(markup, /KernelBridge/);
  assert.match(markup, /KernelBridge activation code/);
  assert.match(markup, /Example Organization/);
});

test('hybrid mode renders both flows only when explicitly provided', () => {
  const markup = renderToStaticMarkup(createElement(ActivationScreen, {
    mode: 'hybrid',
    productName: 'MediFlow',
    onActivateWithCode: async () => {},
    platformAccount: callbacks,
  }));

  assert.match(markup, /Installation activation code/);
  assert.match(markup, /Connect your product account/);
});

test('direct activation submits the trimmed code and removes it from the screen after success', async () => {
  let submittedCode = '';
  render(createElement(ActivationScreen, {
    mode: 'direct',
    productName: 'MediFlow',
    onActivateWithCode: async (code) => {
      submittedCode = code;
    },
  }));

  fireEvent.change(screen.getByLabelText('Installation activation code'), {
    target: { value: '  KB-ONE-TIME-CODE  ' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Activate' }));

  await waitFor(() => assert.equal(submittedCode, 'KB-ONE-TIME-CODE'));
  await waitFor(() => assert.match(screen.getByRole('status').textContent, /MediFlow is activated/));
  assert.equal(document.body.textContent.includes('KB-ONE-TIME-CODE'), false);
});

test('platform activation starts the host-provided account-link flow', async () => {
  let started = false;
  let submittedCode = '';
  render(createElement(ActivationScreen, {
    mode: 'platform_account',
    productName: 'MediFlow',
    activationCodeRequired: true,
    platformAccount: {
      ...callbacks,
      beginLink: async (code) => {
        started = true;
        submittedCode = code ?? '';
        return { linkId: 'link-reference' };
      },
    },
  }));

  fireEvent.change(screen.getByLabelText('KernelBridge activation code'), {
    target: { value: ' KB-ACCOUNT-CODE ' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Connect account' }));

  await waitFor(() => assert.equal(started, true));
  assert.equal(submittedCode, 'KB-ACCOUNT-CODE');
  assert.match(screen.getByRole('status').textContent, /Waiting for the KernelBridge account link/);
});

test('platform activation exposes account unlinking only when the host provides it', async () => {
  let unlinkedId = null;
  const originalConfirm = window.confirm;
  window.confirm = () => true;
  const callbacks = {
    async beginLink() {
      return { linkId: 'link-123', status: 'pending' };
    },
    async getLinkStatus() {
      return 'pending';
    },
    async completeActivation() {},
    async unlinkAccount(linkId) {
      unlinkedId = linkId;
    },
  };

  const { queryByRole } = render(createElement(ActivationScreen, {
    mode: 'platform_account',
    productName: 'MarketLens',
    initialAccountLink: { linkId: 'link-123', status: 'pending' },
    platformAccount: callbacks,
  }));

  try {
    fireEvent.click(screen.getByRole('button', { name: 'Unlink account' }));
    await waitFor(() => assert.equal(unlinkedId, 'link-123'));
  } finally {
    window.confirm = originalConfirm;
  }

  assert.equal(queryByRole('button', { name: 'Unlink account' }), null);
});

test('active platform links complete through the host callback without exposing a code', async () => {
  let completedLinkId = '';
  render(createElement(ActivationScreen, {
    mode: 'platform_account',
    productName: 'MediFlow',
    platformAccount: {
      ...callbacks,
      getLinkStatus: async () => 'approved',
      completeActivation: async (linkId) => {
        completedLinkId = linkId;
      },
    },
  }));

  fireEvent.click(screen.getByRole('button', { name: 'Connect account' }));
  await waitFor(() => assert.match(screen.getByRole('status').textContent, /MediFlow is activated/), { timeout: 7_000 });

  assert.equal(completedLinkId, 'link-reference');
  assert.equal(document.body.textContent.includes('activation_code'), false);
});

test('activation errors use safe messages instead of displaying raw backend details', async () => {
  render(createElement(ActivationScreen, {
    mode: 'direct',
    productName: 'MediFlow',
    onActivateWithCode: async () => {
      throw Object.assign(new Error('raw response included the submitted secret'), {
        code: 'activation_code_invalid',
      });
    },
  }));

  fireEvent.change(screen.getByLabelText('Installation activation code'), {
    target: { value: 'KB-ONE-TIME-CODE' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Activate' }));

  await waitFor(() => {
    assert.match(screen.getByRole('alert').textContent, /not valid/);
  });
  assert.equal(document.body.textContent.includes('raw response'), false);
});

test('activation request failures are visibly explained', async () => {
  render(createElement(ActivationScreen, {
    mode: 'platform_account',
    productName: 'AlignEx',
    activationCodeRequired: true,
    platformAccount: {
      ...callbacks,
      beginLink: async () => {
        throw Object.assign(new Error('response was HTML'), { code: 'unexpected_response' });
      },
    },
  }));

  fireEvent.change(screen.getByLabelText('KernelBridge activation code'), {
    target: { value: 'KB-ACCOUNT-CODE' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Connect account' }));

  const alert = await screen.findByRole('alert');
  assert.match(alert.textContent, /AlignEx returned an unexpected response/);
  assert.equal(alert.textContent.includes('response was HTML'), false);
});

test('activation reports a denied endpoint without mislabeling it as a session failure', async () => {
  render(createElement(ActivationScreen, {
    mode: 'platform_account',
    productName: 'AlignEx',
    activationCodeRequired: true,
    platformAccount: {
      ...callbacks,
      beginLink: async () => {
        throw Object.assign(new Error('request denied'), { code: 'forbidden', status: 403 });
      },
    },
  }));

  fireEvent.change(screen.getByLabelText('KernelBridge activation code'), {
    target: { value: 'KB-ACCOUNT-CODE' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Connect account' }));

  const alert = await screen.findByRole('alert');
  assert.match(alert.textContent, /denied this activation request \(HTTP 403\)/);
  assert.doesNotMatch(alert.textContent, /session has expired/i);
});
