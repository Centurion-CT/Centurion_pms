<?php

declare(strict_types=1);

use App\Models\Room;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which floor a room is on (room boards on the desk app and the web filter
 * by it). Free text so hotels can say "Ground", "Mezzanine" or "Annex".
 * Existing rooms are filled in from their number where it's obvious
 * (101 -> 1, 1204 -> 12, 012 -> Ground); everything else stays empty
 * until a manager sets it on the room form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->string('floor', 30)->nullable()->after('room_number');
        });

        DB::table('rooms')->whereNull('floor')->whereNotNull('room_number')->orderBy('id')
            ->select(['id', 'room_number'])
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $floor = Room::guessFloor((string) $row->room_number);
                    if ($floor !== null) {
                        DB::table('rooms')->where('id', $row->id)->update(['floor' => $floor]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropColumn('floor');
        });
    }
};
