<?php

namespace App\Jobs;

use App\Jobs\Platforms\ShopBase\LoadOrder;
use App\Libs\A2k;
use App\Libs\Ebay;
use App\Libs\Platforms\ShopBase;
use App\Libs\Platforms\WooEcom;
use App\Models\EbayOrder;
use App\Models\Provider\A2k\Order;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class LoadOrderA2k implements ShouldQueue
{
    use Queueable;

    private Order $order;

    /**
     * Create a new job instance.
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
                $shopQuery = $orderPlatform->shop_id;
            } elseif ($order->order_platform === 'woo') {
                $orderPlatform = $order->orderWoo;
                $shopQuery = $orderPlatform->shop_id;
            } else {
                throwException('Error platform !');
            }

            $a2k = new A2k($order->order_platform, $shopQuery);

            $orderId = $order->reference_id;

            $getOrder = $a2k->getOrder($orderId);

            if (!$getOrder) {
                return;
            }

            $order->update(['data' => $getOrder['data']]);

            $order = $order->refresh();

            $status = match (true) {
                $order->onhold() => 'onhold',
                $order->shipped() => 'shipped',
                $order->complete() => 'completed',
                $order->cancel() => 'cancel',
                default => 'printing',
            };

            switch ($order->order_platform) {
                case 'shopbase':
                    $orderPlatform->update(['fulfillment_status' => $status]);

                    if ($order->tracking()) {
                        if ($orderPlatform->fulfillments->count() == 0) {
                            $dataFulfillment = [];

                            $items = $orderPlatform->items;

                            foreach ($items as $item) {
                                $provider = $item->provider;

                                if ($provider == 'a2k') {
                                    $dataFulfillment['fulfillment']['line_items'][] = [
                                        'id' => $item->platform_line_item_id,
                                        'quantity' => $item->quantity,
                                    ];
                                }
                            }

                            $tracking = $order->data['tracking_number'] ?? null;

                            $carrier = "USPS";

                            $isTrackingExists = $orderPlatform->fulfillments->contains(function ($fulfillment) use ($tracking) {
                                return $fulfillment->tracking_number === $tracking;
                            });

                            if ($isTrackingExists) {
                                return;
                            }

                            $dataFulfillment['fulfillment']['tracking_company'] = $carrier;
                            $dataFulfillment['fulfillment']['tracking_number'] = $tracking;

                            try {
                                $createFulfillment = (new ShopBase($orderPlatform->shop))->createFulfillment($orderPlatform->platform_order_id, $dataFulfillment);

                                LoadOrder::dispatch($orderPlatform->shop->id, $orderPlatform->platform_order_id)->onQueue('critical');
                            } catch (\Exception $e) {
                                Log::error('Create fulfillment shopbase error', [
                                    'provider' => 'a2k',
                                    'platform' => 'ShopBaseOrder',
                                    'order_id' => $orderPlatform->id,
                                    'data' => $dataFulfillment,
                                ]);

                                throw new Exception($e->getMessage());
                            }
                        }
                    }

                    return;
                case 'woo':
                    $orderPlatform->update(['status' => $status]);

                    if ($order->tracking() && $orderPlatform->status != 'completed') {
                        $tracking = $order->data['tracking_number'] ?? null;

                        $carrier = "USPS";

                        if (empty($tracking)) {
                            Log::error('Luxurypro order has tracking status but tracking number is empty', [
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
                                (new \App\Libs\Platforms\WooEcom($orderPlatform->shop))->setOrderTracking($orderPlatform->order_id, $tracking, $carrier);

                                (new \App\Libs\Platforms\WooEcom($orderPlatform->shop))->updateOrder($orderPlatform->order_id, [
                                    'status' => 'completed'
                                ]);

                                $orderPlatform->update(['status' => 'completed']);
                            } catch (Exception $e) {
                                logc('error', 'update woo api error', [
                                    'shop' => $orderPlatform->shop->shop_name,
                                    'order_id' => $orderPlatform->order_id
                                ]);

                                throwException($e->getMessage());
                            }
                        }
                    }

                    return;
                default:
                    return;
            }
        } catch (Exception $exception) {
            Log::error("Job Load Order A2k Error!", [$exception->getMessage()]);

            return;
        }
    }
}
