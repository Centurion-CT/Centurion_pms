<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Recipients of the periodic Summary PDF reports. Managed on the
 * Summary page. Each row can independently opt in to the weekly and
 * monthly cadence. Emails are unique per tenant so accidental
 * duplicate subscriptions are impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('summary_report_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email', 190);
            $table->boolean('weekly')->default(true);
            $table->boolean('monthly')->default(true);
            $table->foreignId('created_by_operator_id')->nullable()->constrained('operators')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'weekly']);
            $table->index(['tenant_id', 'monthly']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summary_report_subscribers');
    }
};
