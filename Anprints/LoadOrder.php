<?php

namespace App\Jobs\Providers\Anprints;

use App\Jobs\Platforms\ShopBase\LoadOrder as ShopBaseLoadOrder;
use App\Libs\Platforms\ShopBase;
use App\Libs\Providers\AnPrints;
use App\Models\ProviderOrder;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class LoadOrder implements ShouldQueue
{
    use Queueable;

    private $order;

    /**
     * Create a new job instance.
     */
    public function __construct(ProviderOrder $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
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
            throw new Exception('Error platform !');
        }

        $provider = new AnPrints($order->order_platform, $shopQuery);

        $orderId = $order->reference_id;

        try {
            $getOrder = $provider->getOrder($orderId);

            if ($getOrder['error'] ?? false) {
                if ($getOrder['error'] == 'ORDER_NOT_FOUND' || $getOrder['error'] == 'get order anprints error') {
                    $order->delete();
                }

                return;
            }

            $order->update(['data' => $getOrder['data']['order']]);

            $order = $order->refresh();

            $status = match (true) {
                $order->onhold() => 'onhold',
                $order->shipped() => 'shipped',
                $order->complete() => 'completed',
                $order->cancel() => 'cancel',
                default => 'printing',
            };

            $orderPlatform->update([
                $order->order_platform === 'woo' ? 'status' : 'fulfillment_status' => $status,
            ]);

            if ($order->order_platform === 'shopbase' && $order->tracking()) {
                if ($orderPlatform->fulfillments->count() == 0) {
                    $dataFulfillment = [];

                    $items = $orderPlatform->items;

                    foreach ($items as $item) {
                        $provider = $item->provider;

                        if ($provider == 'anprints') {
                            $dataFulfillment['fulfillment']['line_items'][] = [
                                'id' => $item->platform_line_item_id,
                                'quantity' => $item->quantity,
                            ];
                        }
                    }
                    $tracking = $order->tracking() ?? null;
                    $carrier = $order->data['tracking_carrier'] ?? null;

                    $dataFulfillment['fulfillment']['tracking_company'] = $carrier;
                    $dataFulfillment['fulfillment']['tracking_number'] = $tracking;

                    try {
                        $createFulfillment = (new ShopBase($orderPlatform->shop))->createFulfillment($orderPlatform->platform_order_id, $dataFulfillment);

                        ShopBaseLoadOrder::dispatch($orderPlatform->shop->id, $orderPlatform->platform_order_id)->onQueue('critical');
                    } catch (\Exception $e) {
                        Log::error('Create fulfillment shopbase error', [
                            'provider' => 'anprints',
                            'platform' => 'ShopBaseOrder',
                            'order_id' => $orderPlatform->id,
                            'data' => $dataFulfillment,
                        ]);

                        throw new Exception($e->getMessage());
                    }
                }
            }

            if ($order->tracking() && $order->order_platform == 'woo' && $orderPlatform->status != 'completed') {
                $tracking = $order->tracking() ?? null;
                $carrier = $order->data['tracking_carrier'] ?? null;

                if (empty($tracking)) {
                    Log::error('Order has tracking status but tracking number is empty', [
                        'order_id' => $order->id
                    ]);

                    return;
                }

                $oldTrackings = (new \App\Libs\Platforms\WooEcom($orderPlatform->shop))->getOrderTracking($orderPlatform->order_id);

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
                        (new \App\Libs\Platforms\WooEcom($orderPlatform->shop))->setOrderTracking($orderPlatform->order_id, $tracking, checkCarries($carrier));

                        (new \App\Libs\Platforms\WooEcom($orderPlatform->shop))->updateOrder($orderPlatform->order_id, [
                            'status' => 'completed'
                        ]);

                        $orderPlatform->update(['status' => 'completed']);
                    } catch (\Exception $e) {
                        logc('error', 'update woo api error', [
                            'shop' => $orderPlatform->shop->shop_name,
                            'order_id' => $orderPlatform->order_id
                        ]);

                        throwException($e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            return;
        }
    }
}
