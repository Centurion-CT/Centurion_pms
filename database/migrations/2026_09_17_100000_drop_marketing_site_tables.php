<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Rolls back the short-lived marketing-site experiment. The tenant
 * subdomain root now serves the marketplace stays detail view
 * directly, so the parallel marketing shell (TenantMarketingSite +
 * MarketingContactMessage + `/dashboard/site` editor + `/contact`
 * route + `resources/views/marketing/*`) has no purpose and is
 * being removed wholesale.
 *
 * If a hotel needs a wildly custom brand later, they can host their
 * own site on a custom domain that CNAMEs to the subdomain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('marketing_contact_messages');
        Schema::dropIfExists('tenant_marketing_sites');
    }

    public function down(): void
    {
        // Intentionally empty. This is a one-way delete — the
        // marketing-site experiment isn't coming back.
    }
};
