{{-- Right rail (mockup 3A): money collected today, then the things that
     need someone's attention. --}}
@php
    $naItems = collect($needsAttention ?? [])->map(fn ($i) => [
        'title' => $i['label'],
        'sub' => $i['count'].' '.($i['count'] === 1 ? 'booking' : 'bookings'),
        'href' => $i['href'],
        'kind' => $i['urgency'] === 'critical' ? 'critical' : 'attention',
        'icon' => $i['urgency'] === 'critical' ? 'alert' : 'clock',
    ]);
    $dirtyCount = $attentionRows->count();
    if ($dirtyCount > 0) {
        $naItems->push([
            'title' => $dirtyCount.' '.($dirtyCount === 1 ? 'room needs' : 'rooms need').' cleaning sign-off',
            'sub' => $attentionRows->take(4)->map(fn ($r) => $r['inspection']->room?->room_number)->filter()->implode(', ')
                .($dirtyCount > 4 ? ' and more' : ''),
            'href' => route('tenant.housekeeping.inspections.index'),
            'kind' => 'info',
            'icon' => 'sparkle',
        ]);
    }
    $naIcons = [
        'alert' => '<path d="M10 3l8 14H2zM10 8v4M10 14.5v.5"/>',
        'clock' => '<circle cx="10" cy="10" r="7"/><path d="M10 6v4l3 2"/>',
        'sparkle' => '<path d="M10 2l1.6 4.4L16 8l-4.4 1.6L10 14l-1.6-4.4L4 8l4.4-1.6zM15.5 13l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/>',
    ];
@endphp
@php
    $th = \App\Support\Dashboard\TonightHouse::from(
        ['heatmap' => $heatmap ?? [], 'today' => $today ?? []],
        $attentionRows->pluck('inspection.room_id')->all(),
    );
    $thRooms = collect($th['groups'])->flatMap(fn ($g) => $g['bars'])->all();
    $thCounts = $th['counts'];
    $thStateLabels = \App\Support\Dashboard\TonightHouse::LABELS;
    $thOccupied = $th['occupied'];
    $thTotal = $th['total'];
@endphp
<div class="cx-rail">
    <section class="cx-card cx-na" data-testid="widget-needs-attention">
        <h2 class="cx-h2">Needs attention</h2>
        @if ($naItems->isEmpty())
            <p data-testid="needs-none" class="cx-na-none">You're clear. Nothing urgent right now.</p>
        @else
            <ul class="cx-na-list">
                @foreach ($naItems as $item)
                    <li class="cx-na-row" data-testid="needs-row">
                        <a href="{{ $item['href'] }}" wire:navigate>
                            <span class="cx-na-icon is-{{ $item['kind'] }}" aria-hidden="true">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $naIcons[$item['icon']] !!}</svg>
                            </span>
                            <span style="min-width: 0;">
                                <span class="cx-na-title">{{ $item['title'] }}</span>
                                @if ($item['sub'] !== '')<span class="cx-na-sub">{{ $item['sub'] }}</span>@endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Store requests / low stock. Renders nothing when there's
         nothing to say. --}}
    <livewire:dashboard.alerts-card />

    <section class="cx-card cx-rooms" data-testid="widget-tonight">
        <h2 class="cx-h2">Tonight's rooms</h2>
        @if ($thRooms === [])
            <p class="cx-na-none">Add rooms to see tonight's rooms here.</p>
        @else
            <div class="cx-rooms-grid" role="img" aria-label="{{ $thOccupied }} of {{ $thTotal }} rooms occupied tonight">
                @foreach ($thRooms as $bar)
                    <span class="is-{{ $bar['state'] }}" title="{{ $bar['title'] ?? '' }}"></span>
                @endforeach
            </div>
            <p class="cx-rooms-key">{{ collect($thCounts)->filter()->map(fn ($n, $key) => $thStateLabels[$key].' '.$n)->implode(' · ') }}</p>
        @endif
    </section>
</div>
