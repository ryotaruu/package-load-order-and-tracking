<?php

namespace App\Jobs\Providers\Pentifine;

use App\Jobs\Platforms\ShopBase\LoadOrder as ShopBaseLoadOrder;
use App\Libs\Pentifine;
use App\Libs\Platforms\ShopBase;
use App\Models\ProviderOrder;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class LoadOrder implements ShouldQueue
{
    use Queueable;

    private ProviderOrder $order;

    /**
     * Create a new job instance.
     */
    public function __construct(int $orderId)
    {
        $this->order = ProviderOrder::find($orderId);
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
                throw new \Exception('Error platform !');
            }

            $provider = new Pentifine();

            $orderId = $order->ffId();

            $getOrder = $provider->getOrder($orderId);

            $order->update(['data' => $getOrder['data']]);

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

                    $dataFulfillment['fulfillment']['tracking_company'] = $getOrder['data']['trackings'][0]['carrier'];
                    $dataFulfillment['fulfillment']['tracking_number'] = $getOrder['data']['trackings'][0]['tracking_number'];

                    try {
                        $createFulfillment = (new ShopBase($orderPlatform->shop))->createFulfillment($orderPlatform->platform_order_id, $dataFulfillment);

                        ShopBaseLoadOrder::dispatch($orderPlatform->shop->id, $orderPlatform->platform_order_id)->onQueue('critical');
                    } catch (\Exception $e) {
                        Log::error('Create fulfillment shopbase error', [
                            'provider' => 'pentifine',
                            'platform' => 'ShopBaseOrder',
                            'order_id' => $orderPlatform->id,
                            'data' => $dataFulfillment,
                        ]);

                        throw new Exception($e->getMessage());
                    }
                }
            }

            if ($order->tracking() && $order->order_platform == 'woo' && $orderPlatform->status != 'completed') {
                $tracking = $getOrder['data']['trackings'][0]['carrier'] ?? null;
                $carrier = $getOrder['data']['trackings'][0]['tracking_number'] ?? null;

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
            throwException($e->getMessage());
        }
    }
}
