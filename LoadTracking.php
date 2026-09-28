<?php

namespace App\Jobs\Providers\Merchize;

use App\Jobs\Platforms\ShopBase\LoadOrder;
use App\Libs\Merchize;
use App\Libs\Platforms\ShopBase;
use App\Libs\Platforms\WooEcom;
use App\Models\Provider\CancelRefundOrder;
use App\Models\Provider\Merchize\Order;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

class LoadTracking implements ShouldQueue
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

            $getOrderTracking = $merchize->getOrderTracking($orderId);

            if (empty($getOrderTracking['data'])) {
                return;
            } else {
                $oldOrderData = $order->data['order'];

                $order->update(['data' => [
                    'order' => $oldOrderData,
                    'tracking' => $getOrderTracking['data']
                ]]);

                $order->refresh();
            }

            if (isset($getOrderTracking) && $getOrderTracking['data'][0]['has_tracking']) {
                if (isset($orderPlatform->fulfillments) && $orderPlatform->fulfillments->count() == 0) {
                    $createDate = $order->data['order']['created'] ?? null;

                    if (empty($createDate)) {
                        Log::error('merchize order create_date is empty', [
                            'order_id' => $order->id,
                        ]);

                        return;
                    }

                    try {
                        $trackingAvailableAt = now()::parse($createDate)->addDays(5);
                    } catch (\Throwable $e) {
                        Log::error('merchize order create_date is invalid', [
                            'order_id' => $order->id,
                            'create_date' => $createDate,
                            'message' => $e->getMessage(),
                        ]);

                        return;
                    }

                    if (now()->lt($trackingAvailableAt)) {
                        return;
                    }

                    $dataFulfillment = [];

                    $items = $orderPlatform->items;

                    foreach ($items as $item) {
                        $provider = $item->provider;

                        if ($provider == 'merchize') {
                            $dataFulfillment['fulfillment']['line_items'][] = [
                                'id' => $item->platform_line_item_id,
                                'quantity' => $item->quantity,
                            ];
                        }
                    }
                    $tracking = $getOrderTracking['data'][0]['tracking_number'];
                    $carrier = $getOrderTracking['data'][0]['tracking_company'];

                    $dataFulfillment['fulfillment']['tracking_company'] = $carrier;
                    $dataFulfillment['fulfillment']['tracking_number'] = $tracking;

                    try {
                        $createFulfillment = (new ShopBase($orderPlatform->shop))->createFulfillment($orderPlatform->platform_order_id, $dataFulfillment);

                        LoadOrder::dispatch($orderPlatform->shop->id, $orderPlatform->platform_order_id)->onQueue('critical');
                    } catch (\Exception $e) {
                        Log::error('Create fulfillment shopbase error', [
                            'provider' => 'merchize',
                            'platform' => 'ShopBaseOrder',
                            'order_id' => $orderPlatform->id,
                            'data' => $dataFulfillment,
                        ]);

                        throw new Exception($e->getMessage());
                    }
                }

                if ($order->order_platform == 'woo' && $order->tracking() && $orderPlatform->status != 'completed') {
                    $tracking = $getOrderTracking['data'][0]['tracking_number'];

                    $carrier = '17track';

                    if (empty($tracking)) {
                        Log::error('Merchize order has tracking status but tracking number is empty', [
                            'order_id' => $order->id
                        ]);
                        return;
                    }

                    $oldTrackings = (new WooEcom($orderPlatform->shop))->getOrderTracking($orderPlatform->order_id);

                    $isTrackingExists = false;

                    foreach ($oldTrackings as $oldTracking) {
                        if ($oldTracking['tracking_number'] == $tracking) {
                            $isTrackingExists = true;

                            break;
                        } else {
                            continue;
                        }
                    }

                    if (!$isTrackingExists) {
                        try {
                            (new WooEcom($orderPlatform->shop))->setOrderTracking($orderPlatform->order_id, $tracking, $carrier);

                            (new WooEcom($orderPlatform->shop))->updateOrder(
                                $orderPlatform->order_id,
                                ['status' => 'completed']
                            );

                            $orderPlatform->update(['status' => 'completed']);
                        } catch (\Exception $e) {
                            logc('error', 'update woo api error', [
                                'shop' => $orderPlatform->shop->shop_name,
                                'order_id' => $orderPlatform->order_id
                            ]);

                            throwException($e->getMessage());
                        }
                    } else {
                        (new WooEcom($orderPlatform->shop))->updateOrder(
                            $orderPlatform->order_id,
                            ['status' => 'completed']
                        );

                        $orderPlatform->update(['status' => 'completed']);
                    }

                    return;
                }
            }
        } catch (\Exception $exception) {
            throwException($exception->getMessage());
        }
    }
}
