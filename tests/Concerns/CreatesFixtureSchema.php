<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesFixtureSchema
{
    /**
     * Stand up the `tenant_owned_fixtures` table used by tenancy tests.
     */
    protected function createFixtureSchema(): void
    {
        if (Schema::hasTable('tenant_owned_fixtures')) {
            return;
        }

        Schema::create('tenant_owned_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('label');
        });
    }
}
