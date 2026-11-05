<?php

declare(strict_types=1);

namespace App\Console\Commands\Branches;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: lists records that disagree about which branch they're in.
 *
 * Forms used to file everything under a business's first branch, and
 * saving an edit moved it there (fixed in the room, category, rate rule,
 * review and outlet forms). This finds what that left behind where it
 * shows. A room filed under the first branch along with its category
 * looks like any other room, so only someone who knows the buildings can
 * spot those: branches with no rooms are listed as the place to look.
 *
 * It changes nothing. Which branch is right is for the business to say.
 */
class CheckBranches extends Command
{
    protected $signature = 'branches:check';

    protected $description = 'List rooms, rate rules and reviews whose branch disagrees with what they point at';

    public function handle(): int
    {
        $rooms = DB::table('rooms as r')
            ->join('room_categories as c', 'c.id', '=', 'r.room_category_id')
            ->whereColumn('r.property_id', '<>', 'c.property_id')
            ->whereNull('r.deleted_at')
            ->get(['r.tenant_id', 'r.id', 'r.room_number as what', 'r.property_id as in_branch', 'c.property_id as other_branch']);

        $rules = DB::table('rate_rules as x')
            ->join('room_categories as c', 'c.id', '=', 'x.room_category_id')
            ->whereColumn('x.property_id', '<>', 'c.property_id')
            ->get(['x.tenant_id', 'x.id', 'x.label as what', 'x.property_id as in_branch', 'c.property_id as other_branch']);

        $reviews = DB::table('reviews as x')
            ->join('rooms as r', 'r.id', '=', 'x.room_id')
            ->whereColumn('x.property_id', '<>', 'r.property_id')
            ->get(['x.tenant_id', 'x.id', 'x.guest_name as what', 'x.property_id as in_branch', 'r.property_id as other_branch']);

        $empty = DB::table('properties as p')
            ->whereIn('p.tenant_id', DB::table('properties')->select('tenant_id')->groupBy('tenant_id')->havingRaw('count(*) > 1'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rooms')->whereColumn('rooms.property_id', 'p.id')->whereNull('rooms.deleted_at'))
            ->orderBy('p.tenant_id')
            ->get(['p.tenant_id', 'p.id', 'p.name']);

        foreach (['Rooms in a different branch from their category' => $rooms, 'Rate rules in a different branch from their category' => $rules, 'Reviews in a different branch from their room' => $reviews] as $title => $rows) {
            $this->line($title.': '.$rows->count());
            if ($rows->isNotEmpty()) {
                $this->table(['Business', 'Id', 'Name', 'In branch', 'Points at branch'], $rows->map(fn ($row) => (array) $row)->all());
            }
        }

        $this->line('Branches with no rooms, in businesses with several branches: '.$empty->count());
        if ($empty->isNotEmpty()) {
            $this->table(['Business', 'Branch', 'Name'], $empty->map(fn ($row) => (array) $row)->all());
        }

        $this->info('Nothing was changed.');

        return self::SUCCESS;
    }
}
