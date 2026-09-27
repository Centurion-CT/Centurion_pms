<?php

declare(strict_types=1);

use App\Models\Room;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * QR slug per room — unguessable identifier printed on the in-room
 * QR code that lands the guest on /room/{qr_slug}. Deliberately
 * NOT the room's public id or room_number:
 *   - Room ids leak growth signal + are enumerable.
 *   - Room numbers are printed on doors + visible from the corridor,
 *     so anyone walking past can guess one.
 * A 24-char random slug is unguessable enough that portal auth can
 * rely on knowing this slug + phone + PIN as three factors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->string('qr_slug', 32)->nullable()->after('room_number');
        });

        // Backfill: every existing room gets a random slug.
        DB::table('rooms')->orderBy('id')->each(function ($row): void {
            DB::table('rooms')->where('id', $row->id)->update([
                'qr_slug' => Str::lower(Str::random(24)),
            ]);
        });

        Schema::table('rooms', function (Blueprint $table): void {
            $table->string('qr_slug', 32)->nullable(false)->change();
            $table->unique('qr_slug', 'rooms_qr_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropUnique('rooms_qr_slug_unique');
            $table->dropColumn('qr_slug');
        });
    }
};
