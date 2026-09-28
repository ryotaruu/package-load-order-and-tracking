<?php

namespace App\Jobs;

use App\Jobs\Platforms\ShopBase\LoadOrder;
use App\Libs\BurgerPrint;
use App\Libs\Ebay;
use App\Libs\Platforms\ShopBase;
use App\Models\Provider\BurgerPrint\Order;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class LoadOrderBurgerprint implements ShouldQueue
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
        $order = $this->order;

        try {
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

            $provider = new BurgerPrint($order->order_platform, $shopQuery);

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

            switch ($order->order_platform) {
                case 'shopbase':
                    $orderPlatform->update(['fulfillment_status' => $status]);

                    if ($order->tracking()) {
                        $tracking = $order->tracking() ?? null;
                        $carrier = $getOrder['data']['trackings'][0]['carrier'] ?? null;

                        if (empty($tracking)) {
                            return;
                        }

                        $isTrackingExists = $orderPlatform->fulfillments->contains(function ($fulfillment) use ($tracking) {
                            return $fulfillment->tracking_number === $tracking;
                        });

                        if (!$isTrackingExists) {
                            $dataFulfillment = [];
                            $items = $orderPlatform->items;

                            foreach ($items as $item) {
                                $provider = $item->provider;

                                if ($provider == 'burgerprint') {
                                    $dataFulfillment['fulfillment']['line_items'][] = [
                                        'id' => $item->platform_line_item_id,
                                        'quantity' => $item->quantity,
                                    ];
                                }
                            }

                            if (empty($dataFulfillment['fulfillment']['line_items'])) {
                                return;
                            }

                            $dataFulfillment['fulfillment']['tracking_company'] = $carrier;
                            $dataFulfillment['fulfillment']['tracking_number'] = $tracking;

                            try {
                                $createFulfillment = (new ShopBase($orderPlatform->shop))->createFulfillment($orderPlatform->platform_order_id, $dataFulfillment);

                                if (!$createFulfillment) {
                                    return;
                                }

                                LoadOrder::dispatch($orderPlatform->shop->id, $orderPlatform->platform_order_id)->onQueue('critical');
                            } catch (\Exception $e) {
                                Log::error('Create fulfillment shopbase error', [
                                    'provider' => 'burgerprint',
                                    'platform' => 'ShopBaseOrder',
                                    'order_id' => $orderPlatform->id,
                                    'data' => $dataFulfillment,
                                    'message' => $e->getMessage()
                                ]);

                                throw new Exception($e->getMessage());
                            }
                        }
                    }

                    return;
                case 'woo':
                    $orderPlatform->update(['status' => $status]);
                    return;
                default:
                    return;
            }
        } catch (Exception $e) {
            if (str_contains($e->getMessage(), "Order doesn't exist")) {
                $order->delete();
            }

            throwException($e->getMessage());
        }
    }
}
