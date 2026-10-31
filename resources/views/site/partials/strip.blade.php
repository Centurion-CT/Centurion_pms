{{-- Availability strip: 14 nights from check-in; the guest's own nights underlined. --}}
@php($days = array_keys($strip))
<div class="strip" role="img" aria-label="Free nights over the next two weeks">
    @foreach ($strip as $day => $state)<i class="{{ $state === 'free' ? '' : $state }} {{ $loop->index < $nights ? 'sel' : '' }}"></i>@endforeach
</div>
@if ($days !== [])
    <div class="strip-legend"><span>{{ \Illuminate\Support\Carbon::parse($days[0])->format('D j') }}</span><span>next {{ count($days) }} nights</span><span>{{ \Illuminate\Support\Carbon::parse(end($days))->format('D j') }}</span></div>
@endif
