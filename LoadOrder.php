<?php

namespace App\Jobs\Providers\ZootopCalendar;

use App\Jobs\Platforms\ShopBase\LoadOrder as ShopBaseLoadOrder;
use App\Libs\Platforms\ShopBase;
use App\Models\ProviderOrder;
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

    public function checkTracking(string $carrier, string $tracking)
    {
        $tracking = strtoupper(trim($tracking));
        return match ($carrier) {
            'Yanwen Express' => str_contains($tracking, 'YP') && str_contains($tracking, 'UL'),
            '4PX Express' => str_contains($tracking, '4PX') && str_contains($tracking, 'CN'),
            default => false
        };
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

            $itemsPlatform = $orderPlatform->items;

            $tracking = [];

            foreach ($itemsPlatform as $item) {
                if (in_array($order->order_platform, ['shopbase', 'woo'], true)) {
                    $variantId = $order->order_platform === 'woo'
                        ? $item->variation_id
                        : $item->platform_variant_id;
                    $productId = $order->order_platform === 'woo'
                        ? $item->product_id
                        : $item->platform_product_id;
                    $statusFF = $order->getStatusZootopCalendar($productId, $variantId);

                    $status = match ($statusFF) {
                        'Tracking status', 'Sent', 'Pending' => 'printing',
                        'In transit' => 'shipped',
                        'Delivered' => 'completed',
                        'REFUND', 'CANCEL' => 'cancel',
                        'Alert' => 'onhold',
                        default => 'printing'
                    };

                    $orderPlatform->update([
                        $order->order_platform === 'woo' ? 'status' : 'fulfillment_status' => $status,
                    ]);

                    if ($order->getTrackingZootopCalendar($variantId, $productId)) {
                        $tracking[] = $order->getTrackingZootopCalendar($variantId, $productId);
                    }
                } else {
                    continue;
                }
            }

            if (!empty($tracking)) {
                $trackings = array_unique($tracking);

                foreach ($trackings as $track) {
                    if ($order->order_platform == 'shopbase') {
                        // $createDate = $order->created_at ?? null;

                        // if (empty($createDate)) {
                        //     Log::error('ZooTopBear order create_date is empty', [
                        //         'order_id' => $order->id,
                        //     ]);

                        //     return;
                        // }

                        // try {
                        //     $trackingAvailableAt = now()::parse($createDate)->addDays(5);
                        // } catch (\Throwable $e) {
                        //     Log::error('ZooTopBear order create_date is invalid', [
                        //         'order_id' => $order->id,
                        //         'create_date' => $createDate,
                        //         'message' => $e->getMessage(),
                        //     ]);

                        //     return;
                        // }

                        // if (now()->lt($trackingAvailableAt)) {
                        //     return;
                        // }

                        $tracking = $track ?? null;
                        $carrier = 'Other';

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

                                if ($provider == 'zootopcalendar') {
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

                                ShopBaseLoadOrder::dispatch($orderPlatform->shop->id, $orderPlatform->platform_order_id)->onQueue('critical');
                            } catch (\Exception $e) {
                                logc('error', 'Create fulfillment shopbase error', [
                                    'provider' => 'zootopcalendar',
                                    'platform' => 'ShopBaseOrder',
                                    'order_id' => $orderPlatform->id,
                                    'data' => $dataFulfillment,
                                    'message' => $e->getMessage()
                                ]);

                                return;
                            }
                        }
                    }

                    if ($order->order_platform == 'woo' && $orderPlatform->status != 'completed') {
                        // $createDate = $order->created_at ?? null;

                        // if (empty($createDate)) {
                        //     return;
                        // }

                        // try {
                        //     $trackingAvailableAt = now()::parse($createDate)->addDays(5);
                        // } catch (\Throwable $e) {
                        //     Log::error('ZooTopBear order create_date is invalid', [
                        //         'order_id' => $order->id,
                        //         'create_date' => $createDate,
                        //         'message' => $e->getMessage(),
                        //     ]);

                        //     return;
                        // }

                        // if (now()->lt($trackingAvailableAt)) {
                        //     return;
                        // }

                        $tracking = $track ?? null;
                        $carrier = '17track';

                        if (empty($tracking)) {
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
                                (new \App\Libs\Platforms\WooEcom($orderPlatform->shop))->setOrderTracking($orderPlatform->order_id, $tracking, $carrier);

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
                }
            }
        } catch (\Exception $e) {
            throwException($e->getMessage());
        }
    }
}
