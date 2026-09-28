<?php

namespace App\Jobs\Providers\Toaddit;

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

    public function checkTracking($carrier, $tracking)
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
                    $statusFF = $order->getStatusToAddIt($variantId, $productId);

                    $status = match ($statusFF) {
                        'In production' => 'printing',
                        'Shipped', 'Resended' => 'shipped',
                        'Delivered' => 'completed',
                        'Canceled', 'Refunded' => 'cancel',
                        'Abnormal', 'waitting comfirm', 'waitting for design' => 'onhold',
                        default => 'printing'
                    };

                    $orderPlatform->update([
                        $order->order_platform === 'woo' ? 'status' : 'fulfillment_status' => $status,
                    ]);

                    if ($order->order_platform === 'shopbase' && $order->getTrackingToAddIt($variantId, $productId)) {
                        $tracking[] = $order->getTrackingToAddIt($variantId, $productId);
                    }
                } else {
                    continue;
                }
            }

            if (!empty($tracking)) {
                $trackings = array_unique($tracking);

                foreach ($trackings as $track) {
                    $tracking = $track ?? null;

                    $carrier = 'Other';

                    if (empty($tracking)) {
                        logc('error', 'ToAddIt order has tracking status but tracking number is empty', [
                            'order_id' => $order->id
                        ]);

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

                            if ($provider == 'toaddit') {
                                $dataFulfillment['fulfillment']['line_items'][] = [
                                    'id' => $item->platform_line_item_id,
                                    'quantity' => $item->quantity,
                                ];
                            }
                        }

                        if (empty($dataFulfillment['fulfillment']['line_items'])) {
                            // logc('error', 'No ToAddIt items found to fulfill', ['order_id' => $orderPlatform->id]);
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
                                'provider' => 'ToAddIt',
                                'platform' => 'ShopBaseOrder',
                                'order_id' => $orderPlatform->id,
                                'data' => $dataFulfillment,
                                'message' => $e->getMessage()
                            ]);

                            return;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            throwException($e->getMessage());
        }
    }
}
