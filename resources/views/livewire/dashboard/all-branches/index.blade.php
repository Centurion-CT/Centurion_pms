{{-- All branches (approved mockup design/all-branches). Scoped to .cx-ab. --}}
@php
    use App\Services\Owner\AllBranchesOverview as AB;

    $money = $data['money'];
    $tonight = $data['tonight'];
    $win = $data['window'];
    $branchNames = collect($data['branches'])->pluck('name');
    $namesLine = $branchNames->count() > 1
        ? $branchNames->slice(0, -1)->implode(', ').' and '.$branchNames->last()
        : $branchNames->first();
    $periods = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'last_month' => 'Last month', 'custom' => 'Pick dates'];
    $barClass = fn (string $state) => match ($state) {
        'inhouse', 'overdue' => 'is-in',
        'arriving', 'booked' => 'is-ar',
        default => '',
    };
    $change = $money['collected_change_pct'];
    $dailyMax = max(1, (int) collect($data['daily'])->max('amount'));
    $best = collect($data['daily'])->sortByDesc('amount')->first();
    $todayStr = now()->toDateString();
    $attentionIcons = [
        'overdue' => ['rose', '<circle cx="8" cy="8" r="5.5"/><path d="M8 5v3.2l2 1.3"/>'],
        'approvals' => ['rose', '<rect x="3" y="4" width="10" height="8" rx="1.5"/><path d="M5.5 8h5"/>'],
        'requests' => ['amber', '<path d="M3 3h10v7H7l-4 3Z"/>'],
        'unsold' => ['blue', '<path d="M2.5 7.5 8 3l5.5 4.5V13h-11Z"/>'],
        'reviews' => ['gold', '<path d="m8 2.5 1.7 3.5 3.8.5-2.8 2.6.7 3.8L8 11l-3.4 1.9.7-3.8-2.8-2.6 3.8-.5Z"/>'],
    ];
