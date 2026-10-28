{{-- Branch picker, shown in the sidebar and the topbar. Always visible,
     even with one branch, so the current branch is never a guess and
     managers can find "Add a branch". Picking a branch POSTs to
     /dashboard/branches/switch and lands back on the current page.

     @var string $variant  'sidebar' | 'topbar'
     @var \Illuminate\Support\Collection $allBranches
     @var ?\App\Models\Property $currentProperty
     @var bool $canManageBranches --}}
@php
    $isTopbar = ($variant ?? 'sidebar') === 'topbar';
    $tid = $isTopbar ? 'dash-topbar-branch' : 'dash-branch-switcher';
@endphp
<div class="lp-sidebar-branches {{ $isTopbar ? 'lp-topbar-branch' : '' }}"
     x-data="{ open: false }"
     x-on:click.outside="open = false"
     x-on:keydown.escape.window="open = false"
     data-testid="{{ $tid }}">
    <button type="button"
            class="lp-sidebar-branches-trigger"
            x-on:click="open = !open"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="listbox"
            @if ($isTopbar) aria-label="Branch: {{ $currentProperty?->name ?? 'none picked' }}. Change branch" @endif
            data-testid="{{ $tid }}-trigger">
        @if ($isTopbar)
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M8 14.5s4.5-4.2 4.5-7.8a4.5 4.5 0 1 0-9 0c0 3.6 4.5 7.8 4.5 7.8Z"/><circle cx="8" cy="6.6" r="1.6"/>
            </svg>
        @endif
        <span class="label">
            @unless ($isTopbar)
                <span class="eyebrow">Branch</span>
            @endunless
            <span class="name">{{ $currentProperty?->name ?? 'Pick a branch' }}</span>
        </span>
        <svg width="10" height="10" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 4.5L6 8l3-3.5"/>
        </svg>
    </button>
    <div x-show="open"
         x-transition.opacity.duration.120ms
         x-cloak
         class="lp-sidebar-branches-menu"
         role="listbox"
         data-testid="{{ $tid }}-menu">
        @foreach ($allBranches as $branch)
            @php $isCurrent = $currentProperty && $currentProperty->getKey() === $branch->getKey(); @endphp
            <form method="POST" action="{{ route('tenant.branches.switch') }}" style="margin:0;">
                @csrf
                <input type="hidden" name="property_id" value="{{ $branch->getKey() }}">
                <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                <button type="submit"
                        class="lp-sidebar-branches-item @if ($isCurrent) is-current @endif"
                        role="option"
                        aria-selected="{{ $isCurrent ? 'true' : 'false' }}"
                        data-testid="{{ $tid }}-item-{{ $branch->slug }}">
                    <span class="name">{{ $branch->name }}</span>
                    @if ($branch->city || $branch->state)
                        <span class="sub">{{ trim(collect([$branch->city, $branch->state])->filter()->implode(', ')) }}</span>
                    @endif
                </button>
            </form>
        @endforeach
        @if ($canManageBranches)
            <a href="{{ route('tenant.branches.index') }}"
               class="lp-sidebar-branches-manage"
               data-testid="{{ $tid }}-manage">
                {{ $allBranches->count() > 1 ? 'Manage branches' : 'Add a branch' }}
            </a>
        @endif
    </div>
</div>
