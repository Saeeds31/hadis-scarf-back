<?php
// Modules/Anipo/Http/Controllers/AnipoOrderController.php

namespace Modules\Anipo\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Anipo\Models\AnipoOrder;
use Modules\Anipo\Models\AnipoStatusLog;
use Modules\Anipo\Services\AnipoClient;
use Modules\Anipo\Services\OrderPayloadBuilder;
use Modules\Orders\Models\Order;

class AnipoController extends Controller
{
    public function __construct(
        protected AnipoClient $client,
        protected OrderPayloadBuilder $builder,
    ) {}

    /**
     * ارسال دسته‌ای سفارش‌ها به آنی‌پو
     * body: { order_ids: [1, 2, 3] }
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids'   => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        $success = [];
        $failed  = [];

        foreach ($validated['order_ids'] as $orderId) {
            try {
                $result = $this->sendSingle($orderId);

                if ($result['success']) {
                    $success[] = $result;
                } else {
                    $failed[] = $result;
                }
            } catch (\Throwable $e) {
                Log::channel('daily')->error("Anipo send failed for order #{$orderId}: " . $e->getMessage());
                $failed[] = [
                    'order_id' => $orderId,
                    'success'  => false,
                    'message'  => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => count($failed) === 0,
            'message' => sprintf(
                '%d سفارش موفق، %d ناموفق',
                count($success),
                count($failed)
            ),
            'sent'   => $success,
            'failed' => $failed,
        ]);
    }

    /**
     * منطق ارسال یک سفارش
     */
    protected function sendSingle(int $orderId): array
    {
        $order = Order::with([
            'address.province',
            'address.city',
            'items.product',
            'user',
        ])->find($orderId);

        if (!$order) {
            return [
                'order_id' => $orderId,
                'success'  => false,
                'message'  => 'سفارش یافت نشد',
            ];
        }

        if (!$this->isEligible($order)) {
            return [
                'order_id' => $orderId,
                'success'  => false,
                'message'  => 'سفارش واجد شرایط ارسال نیست (پرداخت نشده)',
            ];
        }

        if (!$order->address?->province || !$order->address?->city) {
            return [
                'order_id' => $orderId,
                'success'  => false,
                'message'  => 'آدرس سفارش ناقص است',
            ];
        }

        return DB::transaction(function () use ($order) {
            $anipoOrder = AnipoOrder::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'anipo_order_code' => $this->generateCode($order),
                    'sync_status'      => AnipoOrder::SYNC_PENDING,
                    'site_status'      => $order->status,
                ]
            );

            if ($anipoOrder->sync_status === AnipoOrder::SYNC_CANCELLED) {
                return [
                    'order_id' => $order->id,
                    'success'  => false,
                    'message'  => 'این سفارش در آنی‌پو لغو شده است',
                ];
            }

            $anipoOrder->update([
                'sync_status' => AnipoOrder::SYNC_SYNCING,
                'site_status' => $order->status,
                'attempts'    => $anipoOrder->attempts + 1,
            ]);

            $payload  = $this->builder->build($order, $anipoOrder);
            $response = $this->client->upsertOrder($payload);

            // موفق
            if (!empty($response['order_code'])) {
                $anipoOrder->update([
                    'sync_status'   => AnipoOrder::SYNC_SYNCED,
                    'synced_at'     => now(),
                    'anipo_status'  => $response['status'] ?? $anipoOrder->anipo_status,
                    'last_response' => $response,
                    'last_error'    => null,
                    'last_error_at' => null,
                ]);

                AnipoStatusLog::record(
                    $anipoOrder,
                    AnipoStatusLog::SOURCE_PUSH,
                    oldStatus: null,
                    newStatus: $response['status'] ?? 'synced',
                    payload: $response
                );

                if (!empty($response['barcode'])) {
                    $anipoOrder->update([
                        'anipo_barcode'     => $response['barcode'],
                        'sync_status'       => AnipoOrder::SYNC_BARCODE_ISSUED,
                        'barcode_issued_at' => now(),
                    ]);

                    AnipoStatusLog::record(
                        $anipoOrder,
                        AnipoStatusLog::SOURCE_PUSH,
                        oldStatus: 'synced',
                        newStatus: 'barcode_issued',
                        payload: ['barcode' => $response['barcode']]
                    );
                }

                return [
                    'order_id'         => $order->id,
                    'success'          => true,
                    'message'          => 'ارسال شد',
                    'anipo_order_code' => $anipoOrder->anipo_order_code,
                ];
            }

            // ناموفق
            $error = $response['message'] ?? 'پاسخ نامعتبر از آنی‌پو';

            $anipoOrder->update([
                'sync_status'   => AnipoOrder::SYNC_FAILED,
                'last_error'    => $error,
                'last_error_at' => now(),
                'last_response' => $response ?: $anipoOrder->last_response,
            ]);

            AnipoStatusLog::record(
                $anipoOrder,
                AnipoStatusLog::SOURCE_PUSH,
                oldStatus: $anipoOrder->anipo_status,
                newStatus: 'sync_failed',
                payload: $response ?: ['error' => $error]
            );

            return [
                'order_id' => $order->id,
                'success'  => false,
                'message'  => $error,
            ];
        });
    }

    protected function isEligible(Order $order): bool
    {
        $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];

        return in_array($order->status, $validStatuses)
            || $order->payment_status === 'paid';
    }

    protected function generateCode(Order $order): string
    {
        $prefix = config('anipo.order_code_prefix', 'ORD');

        return $prefix . '-' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
    }
    /**
     * بروزرسانی وضعیت و چک بارکد از آنی‌پو
     */
    public function refresh(int $orderId): JsonResponse
    {
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'سفارش یافت نشد',
            ], 404);
        }

        $anipoOrder = AnipoOrder::where('order_id', $order->id)->first();

        if (!$anipoOrder || !$anipoOrder->anipo_order_code) {
            return response()->json([
                'success' => false,
                'message' => 'این سفارش هنوز به آنی‌پو ارسال نشده است',
            ], 422);
        }

        // ۱. چک بارکد
        $barcodeRes = $this->client->getBarcode($anipoOrder->anipo_order_code);
        $oldStatus  = $anipoOrder->anipo_status;

        if (($barcodeRes['issued'] ?? false) && !empty($barcodeRes['barcode'])) {
            $anipoOrder->update([
                'anipo_barcode'     => $barcodeRes['barcode'],
                'sync_status'       => AnipoOrder::SYNC_BARCODE_ISSUED,
                'barcode_issued_at' => now(),
                'last_polled_at'    => now(),
                'last_response'     => $barcodeRes,
            ]);

            AnipoStatusLog::record(
                $anipoOrder,
                AnipoStatusLog::SOURCE_POLL,
                oldStatus: $oldStatus,
                newStatus: 'barcode_issued',
                payload: $barcodeRes
            );

            return response()->json([
                'success'      => true,
                'message'      => 'بارکد صادر شده است',
                'issued'       => true,
                'barcode'      => $barcodeRes['barcode'],
                'anipo_status' => $anipoOrder->fresh()->anipo_status,
            ]);
        }

        // ۲. اگر بارکد نبود، وضعیت سفارش را هم چک کن
        $orderRes = $this->client->getOrder($anipoOrder->anipo_order_code);

        $anipoOrder->update([
            'last_polled_at' => now(),
            'anipo_status'   => $orderRes['status'] ?? $anipoOrder->anipo_status,
            'last_response'  => $orderRes ?: $anipoOrder->last_response,
        ]);

        AnipoStatusLog::record(
            $anipoOrder,
            AnipoStatusLog::SOURCE_POLL,
            oldStatus: $oldStatus,
            newStatus: $orderRes['status'] ?? 'still_pending',
            payload: $orderRes ?: $barcodeRes
        );

        return response()->json([
            'success'      => true,
            'message'      => 'بارکد هنوز صادر نشده است',
            'issued'       => false,
            'anipo_status' => $anipoOrder->fresh()->anipo_status,
        ]);
    }
}
