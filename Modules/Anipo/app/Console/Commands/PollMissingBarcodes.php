<?php

namespace Modules\Anipo\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Anipo\Models\AnipoOrder;
use Modules\Anipo\Models\AnipoStatusLog;
use Modules\Anipo\Services\AnipoClient;

class PollMissingBarcodes extends Command
{
    protected $signature = 'anipo:poll-missing-barcodes';
    protected $description = 'چک بارکد سفارش‌های ارسال‌شده به آنی‌پو که هنوز بارکد نگرفته‌اند';

    private int $checkedCount = 0;
    private int $issuedCount = 0;
    private int $failedCount = 0;

    public function handle(AnipoClient $client): int
    {
        Log::channel('daily')->info('anipo:poll-missing-barcodes started');

        try {
            $ordersQuery = AnipoOrder::query()
                ->whereNotNull('anipo_order_code')
                ->whereNotNull('synced_at')
                ->whereNull('anipo_barcode')
                ->where('sync_status', '!=', AnipoOrder::SYNC_CANCELLED)
                ->where('synced_at', '>=', now()->subDays(14));

            $totalOrders = $ordersQuery->count();
            Log::channel('daily')->info("Found {$totalOrders} anipo orders without barcode");

            if ($totalOrders === 0) {
                $this->info('No anipo orders to poll.');
                return self::SUCCESS;
            }

            $ordersQuery->chunkById(100, function ($orders) use ($client) {
                foreach ($orders as $anipoOrder) {
                    $this->processOrder($anipoOrder, $client);
                }
            });

            $this->info("Checked: {$this->checkedCount}, Issued: {$this->issuedCount}, Failed: {$this->failedCount}");
            Log::channel('daily')->info("anipo:poll-missing-barcodes completed - Checked: {$this->checkedCount}, Issued: {$this->issuedCount}, Failed: {$this->failedCount}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            Log::channel('daily')->error('anipo:poll-missing-barcodes failed: ' . $e->getMessage());
            $this->error('Command failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function processOrder(AnipoOrder $anipoOrder, AnipoClient $client): void
    {
        $this->checkedCount++;

        try {
            $oldStatus = $anipoOrder->anipo_status;

            $barcodeRes = $client->getBarcode($anipoOrder->anipo_order_code);

            $anipoOrder->update([
                'last_polled_at' => now(),
                'last_response'  => $barcodeRes,
            ]);

            // اگر بارکد صادر شده بود
            if (($barcodeRes['issued'] ?? false) && !empty($barcodeRes['barcode'])) {
                $anipoOrder->update([
                    'anipo_barcode'     => $barcodeRes['barcode'],
                    'sync_status'       => AnipoOrder::SYNC_BARCODE_ISSUED,
                    'barcode_issued_at' => now(),
                    'anipo_status'      => $barcodeRes['status'] ?? $oldStatus,
                ]);

                AnipoStatusLog::record(
                    $anipoOrder,
                    AnipoStatusLog::SOURCE_POLL,
                    oldStatus: $oldStatus,
                    newStatus: 'barcode_issued',
                    payload: $barcodeRes
                );

                $this->issuedCount++;
                Log::channel('daily')->info("Anipo barcode issued for order #{$anipoOrder->order_id}: {$barcodeRes['barcode']}");
                return;
            }

            // اگر بارکد صادر نشده بود — وضعیت سفارش را هم چک کن
            $orderRes = $client->getOrder($anipoOrder->anipo_order_code);

            $anipoOrder->update([
                'anipo_status' => $orderRes['status'] ?? $anipoOrder->anipo_status,
                'last_response' => $orderRes ?: $anipoOrder->last_response,
            ]);

            AnipoStatusLog::record(
                $anipoOrder,
                AnipoStatusLog::SOURCE_POLL,
                oldStatus: $oldStatus,
                newStatus: $orderRes['status'] ?? 'still_pending',
                payload: $orderRes ?: $barcodeRes
            );

            Log::channel('daily')->info("Anipo barcode still pending for order #{$anipoOrder->order_id}");
        } catch (\Exception $e) {
            $this->failedCount++;

            AnipoStatusLog::record(
                $anipoOrder,
                AnipoStatusLog::SOURCE_POLL,
                oldStatus: $anipoOrder->anipo_status,
                newStatus: 'poll_failed',
                payload: ['error' => $e->getMessage()]
            );

            Log::channel('daily')->error("Anipo poll failed for anipo_order #{$anipoOrder->id}: " . $e->getMessage());
        }
    }
}
