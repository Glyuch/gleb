<?php

namespace App\Console\Commands;

use App\Support\LectureRooms;
use Illuminate\Console\Command;

class ArchiveLectureRooms extends Command
{
    protected $signature = 'lecture:archive';

    protected $description = 'Permanently import surviving lecture room cache files, including expired rooms';

    public function handle(LectureRooms $rooms): int
    {
        $count = $rooms->archiveCachedRooms();
        $this->info("Archived {$count} lecture rooms. Existing database records were preserved.");

        return self::SUCCESS;
    }
}
