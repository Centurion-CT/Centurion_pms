@php
    $pillArrow = '<svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h6M6.5 3L9 6 6.5 9"/></svg>';
@endphp

<div class="lp-dash">

    {{-- ── Back + header ───────────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4">
            <span class="lp-eyebrow-strip">{{ $roomId ? 'Edit room' : 'New room' }}</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $roomId ? 'Edit room' : 'Add a room' }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Pick the category this room belongs to and give it a number guests + staff recognise.
            </p>
        </div>
    </div>

    @if ($categories->isEmpty())
        <section class="lp-dash-card text-center" style="padding: 56px 24px;">
            <p data-testid="no-categories-room" class="text-[16px]"
               style="color: var(--color-ink); font-weight: 500;">
                Create a category first.
            </p>
            <p class="mt-2 text-[13.5px]" style="color: var(--color-ink-mute);">
                Rooms inherit pricing, capacity, and photos from a category.
                <a href="{{ route('tenant.room-categories.create') }}" wire:navigate
                   style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 4px;">
                    create one
                </a>
                to get started.
            </p>
        </section>
    @else
        <form wire:submit="save" data-testid="room-form" class="lp-dash-card" style="display: grid; gap: 22px;">

            {{-- Category --}}
            <div>
                <label for="room-category" class="lp-form-label">Category</label>
                <select id="room-category" wire:model.live="room_category_id" required
                        data-testid="room-category" class="lp-input">
                    <option value="">- pick a category -</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">
                            {{ $cat->name }} · ₦{{ number_format((int) $cat->base_rate) }}/night · sleeps {{ $cat->maxOccupancy() }}
                        </option>
                    @endforeach
                </select>
                <p class="lp-form-help">
                    Need a new class?
                    <a href="{{ route('tenant.room-categories.create') }}" wire:navigate
                       style="color: var(--color-ink); text-decoration: underline; text-underline-offset: 3px;">
                        Create a category
                    </a>
                    first.
                </p>
                @error('room_category_id') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            {{-- Room number --}}
            @if ($roomId)
                {{-- Edit mode, single unit, keep the classic input. --}}
                <div>
                    <label for="room-number" class="lp-form-label">Room number</label>
                    <input id="room-number" type="text" wire:model="room_number" required maxlength="40"
                           data-testid="room-number" class="lp-input" placeholder="101">
                    <p class="lp-form-help">Unique within this property. Used on the calendar and front desk.</p>
                    @error('room_number') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            @else
                {{-- Create mode, badge input for bulk adding.
                     Comma or Enter commits the buffer as a badge;
                     Backspace on an empty buffer pops the last one.
                     Server also flushes any residual buffer on submit
                     so operators don't have to press Enter for the
                     last one. --}}
                <div x-data="{
                        buffer: @entangle('room_number'),
                        commit(raw) {
                            const clean = (raw ?? this.buffer).toString();
                            if (clean.trim() === '') return;
                            $wire.addRoomNumber(clean);
                            this.buffer = '';
                        },
                        onKey(e) {
                            if (e.key === 'Enter' || e.key === ',') {
                                e.preventDefault();
                                this.commit();
                                return;
                            }
                            if (e.key === 'Backspace' && this.buffer === ''
                                && $wire.room_numbers.length > 0) {
                                $wire.removeRoomNumber($wire.room_numbers.length - 1);
                            }
                        },
                        onPaste(e) {
                            const text = (e.clipboardData || window.clipboardData).getData('text');
                            if (!/[,\n]/.test(text)) return;
                            e.preventDefault();
                            this.commit(text);
                        }
                     }">
                    <label for="room-number" class="lp-form-label">Room numbers</label>

                    {{-- Badge tray + inline input; the whole box acts like one field. --}}
                    <div @click="$refs.input.focus()"
                         class="lp-input"
                         style="display: flex; flex-wrap: wrap; gap: 6px; padding: 8px; cursor: text; min-height: 44px; align-items: center;">
                        @foreach ($room_numbers as $i => $rn)
                            <span wire:key="rn-badge-{{ $i }}-{{ $rn }}"
                                  data-testid="room-number-badge-{{ $i }}"
                                  class="lp-mono text-[12px]"
                                  style="display: inline-flex; align-items: center; gap: 6px;
                                         padding: 3px 4px 3px 10px; border-radius: 999px;
                                         background: var(--color-card-deep); color: var(--color-ink);
                                         border: 1px solid var(--color-line-soft);">
                                {{ $rn }}
                                <button type="button" wire:click="removeRoomNumber({{ $i }})"
                                        aria-label="Remove {{ $rn }}"
                                        data-testid="room-number-badge-remove-{{ $i }}"
                                        style="border: 0; background: transparent; color: var(--color-ink-mute);
                                               width: 18px; height: 18px; border-radius: 999px; cursor: pointer;
                                               font-size: 14px; line-height: 1;">
                                    ×
                                </button>
                            </span>
                        @endforeach

                        <input id="room-number" type="text" x-ref="input" x-model="buffer"
                               @keydown="onKey($event)"
                               @paste="onPaste($event)"
                               @blur="commit()"
                               maxlength="40"
                               data-testid="room-number"
                               placeholder="{{ empty($room_numbers) ? '101, 102, 103…' : 'Add another' }}"
                               style="flex: 1; min-width: 120px; border: 0; background: transparent;
                                      color: var(--color-ink); padding: 4px 6px; outline: none;">
                    </div>

                    <p class="lp-form-help">
                        Press Enter or add a comma to tag each number.
                        Create many rooms in one go. Each must be unique within this property.
                    </p>
                    @error('room_numbers') <p class="lp-form-error">{{ $message }}</p> @enderror
                    @error('room_numbers.*') <p class="lp-form-error">{{ $message }}</p> @enderror
                </div>
            @endif

            {{-- Status --}}
            <fieldset class="lp-fieldset">
                <legend>Visibility</legend>
                <div class="grid gap-1 mt-2">
                    @foreach ($statuses as $s)
                        <label class="lp-radio-row">
                            <input type="radio" wire:model="status" value="{{ $s->value }}"
                                   data-testid="room-status-{{ $s->value }}">
                            <span>
                                <strong>{{ ucfirst($s->value) }}</strong>
                                @if ($s->value === 'active')
                                    <span style="color: var(--color-ink-mute);">&middot; visible on booking engine</span>
                                @else
                                    <span style="color: var(--color-ink-mute);">&middot; hidden / out of service</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('status') <p class="lp-form-error">{{ $message }}</p> @enderror
            </fieldset>

            {{-- Internal notes --}}
            <div>
                <label for="room-notes" class="lp-form-label">
                    Internal notes <span style="color: var(--color-ink-mute); letter-spacing: 0; text-transform: none;">· staff-only</span>
                </label>
                <textarea id="room-notes" wire:model="internal_notes" rows="3" maxlength="5000"
                          data-testid="room-notes" class="lp-input"
                          placeholder="Door sticks, TV remote in drawer 2, etc."></textarea>
                @error('internal_notes') <p class="lp-form-error">{{ $message }}</p> @enderror
            </div>

            {{-- Actions --}}
            <div class="flex flex-wrap justify-end gap-3 pt-2"
                 style="border-top: 1px solid var(--color-line-soft);">
                <a href="{{ route('tenant.rooms.index') }}" class="lp-btn">Cancel</a>
                <button type="submit" class="lp-pill"
                        wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">
                        @if ($roomId)
                            Save changes
                        @else
                            {{ count($room_numbers) > 1 ? 'Add '.count($room_numbers).' rooms' : 'Add room' }}
                        @endif
                    </span>
                    <span wire:loading wire:target="save">Saving…</span>
                    <span class="lp-pill-dot">{!! $pillArrow !!}</span>
                </button>
            </div>
        </form>
    @endif
</div>
