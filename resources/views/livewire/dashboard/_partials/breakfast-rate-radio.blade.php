{{-- Shared "Room only / With breakfast" radio pair. Renders only
     when the caller has confirmed $category->hasBreakfastOption().

     Params:
       - category  : RoomCategory (must have breakfast_included_rate set)
       - modelPath : Livewire property name backing the selection
                     (bool, true = breakfast, false = room only)
       - idPrefix  : short unique prefix so multiple pairs on the same
                     page don't collide on radio ids / testids.
--}}
@props(['category', 'modelPath', 'idPrefix' => 'rate'])

<fieldset class="lp-fieldset" data-testid="{{ $idPrefix }}-rate-tier">
    <legend>Rate</legend>
    <div class="grid gap-2 mt-2">
        <label class="lp-radio-row">
            <input type="radio"
                   id="{{ $idPrefix }}-rate-standard"
                   wire:model.live="{{ $modelPath }}"
                   value="0"
                   data-testid="{{ $idPrefix }}-rate-standard">
            <span>
                <strong>Room only</strong>
                <span class="lp-mono" style="color: var(--color-ink-mute);">
                    &nbsp;·&nbsp;&#8358;{{ number_format((int) $category->base_rate) }}/night
                </span>
            </span>
        </label>
        <label class="lp-radio-row">
            <input type="radio"
                   id="{{ $idPrefix }}-rate-breakfast"
                   wire:model.live="{{ $modelPath }}"
                   value="1"
                   data-testid="{{ $idPrefix }}-rate-breakfast">
            <span>
                <strong>With complimentary breakfast</strong>
                <span class="lp-mono" style="color: var(--color-ink-mute);">
                    &nbsp;·&nbsp;&#8358;{{ number_format((int) $category->breakfast_included_rate) }}/night
                </span>
            </span>
        </label>
    </div>
</fieldset>
