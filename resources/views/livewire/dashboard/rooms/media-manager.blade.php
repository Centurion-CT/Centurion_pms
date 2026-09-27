<div class="lp-dash">

    {{-- ── Back link + header ───────────────────────────────────── --}}
    <div>
        @include('_partials._back-button')

        <div class="mt-4">
            <span class="lp-eyebrow-strip">Photos</span>
            <h1 class="lp-display mt-3 text-[clamp(26px,3.4vw,36px)]"
                style="color: var(--color-ink); font-weight: 600; line-height: 1.04;">
                {{ $room->name }}
            </h1>
            <p class="mt-2 text-[14.5px]" style="color: var(--color-ink-soft);">
                Drag photos to reorder. Star one to make it the hero for this room.
                Add alt text on each so search engines and screen readers know what they show.
            </p>
        </div>
    </div>
    <section class="lp-dash-card">
        <x-photo-manager
            :media="$gallery"
            :pending="$photos"
            wireModel="photos"
            wireUpload="upload"
            wireReorder="reorder"
            wireSetHero="setHero"
            wireSetAlt="setAlt"
            wireDelete="delete"
            wireDeleteMany="deleteMany"
            :maxFiles="20"
            :altEditable="true"
            label="Room photos"
            help="Drag photos here or click to browse."
            emptyLabel="No photos yet. Add at least one so this room can appear on your booking page." />
    </section>
</div>
