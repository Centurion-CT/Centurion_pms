<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

/**
 * Shared multi-select state for Livewire index components (Guest
 * requests, Notifications, Complimentary, etc.). Provides:
 *
 *   $selectedIds        currently-checked row IDs
 *   toggleSelected($id) flip one row
 *   selectAll($ids)     tick every row on the current page
 *   clearSelection()    untick everything
 *
 * IDs are stored as strings internally so the same trait works for
 * both int-keyed models (Bookings, Guest requests) and UUID-keyed
 * ones (Laravel database notifications). Comparisons use loose in-
 * array on the string form, so the caller can pass an int without a
 * ceremony cast on every wire:click.
 *
 * The bulk action itself (mark resolved / archive / notify …) lives
 * on the concrete component; this trait only owns the checkbox state.
 */
trait HandlesBulkSelection
{
    /** @var array<int, string> */
    public array $selectedIds = [];

    public function toggleSelected(int|string $id): void
    {
        $needle = (string) $id;
        $key = array_search($needle, $this->selectedIds, true);
        if ($key === false) {
            $this->selectedIds[] = $needle;
        } else {
            unset($this->selectedIds[$key]);
            // Reindex so blade's @foreach with :key stays contiguous
            // and Alpine's array reactivity doesn't leave stale slots.
            $this->selectedIds = array_values($this->selectedIds);
        }
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public function selectAll(array $ids): void
    {
        // Merge instead of replace so pagination doesn't drop selections
        // an operator made on an earlier page. Normalising to string
        // deduplicates cleanly across both int + UUID id shapes.
        $normalised = array_map(static fn ($id) => (string) $id, $ids);
        $this->selectedIds = array_values(array_unique(
            array_merge($this->selectedIds, $normalised),
        ));
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public function deselectPage(array $ids): void
    {
        $drop = array_map(static fn ($id) => (string) $id, $ids);
        $this->selectedIds = array_values(array_diff($this->selectedIds, $drop));
    }

    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    public function hasSelection(): bool
    {
        return $this->selectedIds !== [];
    }

    /**
     * @param  array<int, int|string>  $ids  IDs currently rendered on this page.
     * @return array{all: bool, some: bool}
     */
    public function selectionStateFor(array $ids): array
    {
        if ($ids === []) return ['all' => false, 'some' => false];
        $selectedSet = array_fill_keys($this->selectedIds, true);
        $selectedOnPage = 0;
        foreach ($ids as $id) {
            if (isset($selectedSet[(string) $id])) $selectedOnPage++;
        }
        return [
            'all' => $selectedOnPage === count($ids),
            'some' => $selectedOnPage > 0 && $selectedOnPage < count($ids),
        ];
    }
}
