{{--
    One-time install wizard. Runs when the DB has no Tenant row yet.
    Submit → SeedInstallation → operator logged in → dashboard.
--}}
<div style="min-height: 100vh; padding: 32px 20px; background: #FAF7F2;
            color: #1a1a1a;
            font-family: 'Inter', system-ui, sans-serif;
            display: flex; align-items: flex-start; justify-content: center;">
    <div style="width: 100%; max-width: 640px;">
        <div style="text-align: center; margin-bottom: 28px;">
            <p style="text-transform: uppercase; letter-spacing: 0.16em;
                      font-size: 11px; color: #8AAE3A; font-weight: 600;">
                Keverna Solo &middot; Install
            </p>
            <h1 style="font-family: 'Playfair Display', Georgia, serif;
                       font-size: clamp(28px, 5vw, 36px); font-weight: 600;
                       margin-top: 10px; line-height: 1.1;">
                Let's set your hotel up.
            </h1>
            <p style="font-size: 14px; color: rgba(0,0,0,0.65);
                      margin-top: 12px; line-height: 1.55;
                      max-width: 460px; margin-left: auto; margin-right: auto;">
                Name the hotel, add its first branch, create the first Manager
                account. We'll hand you the dashboard as soon as you're done.
            </p>
        </div>

        @if ($submitError)
            <div role="alert" data-testid="setup-submit-error"
                 style="margin-bottom: 20px; padding: 12px 16px;
                        background: #FEF2F2; border: 1px solid #FCA5A5;
                        border-radius: 10px; color: #B91C1C; font-size: 13.5px;">
                {{ $submitError }}
            </div>
        @endif

        <form wire:submit.prevent="submit"
              style="background: #fff;
                     border: 1px solid rgba(0,0,0,0.08);
                     border-radius: 16px; padding: 28px;
                     display: flex; flex-direction: column; gap: 22px;">

            {{-- Section: hotel identity --}}
            <section style="display: flex; flex-direction: column; gap: 12px;">
                <header>
                    <p style="text-transform: uppercase; letter-spacing: 0.14em;
                              font-size: 10.5px; color: rgba(0,0,0,0.5); font-weight: 600;">
                        Hotel
                    </p>
                </header>
                <label style="display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-size: 12.5px; color: rgba(0,0,0,0.7);">Name</span>
                    <input type="text" wire:model="hotelName"
                           data-testid="setup-hotel-name"
                           autocomplete="organization"
                           placeholder="e.g. Citistay Apartments"
                           style="padding: 10px 12px; border-radius: 10px;
                                  border: 1px solid rgba(0,0,0,0.12);
                                  font-family: inherit; font-size: 14px;">
                    @error('hotelName')
                        <span style="font-size: 12px; color: #B91C1C;">{{ $message }}</span>
                    @enderror
                </label>
            </section>

            {{-- Section: first property --}}
            <section style="display: flex; flex-direction: column; gap: 12px;
                            padding-top: 18px;
                            border-top: 1px solid rgba(0,0,0,0.08);">
                <header>
                    <p style="text-transform: uppercase; letter-spacing: 0.14em;
                              font-size: 10.5px; color: rgba(0,0,0,0.5); font-weight: 600;">
                        First branch
                    </p>
                    <p style="font-size: 12px; color: rgba(0,0,0,0.5); margin-top: 4px;">
                        You can add more branches from the dashboard later.
                    </p>
                </header>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <label style="display: flex; flex-direction: column; gap: 6px;">
                        <span style="font-size: 12.5px; color: rgba(0,0,0,0.7);">Branch name</span>
                        <input type="text" wire:model="propertyName"
                               data-testid="setup-property-name"
                               placeholder="e.g. Main Building"
                               style="padding: 10px 12px; border-radius: 10px;
                                      border: 1px solid rgba(0,0,0,0.12);
                                      font-family: inherit; font-size: 14px;">
                        @error('propertyName')
                            <span style="font-size: 12px; color: #B91C1C;">{{ $message }}</span>
                        @enderror
                    </label>

                    <label style="display: flex; flex-direction: column; gap: 6px;">
                        <span style="font-size: 12.5px; color: rgba(0,0,0,0.7);">Type</span>
                        <select wire:model="propertyType"
                                data-testid="setup-property-type"
                                style="padding: 10px 12px; border-radius: 10px;
                                       border: 1px solid rgba(0,0,0,0.12);
                                       font-family: inherit; font-size: 14px;
                                       background: #fff;">
                            @foreach ($propertyTypeOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        @error('propertyType')
                            <span style="font-size: 12px; color: #B91C1C;">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <label style="display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-size: 12.5px; color: rgba(0,0,0,0.7);">Street address</span>
                    <input type="text" wire:model="propertyAddress"
                           data-testid="setup-property-address"
                           autocomplete="street-address"
                           placeholder="Plot 12, Adetokunbo Ademola Crescent"
                           style="padding: 10px 12px; border-radius: 10px;
                                  border: 1px solid rgba(0,0,0,0.12);
                                  font-family: inherit; font-size: 14px;">
                    @error('propertyAddress')
                        <span style="font-size: 12px; color: #B91C1C;">{{ $message }}</span>
                    @enderror
                </label>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <label style="display: flex; flex-direction: column; gap: 6px;">
                        <span style="font-size: 12.5px; color: rgba(0,0,0,0.7);">City</span>
                        <input type="text" wire:model="propertyCity"
                               data-testid="setup-property-city"
                               autocomplete="address-level2"
                               placeholder="Abuja"
                               style="padding: 10px 12px; border-radius: 10px;
                                      border: 1px solid rgba(0,0,0,0.12);
                                      font-family: inherit; font-size: 14px;">
                        @error('propertyCity')
                            <span style="font-size: 12px; color: #B91C1C;">{{ $message }}</span>
                        @enderror
                    </label>

                    <label style="display: flex; flex-direction: column; gap: 6px;">
                        <span style="font-size: 12.5px; color: rgba(0,0,0,0.7);">State</span>
                        <input type="text" wire:model="propertyState"
                               data-testid="setup-property-state"
                               autocomplete="address-level1"
                               placeholder="FCT"
                               style="padding: 10px 12px; border-radius: 10px;
                                      border: 1px solid rgba(0,0,0,0.12);
                                      font-family: inherit; font-size: 14px;">
                        @error('propertyState')
                            <span style="font-size: 12px; color: #B91C1C;">{{ $message }}</span>
                        @enderror
                    </label>
                </div>
            </section>

            {{-- Section: first manager --}}
            <section style="display: flex; flex-direction: column; gap: 12px;
                            padding-top: 18px;
                            border-top: 1px solid rgba(0,0,0,0.08);">
                <header>
                    <p style="text-transform: uppercase; letter-spacing: 0.14em;
                              font-size: 10.5px; color: rgba(0,0,0,0.5); font-weight: 600;">
                        Manager account
                    </p>
                    <p style="font-size: 12px; color: rgba(0,0,0,0.5); margin-top: 4px;">
                        The first Manager owns everything. You can invite more staff
                        (front desk, housekeeping, POS) from the Staff page later.
                    </p>
                </header>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <label style="display: flex; flex-direction: column; gap: 6px;">
                        <span style="font-size: 12.5px; color: rgba(0,0,0,0.7);">Full name</span>
                        <input type="text" wire:model="managerName"
                               data-testid="setup-manager-name"
                               autocomplete="name"
                               placeholder="Ada Manager"
                               style="padding: 10px 12px; border-radius: 10px;
                                      border: 1px solid rgba(0,0,0,0.12);
                                      font-family: inherit; font-size: 14px;">
                        @error('managerName')
                            <span style="font-size: 12px; color: #B91C1C;">{{ $message }}</span>
                        @enderror
                    </label>

                    <label style="display: flex; flex-direction: column; gap: 6px;">
                        <span style="font-size: 12.5px; color: rgba(0,0,0,0.7);">Email</span>
                        <input type="email" wire:model="managerEmail"
                               data-testid="setup-manager-email"
                               autocomplete="email"
                               placeholder="you@example.com"
                               style="padding: 10px 12px; border-radius: 10px;
                                      border: 1px solid rgba(0,0,0,0.12);
                                      font-family: inherit; font-size: 14px;">
                        @error('managerEmail')
                            <span style="font-size: 12px; color: #B91C1C;">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <label style="display: flex; flex-direction: column; gap: 6px;">
                        <span style="font-size: 12.5px; color: rgba(0,0,0,0.7);">
                            Password
                            <span style="color: rgba(0,0,0,0.4); font-weight: 400;">(min 10 characters)</span>
                        </span>
                        <input type="password" wire:model="managerPassword"
                               data-testid="setup-manager-password"
                               autocomplete="new-password"
                               style="padding: 10px 12px; border-radius: 10px;
                                      border: 1px solid rgba(0,0,0,0.12);
                                      font-family: inherit; font-size: 14px;">
                        @error('managerPassword')
                            <span style="font-size: 12px; color: #B91C1C;">{{ $message }}</span>
                        @enderror
                    </label>

                    <label style="display: flex; flex-direction: column; gap: 6px;">
                        <span style="font-size: 12.5px; color: rgba(0,0,0,0.7);">Confirm password</span>
                        <input type="password" wire:model="managerPasswordConfirmation"
                               data-testid="setup-manager-password-confirm"
                               autocomplete="new-password"
                               style="padding: 10px 12px; border-radius: 10px;
                                      border: 1px solid rgba(0,0,0,0.12);
                                      font-family: inherit; font-size: 14px;">
                    </label>
                </div>
            </section>

            <div style="display: flex; align-items: center; justify-content: space-between;
                        gap: 12px; padding-top: 6px;">
                <p style="font-size: 12px; color: rgba(0,0,0,0.5);">
                    You can change any of this later from Settings.
                </p>
                <button type="submit"
                        data-testid="setup-submit"
                        wire:loading.attr="disabled"
                        style="background: #1a1a1a; color: #fff;
                               border: 0; border-radius: 999px;
                               padding: 12px 24px;
                               font-family: inherit; font-size: 13.5px; font-weight: 600;
                               cursor: pointer;">
                    <span wire:loading.remove wire:target="submit">Finish install &rarr;</span>
                    <span wire:loading wire:target="submit">Installing…</span>
                </button>
            </div>
        </form>

        <p style="text-align: center; margin-top: 20px; font-size: 12px; color: rgba(0,0,0,0.4);">
            Keverna Solo &middot; single-tenant install &middot;
            need help?
            <a href="mailto:setup@keverna.com" style="color: inherit; text-decoration: underline;">
                setup@keverna.com
            </a>
        </p>
    </div>
</div>
