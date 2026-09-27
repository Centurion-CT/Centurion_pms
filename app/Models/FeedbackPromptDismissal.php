<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $operator_id
 * @property string $module
 * @property string $action  'submitted' | 'skipped'
 * @property Carbon $dismissed_at
 */
class FeedbackPromptDismissal extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }

    public function isSubmitted(): bool
    {
        return $this->action === 'submitted';
    }

    /**
     * Skips get a 30-day cooldown before we ask again; submits are
     * permanent (the operator already told us how they feel about
     * this module). Callers use this to decide whether to render
     * the floating prompt at all.
     */
    public function isDueForReprompt(): bool
    {
        if ($this->isSubmitted()) return false;
        return $this->dismissed_at->diffInDays(Carbon::now(), absolute: true) >= 30;
    }
}
