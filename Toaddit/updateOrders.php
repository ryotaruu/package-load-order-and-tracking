<?php

namespace App\Jobs\Providers\Toaddit;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Provider\ApiConfig;
use App\Models\ProviderOrder;
use App\Services\providers\sheetDefault\LoadOrder;

class updateOrders implements ShouldQueue
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
            (new LoadOrder(
                ProviderOrder::query()->where('provider_name', 'toaddit')->whereBetween('created_at', [now()->subDays(31)->startOfDay(), now()->endOfDay()]),
                'D',
                2,
                'AJ',
                ApiConfig::query()->where('provider_name', 'toaddit')->first()->api_key,
                'orders',
                ''
            ))->handle();
        } catch (\Exception $e) {
            throwException($e->getMessage());
        }
    }
}
