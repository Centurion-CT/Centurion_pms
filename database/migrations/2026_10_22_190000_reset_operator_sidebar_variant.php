<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time reset for the Centurion 3A sidebar: clear every operator's
 * saved sidebar_variant so all staff land on the install default
 * (config brand.sidebar_default, 'list'). Other preference keys are
 * kept. Operators can still switch layouts afterwards; their new pick
 * is saved as usual.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('operators')
            ->whereNotNull('preferences')
            ->orderBy('id')
            ->select(['id', 'preferences'])
            ->each(function (object $op): void {
                $prefs = json_decode((string) $op->preferences, true);
                if (! is_array($prefs) || ! array_key_exists('sidebar_variant', $prefs)) {
                    return;
                }
                unset($prefs['sidebar_variant']);
                DB::table('operators')->where('id', $op->id)->update([
                    'preferences' => $prefs === [] ? null : json_encode($prefs),
                ]);
            });
    }

    public function down(): void
    {
        // Previous picks aren't recorded; nothing to restore.
    }
};
