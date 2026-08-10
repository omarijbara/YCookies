<x-filament-panels::page>
    <div style="max-width: 42rem; margin: 0 auto;">
        @if($group && $group->subscribed('default'))
            {{-- Subscribed state --}}
            <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 1rem; padding: 2rem;">
                <h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 1.5rem;">Your Plan</h2>

                <div style="display: flex; align-items: center; gap: 1rem; padding: 1rem; border-radius: 0.75rem; background: rgba(34,197,94,0.1); border: 1px solid rgba(34,197,94,0.2); margin-bottom: 1.5rem;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 2rem; height: 2rem; color: #22c55e; flex-shrink: 0;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                    </svg>
                    <div>
                        <p style="font-weight: 600; color: #22c55e;">Active Subscription</p>
                        <p style="font-size: 0.875rem; color: #9ca3af;">
                            @php
                                $sub = $group->subscription('default');
                                $planName = match($sub?->stripe_price) {
                                    'price_1T9PuUCqOt3Mipp1ZzEvHkJG' => 'Pro Monthly — $9/mo',
                                    'price_1T9PudCqOt3Mipp1bJUzv0EC' => 'Agency Monthly — $9/mo',
                                    'price_1T9PueCqOt3Mipp1qjPMUx7i' => 'Enterprise — $89/yr',
                                    default => 'Subscribed',
                                };
                            @endphp
                            {{ $planName }}
                        </p>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                    <div style="padding: 1rem; border-radius: 0.75rem; background: rgba(255,255,255,0.05);">
                        <p style="font-size: 0.875rem; color: #9ca3af;">Domains Used</p>
                        <p style="font-size: 1.5rem; font-weight: 700;">{{ $group->domains()->count() }}</p>
                    </div>
                    <div style="padding: 1rem; border-radius: 0.75rem; background: rgba(255,255,255,0.05);">
                        <p style="font-size: 0.875rem; color: #9ca3af;">Domain Limit</p>
                        <p style="font-size: 1.5rem; font-weight: 700;">
                            {{ $group->domain_limit >= 9999 ? '∞ Unlimited' : $group->domain_limit }}
                        </p>
                    </div>
                </div>

                <x-filament::button wire:click="manageSubscription" color="primary" size="lg" style="width: 100%;">
                    Manage Subscription on Stripe
                </x-filament::button>
            </div>

        @else
            {{-- Not subscribed state --}}
            <div style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 1rem; padding: 2rem;">
                <h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem;">Upgrade for More Domains</h2>
                <p style="color: #9ca3af; margin-bottom: 2rem;">
                    You're on the <span style="font-weight: 600;">Free</span> plan ({{ $group->domains()->count() }}/{{ $group->domain_limit }} domain{{ $group->domain_limit > 1 ? 's' : '' }}).
                    Upgrade to unlock more.
                </p>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div style="padding: 1.5rem; border-radius: 0.75rem; border: 1px solid rgba(255,255,255,0.1); text-align: center;">
                        <p style="font-size: 0.75rem; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.5rem;">Monthly</p>
                        <p style="font-size: 1.875rem; font-weight: 700; margin-bottom: 0.25rem;">$9<span style="font-size: 1rem; font-weight: 400; color: #9ca3af;">/mo</span></p>
                        <p style="font-size: 0.875rem; color: #9ca3af; margin-bottom: 1rem;">Up to 10 domains</p>
                        <x-filament::button wire:click="upgradeMonthly" color="primary" size="lg" style="width: 100%;">
                            Upgrade Monthly
                        </x-filament::button>
                    </div>
                    <div style="padding: 1.5rem; border-radius: 0.75rem; border: 2px solid #3b82f6; text-align: center; position: relative;">
                        <span style="position: absolute; top: -0.75rem; left: 50%; transform: translateX(-50%); background: #3b82f6; color: white; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.75rem; border-radius: 9999px;">BEST VALUE</span>
                        <p style="font-size: 0.75rem; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.5rem;">Yearly</p>
                        <p style="font-size: 1.875rem; font-weight: 700; margin-bottom: 0.25rem;">$89<span style="font-size: 1rem; font-weight: 400; color: #9ca3af;">/yr</span></p>
                        <p style="font-size: 0.875rem; color: #9ca3af; margin-bottom: 1rem;">Unlimited domains</p>
                        <x-filament::button wire:click="upgradeYearly" color="success" size="lg" style="width: 100%;">
                            Upgrade Yearly
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>