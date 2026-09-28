<?php

namespace App\Jobs\Providers\Pgprints;

use App\Jobs\Platforms\ShopBase\LoadOrder as ShopBaseLoadOrder;
use App\Libs\Platforms\ShopBase;
use App\Libs\Providers\Pgprints;
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
                throwException('Error platform !');
            }

            $provider = new Pgprints($order->order_platform, $shopQuery);

            $orderId = $order->reference_id;

            $getOrder = $provider->getOrder($orderId);

            $order->update(['data' => $getOrder['data'][0] ?? $getOrder['data']]);

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
                $createDate = $order->data['createdAt'] ?? null;

                if (empty($createDate)) {
                    Log::error('pgprints order create_date is empty', [
                        'order_id' => $order->id,
                    ]);

                    return;
                }

                try {
                    $trackingAvailableAt = now()::parse($createDate)->addDays(5);
                } catch (\Throwable $e) {
                    Log::error('pgprint order create_date is invalid', [
                        'order_id' => $order->id,
                        'create_date' => $createDate,
                        'message' => $e->getMessage(),
                    ]);

                    return;
                }

                if (now()->lt($trackingAvailableAt)) {
                    return;
                }

                $tracking = $order->tracking() ?? null;
                $carrier = $order->data['childDetails'][0]['carrierCode'] ?? null;

                if (empty($tracking)) {
                    Log::error('pgprint order has tracking status but tracking number is empty', [
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

                        if ($provider == 'pgprints') {
                            $dataFulfillment['fulfillment']['line_items'][] = [
                                'id' => $item->platform_line_item_id,
                                'quantity' => $item->quantity,
                            ];
                        }
                    }

                    if (empty($dataFulfillment['fulfillment']['line_items'])) {
                        // Log::error('No pgprints items found to fulfill', ['order_id' => $orderPlatform->id]);

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
                        Log::error('Create fulfillment shopbase error', [
                            'provider' => 'pgprints',
                            'platform' => 'ShopBaseOrder',
                            'order_id' => $orderPlatform->id,
                            'data' => $dataFulfillment,
                            'message' => $e->getMessage()
                        ]);

                        throw new Exception($e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            throwException($e->getMessage());
        }
    }
}
