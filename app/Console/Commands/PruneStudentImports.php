<?php

namespace App\Console\Commands;

use App\Models\StudentImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('students:prune-imports')]
#[Description('Remove expired private student import previews and results')]
class PruneStudentImports extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;
        StudentImport::query()->where('expires_at', '<=', now())->chunkById(100, function ($imports) use (&$count): void {
            foreach ($imports as $import) {
                $import->delete();
                $count++;
            }
        });
        $this->info('Removed '.$count.' expired import previews. Student records were preserved.');

        return self::SUCCESS;
    }
}
