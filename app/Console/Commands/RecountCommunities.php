<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Marvel\Database\Models\Community;

class RecountCommunities extends Command
{
    protected $signature = 'social:recount-communities';
    protected $description = 'Recalculate canonical Community member and Place counters';

    public function handle(): int
    {
        Community::query()->chunkById(100, function ($communities) {
            foreach ($communities as $community) {
                $community->update([
                    'members_count' => $community->members()->wherePivot('status', 'active')->count(),
                    'places_count' => $community->places()->count(),
                ]);
            }
        });

        $this->info('Community counters recalculated.');
        return self::SUCCESS;
    }
}
