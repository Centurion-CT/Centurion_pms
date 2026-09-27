<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states for {@see \App\Models\PropertyContentImport}.
 *
 *   Pending     — row created; no fetch attempted yet.
 *   Fetching    — job dispatched, extractors running.
 *   Ready       — suggestions returned; awaiting super-admin review.
 *   Approved    — admin accepted fields; property updated + operator notified.
 *   Rejected    — admin rejected the whole import; nothing was written to
 *                 the property.
 *   Failed      — an extractor errored; error message on the row.
 */
enum ContentImportStatus: string
{
    case Pending  = 'pending';
    case Fetching = 'fetching';
    case Ready    = 'ready';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed   = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'Pending',
            self::Fetching => 'Fetching',
            self::Ready    => 'Ready for review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Failed   => 'Failed',
        };
    }
}
