<?php

namespace App\Jobs\Providers\Lenful;

use App\Libs\Providers\Lenful;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class LoadCatalogs implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            (new Lenful())->getCatalog(true);
        } catch (\Exception $e) {
            throwException($e->getMessage());
        }
    }
}