@endphp
<div class="lp-dash cx-ab" data-testid="all-branches">
    <style>
        .lp-page .lp-dash.cx-ab { padding: 24px 32px 56px; gap: 22px; grid-template-columns: minmax(0, 1fr); }
        @media (max-width: 768px) { .lp-page .lp-dash.cx-ab { padding: 16px 16px 40px; gap: 16px; } }
        .cx-ab .ab-card { background: var(--color-card); border: 1px solid var(--color-line); border-radius: 12px; }
        .cx-ab .ab-h2 { margin: 0; font-family: var(--font-display); font-weight: 600; font-size: 19px; line-height: 1.2; color: var(--color-ink); }
        .cx-ab .ab-mute { color: var(--color-ink-mute); }
        .cx-ab .ab-soft { color: var(--color-ink-soft); }
        .cx-ab .ab-num { font-variant-numeric: tabular-nums; }

        .cx-ab .ab-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; }
        .cx-ab .ab-head h1 { margin: 2px 0 0; font-family: var(--font-display); font-weight: 600; font-size: clamp(26px, 2.6vw, 34px); line-height: 1.1; color: var(--color-ink); }
        .cx-ab .ab-head p { margin: 4px 0 0; color: var(--color-ink-soft); }
        .cx-ab .ab-seg { display: inline-flex; flex-wrap: wrap; gap: 2px; padding: 4px; border-radius: 10px; background: var(--color-card); border: 1px solid var(--color-line); }
        .cx-ab .ab-seg button { padding: 8px 14px; border: 0; border-radius: 7px; background: transparent; font: inherit; font-weight: 500; color: var(--color-ink-soft); cursor: pointer; }
        .cx-ab .ab-seg button:hover { color: var(--color-ink); }
        .cx-ab .ab-seg button[aria-pressed="true"] { background: var(--color-ink-deep); color: #fff; font-weight: 600; }
        .cx-ab .ab-seg button:focus-visible, .cx-ab .ab-open:focus-visible { outline: 3px solid var(--color-accent-soft); outline-offset: 2px; }
        .cx-ab .ab-dates { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 10px; justify-content: flex-end; }
        .cx-ab .ab-dates input { height: 38px; padding: 0 10px; border: 1px solid var(--color-line); border-radius: 8px; background: var(--color-card); color: var(--color-ink); font: inherit; }

        .cx-ab .ab-money { display: grid; grid-template-columns: 1.3fr repeat(5, 1fr); background: #16324B; border-radius: 12px; color: #fff; padding: 24px 28px; }
        .cx-ab .ab-money .c { padding: 0 20px; border-left: 1px solid rgba(255,255,255,.12); min-width: 0; }
        .cx-ab .ab-money .c:first-child { padding-left: 0; border-left: 0; }
        .cx-ab .ab-money .l { font-size: 13.5px; color: rgba(255,255,255,.74); }
        .cx-ab .ab-money .big { margin-top: 6px; font-family: var(--font-display); font-weight: 600; font-size: clamp(32px, 3.2vw, 44px); line-height: 1.05; }
        .cx-ab .ab-money .v { margin-top: 8px; font-family: var(--font-display); font-weight: 600; font-size: 24px; }
        .cx-ab .ab-money .n { margin-top: 6px; font-size: 13px; color: rgba(255,255,255,.68); }
        .cx-ab .ab-money .net .v { color: #E3A83A; }
        .cx-ab .ab-up { color: #7FD8B0; font-weight: 600; }
        .cx-ab .ab-down { color: #F5A3B8; font-weight: 600; }
        @media (max-width: 1180px) {
            .cx-ab .ab-money { grid-template-columns: repeat(3, 1fr); row-gap: 20px; }
            .cx-ab .ab-money .c:nth-child(4) { padding-left: 0; border-left: 0; }
        }
        @media (max-width: 640px) {
            .cx-ab .ab-money { grid-template-columns: 1fr 1fr; padding: 20px; }
            .cx-ab .ab-money .c { padding: 0 12px; }
            .cx-ab .ab-money .c:first-child { grid-column: 1 / -1; }
            .cx-ab .ab-money .c:nth-child(2n) { padding-left: 0; border-left: 0; }
        }

        .cx-ab .ab-tonight { padding: 24px 28px 22px; }
        .cx-ab .ab-tonight-top { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; }
        .cx-ab .ab-pct { font-family: var(--font-display); font-weight: 600; font-size: 40px; line-height: 1; color: var(--color-ink); text-align: right; }
        .cx-ab .ab-legend { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 10px; font-size: 13px; color: var(--color-ink-soft); }
        .cx-ab .ab-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .cx-ab .ab-sw { width: 10px; height: 10px; border-radius: 2px; background: var(--color-card-deep); border: 1px solid var(--color-line); }
        .cx-ab .ab-sw.is-in { background: #16324B; border-color: #16324B; }
        .cx-ab .ab-sw.is-ar { background: #6E9FCB; border-color: #6E9FCB; }
        .cx-ab .ab-groups { display: flex; gap: 28px; margin-top: 22px; align-items: flex-end; overflow-x: auto; }
        .cx-ab .ab-grp { min-width: 120px; }
        @media (max-width: 760px) {
            .cx-ab .ab-groups { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; overflow: visible; }
            .cx-ab .ab-grp { min-width: 0; }
            .cx-ab .ab-grp-meta { flex-direction: column; gap: 2px; }
            .cx-ab .ab-tonight { padding: 20px; }
        }
        .cx-ab .ab-bars { display: flex; align-items: flex-end; gap: 3px; height: 74px; }
        .cx-ab .ab-bars i { flex: 1 1 0; min-width: 3px; border-radius: 3px 3px 1px 1px; background: #DCE5EE; }
        .cx-ab .ab-bars i.is-in { background: #16324B; }
        .cx-ab .ab-bars i.is-ar { background: #6E9FCB; }
        .cx-ab .ab-grp-meta { display: flex; justify-content: space-between; gap: 8px; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--color-line-soft); font-size: 14px; }
        .cx-ab .ab-grp-meta b { font-weight: 600; color: var(--color-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .cx-ab .ab-table-wrap { position: relative; overflow-x: auto; }
        .cx-ab table { width: 100%; border-collapse: collapse; min-width: 860px; }
        .cx-ab th { padding: 0 16px 10px; text-align: right; font-weight: 500; font-size: 13px; color: var(--color-ink-mute); white-space: nowrap; }
        .cx-ab td { padding: 14px 16px; text-align: right; border-top: 1px solid var(--color-line-soft); font-variant-numeric: tabular-nums; color: var(--color-ink); }
        .cx-ab th:first-child, .cx-ab td:first-child { text-align: left; padding-left: 24px; }
        .cx-ab th:last-child, .cx-ab td:last-child { padding-right: 24px; }
        .cx-ab .ab-bn { font-weight: 600; font-size: 15px; }
        .cx-ab .ab-bs { font-size: 12.5px; color: var(--color-ink-mute); }
        .cx-ab .ab-occ { display: inline-flex; align-items: center; gap: 10px; }
        .cx-ab .ab-track { width: 84px; height: 6px; border-radius: 3px; background: #DCE5EE; overflow: hidden; }
        .cx-ab .ab-track i { display: block; height: 100%; background: #16324B; }
        .cx-ab .ab-owe { color: #C42652; font-weight: 600; }
        .cx-ab .ab-flag { display: inline-flex; min-width: 24px; height: 24px; padding: 0 7px; align-items: center; justify-content: center; border-radius: 999px; font-size: 12.5px; font-weight: 700; background: #FCE8EE; color: #C42652; }
        .cx-ab .ab-flag.is-zero { background: var(--color-card-deep); color: var(--color-ink-mute); font-weight: 500; }
        .cx-ab .ab-open { border: 0; background: none; font: inherit; font-weight: 600; color: var(--color-accent-deep); cursor: pointer; white-space: nowrap; }
        .cx-ab tr.ab-total td { font-weight: 700; background: var(--color-card-deep); border-top: 1px solid var(--color-line); }

        .cx-ab .ab-two { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; align-items: start; }
        .cx-ab .ab-three { display: grid; grid-template-columns: 1fr 1fr 1.15fr; gap: 22px; align-items: start; }
        @media (max-width: 1100px) { .cx-ab .ab-two, .cx-ab .ab-three { grid-template-columns: 1fr; } }
        .cx-ab .ab-pad { padding: 22px 24px; }
        .cx-ab .ab-list-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; margin-bottom: 6px; }
        .cx-ab .ab-item { display: grid; grid-template-columns: 34px 1fr auto; gap: 14px; align-items: start; padding: 14px 0; border-top: 1px solid var(--color-line-soft); }
        .cx-ab .ab-item:first-of-type { border-top: 0; }
        .cx-ab .ab-item.is-feed { grid-template-columns: 64px 1fr auto; }
        .cx-ab .ab-item-end { text-align: right; }
        @media (max-width: 640px) {
            .cx-ab .ab-item, .cx-ab .ab-item.is-feed { grid-template-columns: 52px 1fr; }
            .cx-ab .ab-item:not(.is-feed) { grid-template-columns: 34px 1fr; }
            .cx-ab .ab-item > :last-child { grid-column: 2; justify-content: flex-start; text-align: left; max-width: none; }
            .cx-ab .ab-item-end { display: flex; align-items: center; gap: 10px; }
            .cx-ab .ab-item-end > div { margin-top: 0 !important; }
        }
        .cx-ab .ab-ic { width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center; }
        .cx-ab .ab-ic.rose { background: #FCE8EE; color: #C42652; }
        .cx-ab .ab-ic.amber { background: #FDF0DC; color: #B86A05; }
        .cx-ab .ab-ic.blue { background: var(--color-accent-soft); color: var(--color-accent-deep); }
        .cx-ab .ab-ic.gold { background: #FBF1DE; color: #8A5A0B; }
        .cx-ab .ab-t { font-weight: 600; color: var(--color-ink); }
        .cx-ab .ab-s { margin-top: 2px; font-size: 13.5px; color: var(--color-ink-soft); }
        .cx-ab .ab-tags { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 6px; max-width: 220px; }
        .cx-ab .ab-tag { display: inline-flex; padding: 2px 9px; border-radius: 999px; font-size: 12.5px; font-weight: 600; white-space: nowrap; background: var(--color-card-deep); border: 1px solid var(--color-line-soft); color: var(--color-ink-soft); }
        .cx-ab .ab-empty { padding: 18px 0 8px; color: var(--color-ink-soft); }

        .cx-ab .ab-cols { display: flex; align-items: flex-end; gap: 4px; height: 150px; margin-top: 18px; border-bottom: 1px solid var(--color-line); }
        .cx-ab .ab-cols i { flex: 1 1 0; min-width: 2px; background: #6E9FCB; border-radius: 3px 3px 0 0; }
        .cx-ab .ab-cols i.is-today { background: #16324B; }
        .cx-ab .ab-axis { display: flex; justify-content: space-between; margin-top: 8px; font-size: 12.5px; color: var(--color-ink-mute); }

        .cx-ab .ab-hbar { margin-top: 16px; }
        .cx-ab .ab-hbar .l { display: flex; justify-content: space-between; gap: 10px; font-size: 14px; color: var(--color-ink); }
        .cx-ab .ab-hbar .l b { font-weight: 600; white-space: nowrap; }
        .cx-ab .ab-hbar .tr { height: 8px; margin-top: 7px; border-radius: 4px; background: var(--color-card-deep); overflow: hidden; }
        .cx-ab .ab-hbar .tr i { display: block; height: 100%; border-radius: 4px; background: var(--color-accent); }
        .cx-ab .ab-owes-row { display: grid; grid-template-columns: 1fr auto; gap: 10px; padding: 12px 0; border-top: 1px solid var(--color-line-soft); }
        .cx-ab .ab-owes-row:first-of-type { border-top: 0; }
        .cx-ab .ab-owes-row .d { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 2px; font-size: 13px; color: var(--color-ink-mute); }
    </style>

    {{-- Heading + period --}}
    <div class="ab-head">
        <div>
            <div class="ab-soft">{{ now()->format('l, j F') }} · updated {{ now()->format('g:i a') }}</div>
            <h1>All branches</h1>
            <p>Money and activity across {{ $namesLine }}.</p>
        </div>
        <div>
            <div class="ab-seg" role="group" aria-label="Period">
                @foreach ($periods as $key => $label)
                    <button type="button" wire:click="setPeriod('{{ $key }}')"
                            aria-pressed="{{ $period === $key ? 'true' : 'false' }}"
                            data-testid="ab-period-{{ $key }}">{{ $label }}</button>
                @endforeach
            </div>
            @if ($period === 'custom')
                <div class="ab-dates">
                    <label class="ab-soft" for="ab-from">From</label>
                    <input id="ab-from" type="date" wire:model.live="from" max="{{ $todayStr }}">
                    <label class="ab-soft" for="ab-to">to</label>
                    <input id="ab-to" type="date" wire:model.live="to" max="{{ $todayStr }}">
                </div>
            @endif
        </div>
    </div>

    {{-- Money for the chosen period --}}
    <section class="ab-money" data-testid="ab-money">
        <div class="c">
            <div class="l">Collected {{ $win['label'] }}</div>
            <div class="big ab-num" data-testid="ab-collected">{{ AB::short($money['collected']) }}</div>
            <div class="n">
                @if ($change !== null)
                    <span class="{{ $change >= 0 ? 'ab-up' : 'ab-down' }}">{{ $change >= 0 ? '↑' : '↓' }} {{ abs(round($change)) }}%</span>
                    on {{ $money['previous_label'] }}
                @else
                    Nothing collected {{ $money['previous_label'] }} to compare with
                @endif
            </div>
        </div>
        <div class="c"><div class="l">Billed</div><div class="v ab-num">{{ AB::short($money['billed']) }}</div><div class="n">Stays starting {{ $win['label'] === 'today' ? 'today' : 'in this period' }}</div></div>
        <div class="c"><div class="l">Still to collect</div><div class="v ab-num" data-testid="ab-owed">{{ AB::short($money['owed']) }}</div>
            <div class="n">{{ $money['owed_stays'] }} {{ $money['owed_stays'] === 1 ? 'stay' : 'stays' }}@if ($money['owed_companies'] > 0), {{ $money['owed_companies'] }} {{ $money['owed_companies'] === 1 ? 'company' : 'companies' }}@endif, as of now</div></div>
        <div class="c"><div class="l">Refunded</div><div class="v ab-num">{{ AB::short($money['refunded']) }}</div><div class="n">{{ $money['refunds_count'] }} {{ $money['refunds_count'] === 1 ? 'refund' : 'refunds' }}</div></div>
        <div class="c"><div class="l">Expenses</div><div class="v ab-num">{{ AB::short($money['expenses']) }}</div><div class="n">Purchases and bills</div></div>
        <div class="c net"><div class="l">Net</div><div class="v ab-num" data-testid="ab-net">{{ AB::short($money['net']) }}</div><div class="n">Collected − refunds − expenses</div></div>
    </section>

    {{-- Tonight across the group --}}
    <section class="ab-card ab-tonight" data-testid="ab-tonight">
        <div class="ab-tonight-top">
            <div>
                <h2 class="ab-h2">Tonight across the group</h2>
                <p class="ab-soft" style="margin: 4px 0 0;">Each bar is a room. {{ $tonight['occupied'] }} of {{ $tonight['total'] }} {{ $tonight['total'] === 1 ? 'room is' : 'rooms are' }} taken tonight.</p>
            </div>
            <div>
                <div class="ab-pct ab-num" data-testid="ab-tonight-pct">{{ $tonight['pct'] }}%</div>
                <div class="ab-legend">
                    <span><i class="ab-sw is-in"></i>In-house {{ $tonight['in_house'] }}</span>
                    <span><i class="ab-sw is-ar"></i>Arriving or reserved {{ $tonight['arriving'] }}</span>
                    <span><i class="ab-sw"></i>Free {{ $tonight['free'] }}</span>
                </div>
            </div>
        </div>
        <div class="ab-groups" role="img" aria-label="Tonight: {{ collect($data['branches'])->map(fn ($b) => $b['name'].' '.$b['house']['pct'].'% full')->implode(', ') }}.">
            @foreach ($data['branches'] as $b)
                <div class="ab-grp" style="flex: {{ max(1, $b['house']['total']) }} 1 0;">
                    <div class="ab-bars">
                        @foreach (collect($b['house']['groups'])->flatMap(fn ($g) => $g['bars']) as $bar)
                            <i class="{{ $barClass($bar['state']) }}" style="height: {{ $bar['height'] }}%;" title="{{ $bar['title'] }}"></i>
                        @endforeach
                    </div>
                    <div class="ab-grp-meta">
                        <b title="{{ $b['name'] }}">{{ $b['name'] }}</b>
                        <span class="ab-soft ab-num" style="white-space: nowrap;">
                            @if ($b['house']['total'] > 0)
                                {{ $b['house']['pct'] }}% · {{ $b['house']['occupied'] }} of {{ $b['house']['total'] }}
                            @else
                                No rooms yet
                            @endif
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Branch by branch --}}
    <section class="ab-card" style="padding: 22px 0 0;" data-testid="ab-branches">
        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 8px; padding: 0 24px 16px;">
            <h2 class="ab-h2">Branch by branch</h2>
            <span class="ab-mute">Collected is for {{ $win['label'] === 'today' ? 'today' : 'the period above' }}. Still to collect is as of now.</span>
        </div>
        <div class="ab-table-wrap">
            <table>
                <thead>
                    <tr><th>Branch</th><th>Tonight</th><th>Arriving</th><th>Leaving</th><th>In-house</th><th>Collected</th><th>Still to collect</th><th>Needs attention</th><th><span class="sr-only">Open</span></th></tr>
                </thead>
                <tbody>
                    @foreach ($data['branches'] as $b)
                        <tr data-testid="ab-branch-{{ $b['property']->slug }}">
                            <td>
                                <div class="ab-bn">{{ $b['name'] }}</div>
                                <div class="ab-bs">{{ $loop->first ? 'Main branch · ' : '' }}{{ $b['sub'] }}</div>
                            </td>
                            <td><span class="ab-occ"><span class="ab-track"><i style="width: {{ $b['house']['pct'] }}%"></i></span>{{ $b['house']['pct'] }}%</span></td>
                            <td>{{ $b['arriving'] }}</td>
                            <td>{{ $b['leaving'] }}</td>
                            <td>{{ $b['in_house'] }}</td>
                            <td>{{ AB::short($b['collected']) }}</td>
                            <td class="{{ $b['owed'] > 0 ? 'ab-owe' : '' }}">{{ AB::short($b['owed']) }}</td>
                            <td><span class="ab-flag {{ $b['attention'] === 0 ? 'is-zero' : '' }}">{{ $b['attention'] }}</span></td>
                            <td>
                                @if (isset($canOpen[(int) $b['property']->id]))
                                    <form method="POST" action="{{ route('tenant.branches.switch') }}" style="margin: 0;">
                                        @csrf
                                        <input type="hidden" name="property_id" value="{{ $b['property']->id }}">
                                        <input type="hidden" name="return_to" value="{{ route('tenant.dashboard') }}">
                                        <button type="submit" class="ab-open" data-testid="ab-open-{{ $b['property']->slug }}">Open →</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @if (count($data['branches']) > 1)
                        <tr class="ab-total">
                            <td>All branches</td>
                            <td>{{ $tonight['pct'] }}%</td>
                            <td>{{ array_sum(array_column($data['branches'], 'arriving')) }}</td>
                            <td>{{ array_sum(array_column($data['branches'], 'leaving')) }}</td>
                            <td>{{ array_sum(array_column($data['branches'], 'in_house')) }}</td>
                            <td>{{ AB::short((int) array_sum(array_column($data['branches'], 'collected'))) }}</td>
                            <td class="ab-owe">{{ AB::short($money['owed']) }}</td>
                            <td>{{ array_sum(array_column($data['branches'], 'attention')) }}</td>
                            <td></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </section>

    <div class="ab-two">
        {{-- Needs attention --}}
        <section class="ab-card ab-pad" data-testid="ab-attention">
            <div class="ab-list-head">
                <h2 class="ab-h2">Needs attention</h2>
            </div>
            @forelse ($data['attention'] as $item)
                @php [$tone, $path] = $attentionIcons[$item['kind']]; @endphp
                <div class="ab-item" data-testid="ab-attention-{{ $item['kind'] }}">
                    <span class="ab-ic {{ $tone }}"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $path !!}</svg></span>
                    <div><div class="ab-t">{{ $item['title'] }}</div><div class="ab-s">{{ $item['subtitle'] }}</div></div>
                    <div class="ab-tags">
                        @foreach ($item['tags'] as $tag)
                            <span class="ab-tag">{{ $tag['branch'] }}@if (count($item['tags']) > 1) {{ $tag['count'] }}@endif</span>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="ab-empty">Nothing needs you right now, at any branch.</p>
            @endforelse
        </section>

        {{-- Live activity polls on its own --}}
        <livewire:dashboard.all-branches.activity />
    </div>

    {{-- Collected each day --}}
    <section class="ab-card ab-pad" data-testid="ab-daily">
        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 8px;">
            <h2 class="ab-h2">Collected each day <span class="ab-mute" style="font-family: var(--font-sans); font-size: 14px; font-weight: 500; margin-left: 8px;">All branches, {{ AB::rangeLabel($win['from'], $win['to']) }}</span></h2>
            @if ($best && $best['amount'] > 0 && count($data['daily']) > 1)
                <span class="ab-mute">Best day: {{ \Illuminate\Support\Carbon::parse($best['date'])->format('D j M') }}, {{ AB::short($best['amount']) }}</span>
            @endif
        </div>
        <div class="ab-cols" role="img" aria-label="Money collected each day, {{ AB::rangeLabel($win['from'], $win['to']) }}. Total {{ AB::short($money['collected']) }}.">
            @foreach ($data['daily'] as $day)
                <i class="{{ $day['date'] === $todayStr ? 'is-today' : '' }}"
                   style="height: {{ $day['amount'] > 0 ? max(2, round($day['amount'] / $dailyMax * 100)) : 1 }}%;"
                   title="{{ \Illuminate\Support\Carbon::parse($day['date'])->format('D j M') }}: ₦{{ number_format($day['amount']) }}"></i>
            @endforeach
        </div>
        <div class="ab-axis">
            <span>{{ $win['from']->format('j M') }}</span>
            @if (count($data['daily']) > 2)
                <span>{{ \Illuminate\Support\Carbon::parse($data['daily'][intdiv(count($data['daily']), 2)]['date'])->format('j M') }}</span>
            @endif
            <span>{{ $win['to']->format('j M') }}</span>
        </div>
    </section>

    <div class="ab-three">
        <section class="ab-card ab-pad" data-testid="ab-methods">
            <h2 class="ab-h2">How guests paid</h2>
            <p class="ab-mute" style="margin: 2px 0 0;">{{ AB::short($data['methods_total']) }} collected {{ $win['label'] }}</p>
            @forelse ($data['methods'] as $m)
                <div class="ab-hbar">
                    <div class="l"><span>{{ $m['label'] }}</span><b class="ab-num">{{ AB::short((int) $m['amount']) }} <span class="ab-mute" style="font-weight: 500;">{{ $m['pct'] }}%</span></b></div>
                    <div class="tr"><i style="width: {{ $m['pct'] }}%"></i></div>
                </div>
            @empty
                <p class="ab-empty">No payments in this period.</p>
            @endforelse
        </section>
        <section class="ab-card ab-pad" data-testid="ab-channels">
            <h2 class="ab-h2">Where bookings came from</h2>
            <p class="ab-mute" style="margin: 2px 0 0;">{{ AB::short($data['channels_total']) }} in stays starting {{ $win['label'] === 'today' ? 'today' : 'in this period' }}</p>
            @forelse ($data['channels'] as $c)
                @php $share = (int) round($c['share']); @endphp
                <div class="ab-hbar">
                    <div class="l"><span>{{ $c['label'] }}</span><b class="ab-num">{{ AB::short((int) $c['revenue']) }} <span class="ab-mute" style="font-weight: 500;">{{ $share }}%</span></b></div>
                    <div class="tr"><i style="width: {{ $share }}%"></i></div>
                </div>
            @empty
                <p class="ab-empty">No stays start in this period.</p>
            @endforelse
        </section>
        <section class="ab-card ab-pad" data-testid="ab-owes">
            <div class="ab-list-head" style="margin-bottom: 8px;">
                <h2 class="ab-h2">Who owes</h2>
                <span class="ab-owe ab-num">{{ AB::short($money['owed']) }}</span>
            </div>
            @forelse ($data['owed_top'] as $row)
                <div class="ab-owes-row">
                    <div>
                        <div class="ab-t">{{ $row['name'] }}</div>
                        <div class="d"><span class="ab-tag">{{ $row['branch'] }}</span>{{ $row['kind'] === 'company' ? 'Company · ' : '' }}{{ $row['subtitle'] }}</div>
                    </div>
                    <b class="ab-num" style="color: var(--color-ink);">₦{{ number_format($row['amount']) }}</b>
                </div>
            @empty
                <p class="ab-empty">Nobody owes anything right now.</p>
            @endforelse
            @if ($data['owed_rest_count'] > 0)
                <div class="ab-owes-row" style="padding-bottom: 0;"><span class="ab-soft">{{ $data['owed_rest_count'] }} more, {{ AB::short($data['owed_rest_amount']) }}. Each branch's Finance page lists them.</span></div>
            @endif
        </section>
    </div>
</div>
