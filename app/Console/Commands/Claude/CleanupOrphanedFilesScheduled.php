<?php

declare(strict_types=1);

namespace App\Console\Commands\Claude;

use App\Components\Claude\Files\FilesCleanupRunner;
use Illuminate\Console\Command;

final class CleanupOrphanedFilesScheduled extends Command
{
    protected $signature = 'claude:cleanup-files';

    protected $description = 'Hard-delete orphaned file records and alert on unused files';

    public function handle(FilesCleanupRunner $runner): int
    {
        $hardDeleted = $runner->runHardDeletePass();
        $this->info("Hard-deleted $hardDeleted file(s).");

        $alerts = $runner->runUnusedAlertPass();
        $this->info("Detected $alerts unused file(s).");

        return self::SUCCESS;
    }
}
