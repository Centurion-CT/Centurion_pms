<?php

declare(strict_types=1);

namespace App\Multitenancy;

use RuntimeException;

/**
 * Raised when tenant-scoped code runs without a current tenant in context.
 *
 * Hitting this in production indicates a bug (a missing middleware, a queue
 * job that forgot `TenantAware`, or central-context code reaching for tenant
 * data). Failing loudly is the goal — silent fallthrough is the PRD §12
 * "Critical" risk.
 */
class TenantNotResolvedException extends RuntimeException {}
