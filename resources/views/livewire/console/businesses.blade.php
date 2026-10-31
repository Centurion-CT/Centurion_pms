{{-- Every business on the platform (App\Livewire\Console\Businesses). --}}
@php($suffix = config('keverna.tenant_domain_suffix'))
<div>
    <div style="display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
        <div>
            <h1>Businesses</h1>
            <p class="lead">{{ $counts['all'] }} on {{ config('brand.name') }}. Pausing one closes its booking site and signs its staff out until you resume it.</p>
        </div>
        <button class="btn btn-quiet" type="button" wire:click="signOut">Sign out {{ $admin }}</button>
    </div>

    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 24px 0 16px;">
        @foreach (\App\Livewire\Console\Businesses::VIEWS as $key => $label)
            <button type="button" wire:click="$set('show', '{{ $key }}')" class="btn {{ $show === $key ? 'btn-primary' : 'btn-quiet' }}" style="height: 34px;" aria-pressed="{{ $show === $key ? 'true' : 'false' }}">
                {{ $label }} <span style="opacity: .7; margin-left: 6px;">{{ $counts[$key] }}</span>
            </button>
        @endforeach
        <input class="input" type="search" wire:model.live.debounce.300ms="q" placeholder="Search name, address or email" aria-label="Search businesses" style="max-width: 300px; height: 34px; margin-left: auto;">
    </div>

    <div class="card" style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 14px; min-width: 920px;">
            <thead>
                <tr style="text-align: left; color: var(--ink-500); font-size: 12px; font-weight: 600;">
                    <th style="padding: 12px 16px;">Business</th>
                    <th style="padding: 12px 8px;">Set up</th>
                    <th style="padding: 12px 8px;">Bookings</th>
                    <th style="padding: 12px 8px;">Needs a look</th>
                    <th style="padding: 12px 16px; text-align: right;"><span class="sr-only" style="position:absolute;left:-9999px">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php($t = $row->tenant)
                    @php($paused = $t->status === \App\Enums\TenantStatus::Locked)
                    <tr wire:key="biz-{{ $t->id }}" style="border-top: 1px solid var(--line); vertical-align: top; {{ $paused ? 'background: #FBFCFD;' : '' }}">
                        <td style="padding: 14px 16px;">
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <b style="color: var(--ink-900);">{{ $t->name }}</b>
                                @if ($paused)<span class="tag tag-bad">Paused</span>@endif
                            </div>
                            <a href="https://{{ $t->slug.$suffix }}" target="_blank" rel="noopener" class="meta" style="color: var(--blue);">{{ $t->slug.$suffix }}</a>
                            @if ($t->custom_domain)
                                <div class="meta">{{ $t->custom_domain }} · {{ $t->ownDomainIsLive() ? 'live' : ($t->domain_verified_at ? 'verified, not live' : 'waiting for DNS') }}</div>
                            @endif
                            <div class="meta">{{ $t->email }}@if ($t->phone) · {{ $t->phone }}@endif · joined {{ $t->created_at?->format('j M Y') }}</div>
                        </td>
                        <td style="padding: 14px 8px;">
                            <div>{{ $row->liveBuildings }} of {{ $row->buildings }} {{ \Illuminate\Support\Str::plural('building', $row->buildings) }} live</div>
                            <div class="meta">{{ $row->types }} apartment {{ \Illuminate\Support\Str::plural('type', $row->types) }} · {{ $row->staff }} staff</div>
                        </td>
                        <td style="padding: 14px 8px;">
                            <div>{{ $row->bookings30d }} in 30 days</div>
                            <div class="meta">{{ $row->lastBookingAt ? 'last '.$row->lastBookingAt->diffForHumans() : 'none yet' }}</div>
                        </td>
                        <td style="padding: 14px 8px;">
                            @forelse (array_filter($row->issues, fn ($i) => $i !== 'Paused') as $issue)
                                <div style="margin-bottom: 4px;"><span class="tag tag-warn" style="white-space: normal; height: auto; padding: 3px 8px;">{{ $issue }}</span></div>
                            @empty
                                <span class="tag tag-ok">All set</span>
                            @endforelse
                        </td>
                        <td style="padding: 14px 16px; text-align: right;">
                            @if ($paused)
                                <button class="btn btn-quiet" type="button" wire:click="resume({{ $t->id }})" wire:confirm="Resume {{ $t->name }}? Its booking site reopens and staff can sign in again.">Resume</button>
                            @else
                                <button class="btn btn-quiet btn-danger" type="button" wire:click="pause({{ $t->id }})" wire:confirm="Pause {{ $t->name }}? Its booking site stops taking bookings and its staff are signed out until you resume it.">Pause</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding: 32px 16px; text-align: center;" class="meta">No businesses match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
