<?php

namespace PatrykSawicki\Helper\app\Console\Commands;

use Illuminate\Console\Command;
use PatrykSawicki\Helper\app\Traits\uploads;

class FilesRebuildCommand extends Command
{
    use uploads;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:rebuild';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild files.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Rebuilding files...');

        $fileClass = config('filesSettings.fileClass');

        if (empty($fileClass)) {
            $this->error('File class not found.');
            return false;
        }

        $this->rebuildFiles(filesClass: $fileClass);

        $this->info('Files rebuilt.');

        return true;
    }
}
