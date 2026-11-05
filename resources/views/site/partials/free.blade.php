{{-- How many are free for the guest's dates, in words. --}}
@if ($free === 0)
    <span class="free free--gone">Taken for your dates</span>
@elseif ($free === 1)
    <span class="free free--last">Last one</span>
@else
    <span class="free">{{ $free }} free</span>
@endif
