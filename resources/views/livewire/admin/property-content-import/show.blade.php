<div class="lp-dash">
    <div>
        @include('_partials._back-button')
        <div class="mt-4">
            <span class="lp-eyebrow-strip">Content import</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                Concierge setup, {{ $property->name }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft); max-width: 60ch;">
                Paste any subset of the operator's public listing URLs. We fetch structured
                content, you review + approve field-by-field, the operator gets an email + in-app
                notification, and their stays page lands populated instead of blank.
            </p>
        </div>
    </div>

    @if (session('status'))
        <div class="lp-dash-card" style="border-left: 4px solid var(--color-accent-deep);">
            <p class="text-[13.5px]" style="color: var(--color-ink);">{{ session('status') }}</p>
        </div>
    @endif

    {{-- Surface the most recent Failed import's error, otherwise the
         admin sees no visible signal of why nothing appeared. --}}
    @php
        $lastFailed = $imports->firstWhere('status', \App\Enums\ContentImportStatus::Failed);
    @endphp
    @if ($lastFailed !== null && $lastFailed->error_message)
        <div class="lp-dash-card" style="border-left: 4px solid #B85C42;" data-testid="content-import-last-error">
            <p class="lp-mono text-[10.5px]" style="color: #B85C42; letter-spacing: 0.08em; text-transform: uppercase;">
                Last import failed · {{ $lastFailed->created_at?->diffForHumans() }}
            </p>
            <p class="text-[13px] mt-1" style="color: var(--color-ink);">
                {{ $lastFailed->error_message }}
            </p>
            <p class="text-[11.5px] mt-1" style="color: var(--color-ink-mute);">
                Check the URL is public + your API keys in .env (ANTHROPIC_API_KEY / GOOGLE_PLACES_API_KEY).
                Full detail on <a href="{{ route('admin.ai-spend.index') }}" wire:navigate style="text-decoration: underline;">AI spend</a>.
            </p>
        </div>
    @endif

    @if ($budgetOverSoft)
        <div class="lp-dash-card"
             style="border-left: 4px solid #B85C42;"
             data-testid="ai-spend-warn">
            <p class="text-[13.5px]" style="color: var(--color-ink);">
                Monthly AI spend has crossed the soft cap.
                <a href="{{ route('admin.ai-spend.index') }}" wire:navigate style="text-decoration: underline;">Open the audit log</a>
                to see the breakdown before continuing.
            </p>
        </div>
    @endif

    {{-- URL entry form --}}
    <section class="lp-dash-card" data-testid="content-import-form" style="display: grid; gap: 12px;">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Fetch from listings</span>
            <span class="lp-dash-card-meta">One-shot per property. No cron, no re-poll.</span>
        </div>
        <div>
            <label class="lp-form-label" for="ci-google">Google Business URL</label>
            <input id="ci-google" type="url" wire:model.defer="googleUrl"
                   placeholder="https://maps.app.goo.gl/..."
                   data-testid="content-import-google"
                   class="lp-input">
        </div>
        <div>
            <label class="lp-form-label" for="ci-booking">Booking.com listing URL</label>
            <input id="ci-booking" type="url" wire:model.defer="bookingUrl"
                   placeholder="https://www.booking.com/hotel/..."
                   data-testid="content-import-booking"
                   class="lp-input">
        </div>
        <div>
            <label class="lp-form-label" for="ci-airbnb">Airbnb listing URL</label>
            <input id="ci-airbnb" type="url" wire:model.defer="airbnbUrl"
                   placeholder="https://www.airbnb.com/rooms/..."
                   data-testid="content-import-airbnb"
                   class="lp-input">
        </div>
        @error('googleUrl') <p class="lp-form-error">{{ $message }}</p> @enderror
        @error('accept') <p class="lp-form-error">{{ $message }}</p> @enderror
        <div class="flex items-center gap-3">
            <button type="button"
                    wire:click="dispatchImport"
                    wire:loading.attr="disabled"
                    wire:target="dispatchImport"
                    class="lp-btn lp-btn--primary"
                    data-testid="content-import-fetch">
                <span wire:loading.remove wire:target="dispatchImport">Fetch content</span>
                <span wire:loading wire:target="dispatchImport">Fetching, this can take ~10-30s…</span>
            </button>
            <span wire:loading wire:target="dispatchImport"
                  class="text-[12px]" style="color: var(--color-ink-mute);">
                Extractors are hitting Google Places + Claude Sonnet 4.6 now.
                Don&rsquo;t close the tab.
            </span>
        </div>
    </section>

    {{-- Review pane (Ready state only) --}}
    @if ($active !== null && ! empty($suggestedFlat))
        <section class="lp-dash-card" data-testid="content-import-review">
            <div class="lp-dash-card-head">
                <span class="lp-dash-card-title">Review &amp; approve</span>
                <span class="lp-dash-card-meta">
                    Suggestion #{{ $active->id }} · fetched {{ $active->fetched_at?->diffForHumans() }}
                </span>
            </div>
            @if ($active->error_message)
                <p class="text-[12px]" style="color: #B85C42;">
                    Partial: {{ $active->error_message }}
                </p>
            @endif
            <div class="overflow-x-auto">
                <table class="lp-table" data-testid="content-import-review-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"></th>
                            <th>Field</th>
                            <th>Current on property</th>
                            <th>Suggested from listings</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($suggestedFlat as $field => $value)
                            <tr>
                                <td>
                                    <input type="checkbox"
                                           wire:model.defer="accept.{{ $field }}"
                                           data-testid="content-import-accept-{{ $field }}">
                                </td>
                                <td class="lp-mono text-[11px]" style="color: var(--color-ink-mute); text-transform: uppercase;">
                                    {{ $field }}
                                </td>
                                <td class="text-[12.5px]" style="color: var(--color-ink-mute); vertical-align: top;">
                                    @php $current = $property->{$field} ?? null; @endphp
                                    @if (is_array($current))
                                        {{ implode(', ', array_slice($current, 0, 6)) }}
                                    @elseif ($current)
                                        {{ \Illuminate\Support\Str::limit((string) $current, 140) }}
                                    @else
                                        <em>— empty —</em>
                                    @endif
                                </td>
                                <td class="text-[12.5px]" style="color: var(--color-ink); vertical-align: top;">
                                    @if (is_array($value))
                                        {{ implode(', ', array_slice($value, 0, 8)) }}
                                    @else
                                        {{ \Illuminate\Support\Str::limit((string) $value, 260) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end gap-2 mt-3">
                <button type="button"
                        wire:click="reject({{ $active->id }})"
                        class="lp-btn"
                        data-testid="content-import-reject">
                    Reject
                </button>
                <button type="button"
                        wire:click="approve({{ $active->id }})"
                        class="lp-btn lp-btn--primary"
                        data-testid="content-import-approve">
                    Approve &amp; publish
                </button>
            </div>
        </section>
    @endif

    {{-- History --}}
    <section class="lp-dash-card" data-testid="content-import-history">
        <div class="lp-dash-card-head">
            <span class="lp-dash-card-title">Import history</span>
            <span class="lp-dash-card-meta">last 10 attempts</span>
        </div>
        @if ($imports->isEmpty())
            <p class="text-[13.5px]" style="color: var(--color-ink-mute);">Nothing yet.</p>
        @else
            <table class="lp-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Sources</th>
                        <th>Status</th>
                        <th>By admin</th>
                        <th>Notified</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($imports as $row)
                        <tr>
                            <td class="lp-mono text-[11px]" style="color: var(--color-ink-soft); white-space: nowrap;">
                                {{ $row->created_at?->format('M j · g:i A') }}
                            </td>
                            <td class="text-[12px]" style="color: var(--color-ink-mute);">
                                {{ collect([
                                    $row->google_url ? 'Google' : null,
                                    $row->booking_url ? 'Booking' : null,
                                    $row->airbnb_url ? 'Airbnb' : null,
                                ])->filter()->implode(' · ') }}
                            </td>
                            <td>
                                <span class="lp-mono text-[10.5px]"
                                      style="padding: 3px 8px; border-radius: 999px; letter-spacing: 0.06em; text-transform: uppercase;
                                             background: {{ $row->status === \App\Enums\ContentImportStatus::Approved ? '#D1FAE5' : (in_array($row->status, [\App\Enums\ContentImportStatus::Failed, \App\Enums\ContentImportStatus::Rejected]) ? '#FEE2E2' : '#FEF3C7') }};
                                             color: {{ $row->status === \App\Enums\ContentImportStatus::Approved ? '#065F46' : (in_array($row->status, [\App\Enums\ContentImportStatus::Failed, \App\Enums\ContentImportStatus::Rejected]) ? '#7F1D1D' : '#92400E') }};">
                                    {{ $row->status->label() }}
                                </span>
                            </td>
                            <td class="text-[12.5px]" style="color: var(--color-ink-soft);">
                                {{ $row->runByAdmin?->name ?? '-' }}
                            </td>
                            <td class="text-[11px]" style="color: var(--color-ink-mute);">
                                {{ $row->notified_operator_at?->diffForHumans() ?? '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
