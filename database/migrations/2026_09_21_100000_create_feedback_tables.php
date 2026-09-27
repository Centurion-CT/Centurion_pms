<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two tables backing the in-dashboard "how are we doing?" feedback
 * loop (§operator feedback):
 *
 *   feedback_submissions — one row per actual answer. Carries the
 *     module the operator was in when they answered (dashboard,
 *     bookings, rates, etc.), the exact route so we can trace
 *     screen-level pain, a 1-5 rating and a free-text body.
 *
 *   feedback_prompt_dismissals — one row per (operator, module)
 *     that records the last time we asked them for feedback and
 *     what they did about it (submitted / skipped). The prompt
 *     widget reads this to decide whether to show at all, so a
 *     single operator can't be nagged twice for the same module.
 *     Skip re-prompt after 30 days; a submission is permanent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_submissions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $t->string('module', 40);            // 'dashboard', 'bookings', 'rates', ...
            $t->string('route', 120)->nullable(); // 'tenant.rates.index', for precise tracing
            $t->unsignedTinyInteger('rating');    // 1-5
            $t->text('body')->nullable();
            $t->timestamps();

            $t->index(['module', 'rating']);
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('feedback_prompt_dismissals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $t->string('module', 40);
            $t->string('action', 16); // 'submitted' | 'skipped'
            $t->timestamp('dismissed_at')->useCurrent();
            $t->timestamps();

            $t->unique(['operator_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_prompt_dismissals');
        Schema::dropIfExists('feedback_submissions');
    }
};
