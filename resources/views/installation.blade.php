<main class="kb-page">
    <aside class="kb-brand">
        <a class="kb-logo-line" href="{{ url('/') }}" aria-label="KernelBridge">
            <img class="kb-logo" src="{{ asset('vendor/kernelbridge-licensing/kernelbridge-logo.png') }}" alt="KernelBridge">
            <span class="kb-wordmark">KernelBridge<small>Licensing and activation</small></span>
        </a>

        <div class="kb-brand-copy">
            <p class="kb-eyebrow">Secure account activation</p>
            <h1>Connect {{ $productName }} to KernelBridge.</h1>
            <p>
                Link your business to its KernelBridge license. A valid activation code connects the license
                immediately so installations can activate.
            </p>
            <ol class="kb-security-list">
                <li><span class="kb-check">1</span>Submit your license activation code</li>
                <li><span class="kb-check">2</span>Connect your business to the license</li>
                <li><span class="kb-check">3</span>Activate product installations</li>
            </ol>
        </div>

        <p class="kb-brand-foot">KernelBridge is the source of truth for licensing and entitlements.</p>
    </aside>

    <section class="kb-panel-wrap">
        <article class="kb-panel">
            <header class="kb-panel-header">
                <h2>Link your business license</h2>
                <p>Submit the activation code supplied by your license administrator.</p>
            </header>

            @if ($businessName)
                <div class="kb-meta">
                    <div><span>Product</span><strong>{{ $productName }}</strong></div>
                    <div><span>Business</span><strong>{{ $businessName }}</strong></div>
                </div>
            @endif

            @if (session()->has('status'))
                <p class="kb-alert kb-alert-success" role="status">{{ session('status') }}</p>
            @endif

            @error('license')
                <p class="kb-alert kb-alert-error" role="alert">{{ $message }}</p>
            @enderror

            @if ($license)
                @php($linkStatus = data_get($license->provider_metadata, 'account_link_status', 'unknown'))
                <section class="kb-meta" aria-labelledby="kb-link-status-title">
                    <div>
                        <span id="kb-link-status-title">Account-link status</span>
                        <strong>{{ str_replace('_', ' ', $linkStatus) }}</strong>
                    </div>
                    @if (data_get($license->provider_metadata, 'plan_name'))
                        <div><span>Plan</span><strong>{{ data_get($license->provider_metadata, 'plan_name') }}</strong></div>
                    @endif
                    @if ($linkStatus === 'pending')
                        <p class="kb-alert kb-alert-warning" role="status">
                            This account-link request is still pending. Refresh its status or submit the activation code again to reconnect the product account.
                        </p>
                    @elseif ($linkStatus === 'approved')
                        @if (isset($licenseActive) && ! $licenseActive)
                            <p class="kb-alert kb-alert-warning" role="status">
                                The account link is approved, but the license is not active. Contact your license administrator.
                            </p>
                        @else
                            <p class="kb-alert kb-alert-success" role="status">
                                The account link is approved. Installations can activate when the license is active.
                            </p>
                        @endif
                    @elseif ($linkStatus === 'rejected')
                        <p class="kb-alert kb-alert-error" role="alert">
                            This earlier account-link request was rejected. You can submit a new request.
                        </p>
                    @elseif ($linkStatus === 'revoked')
                        <p class="kb-alert kb-alert-error" role="alert">
                            This account link was revoked. Submit a valid activation code to connect a license again.
                        </p>
                    @endif
                    <div class="kb-actions">
                        <button type="button" class="kb-button kb-button-secondary" wire:click="refreshAccountLinkStatus" wire:loading.attr="disabled">
                            Refresh status
                        </button>
                        @if ($linkStatus === 'rejected')
                            <button type="button" class="kb-button" wire:click="requestAccountLinkAgain" wire:loading.attr="disabled">
                                Request link again
                            </button>
                        @endif
                        @if ($linkStatus !== 'revoked' && data_get($license->provider_metadata, 'account_link_uuid'))
                            <button
                                type="button"
                                class="kb-button kb-button-secondary"
                                wire:click="unlinkAccount"
                                wire:confirm="Unlink this account? This revokes active Business Server installations and unused installation codes for this license."
                                wire:loading.attr="disabled"
                            >
                                Unlink account
                            </button>
                        @endif
                    </div>
                </section>
            @endif

            @if (! $license || in_array(data_get($license->provider_metadata, 'account_link_status'), ['pending', 'rejected', 'revoked'], true))
                <form class="kb-form" wire:submit="activate">
                    <label class="kb-field" for="kb-activation-code">
                        <span>{{ $license && $linkStatus === 'pending' ? 'Resubmit KernelBridge activation code' : 'KernelBridge activation code' }}</span>
                        <input
                            id="kb-activation-code"
                            type="text"
                            wire:model="activationCode"
                            autocomplete="one-time-code"
                            autocapitalize="characters"
                            spellcheck="false"
                            required
                            aria-describedby="kb-activation-help"
                        >
                    </label>
                    <p id="kb-activation-help" class="kb-card-copy">
                        The code is securely sent to KernelBridge to link this business to its license. It is not stored by the product platform.
                    </p>
                    @error('activation_code')
                        <p class="kb-alert kb-alert-error" role="alert">{{ $message }}</p>
                    @enderror
                    @error('activationCode')
                        <p class="kb-alert kb-alert-error" role="alert">{{ $message }}</p>
                    @enderror
                    <div class="kb-actions">
                        <button type="submit" class="kb-button" wire:loading.attr="disabled">
                            {{ $license && $linkStatus === 'pending' ? 'Reconnect product account' : 'Connect license' }}
                        </button>
                    </div>
                </form>
            @endif
        </article>
    </section>
</main>
