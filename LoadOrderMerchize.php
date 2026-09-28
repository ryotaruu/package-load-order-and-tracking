<?php

namespace App\Jobs;

use App\Jobs\Platforms\ShopBase\LoadOrder;
use App\Jobs\Providers\Merchize\LoadTracking;
use App\Libs\Merchize;
use App\Libs\Platforms\ShopBase;
use App\Models\Provider\CancelRefundOrder;
use App\Models\Provider\Merchize\Order;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

class LoadOrderMerchize implements ShouldQueue
{
    use Queueable;

    private Order $order;

    public int $tries = 20;

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function middleware(): array
    {
        return [
            (new RateLimited('merchize-api')),
        ];
    }

    /**
     * Create a new job instance
     */
    public function __construct(int $orderId)
    {
        $this->order = Order::find($orderId);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $order = $this->order;

            if ($order->order_platform === 'ebay') {
                $orderPlatform = $order->orderEbay;
                $shopQuery = $orderPlatform->shop->id;
            } elseif ($order->order_platform === 'etsy') {
                $orderPlatform = $order->orderEtsy;
                $shopQuery = $orderPlatform->shop_name;
            } elseif ($order->order_platform === 'shopbase') {
                $orderPlatform = $order->orderShopBase;
                $shopQuery = $orderPlatform->shop->id;
            } elseif ($order->order_platform === 'woo') {
                $orderPlatform = $order->orderWoo;
                $shopQuery = $orderPlatform->shop->id;
            } else {
                throwException('Error platform !');
            }

            $merchize = new Merchize($order->order_platform, $shopQuery);

            $orderId = $order->ffId();

            $getOrder = $merchize->getOrder($orderId);

            if (empty($getOrder['data'])) {
                return;
            }

            $order->update(['data' => [
                'order' => $getOrder['data'],
            ]]);

            $order->refresh();

            $status = match (true) {
                $order->onhold() => 'onhold',
                $order->shipped() => 'shipped',
                $order->complete() => 'completed',
                $order->cancel() => 'cancel',
                default => 'printing',
            };

            $orderPlatform->update(['fulfillment_status' => $status]);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }
}
