<?php

namespace Modules\Orders\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Coupons\Services\CouponService;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Models\Order;
use Modules\Wallet\Services\WalletService;

class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Expire unpaid orders and restore stock, coupon and wallet balance.';

    // اضافه کردن لاگ‌های شروع و پایان کل فرآیند
    public function handle(
        WalletService $walletService,
        CouponService $couponService,
        NotificationService $notificationService,
    ): int {
        
        Log::channel('daily')->info('===========================');
        Log::channel('daily')->info('START: orders:expire-unpaid command executed');
        Log::channel('daily')->info('Command start time: ' . now()->toDateTimeString());
        
        try {
            
            Log::channel('daily')->info('Step 1: Building initial query for pending orders...');
            
            $ordersQuery = Order::query()
                ->with([
                    'items.variant',
                    'coupon',
                    'user.wallet',
                    'gatewayTransactions',
                ])
                ->where('status', 'pending')
                ->where('payment_status', 'pending')
                ->where('created_at', '<=', now()->subMinutes(10));
            
            Log::channel('daily')->info('Step 2: Query conditions applied');
            Log::channel('daily')->info('- status: pending');
            Log::channel('daily')->info('- payment_status: pending');
            Log::channel('daily')->info('- created_at <= ' . now()->subMinutes(10)->toDateTimeString());
            
            $totalOrders = $ordersQuery->count();
            Log::channel('daily')->info('Step 3: Total pending orders found: ' . $totalOrders);
            
            if ($totalOrders === 0) {
                Log::channel('daily')->info('No pending orders found to process. Exiting gracefully.');
                $this->info('No pending orders to process.');
                return self::SUCCESS;
            }
            
            Log::channel('daily')->info('Step 4: Starting chunk processing with 100 orders per chunk...');
            
            $processedCount = 0;
            $failedCount = 0;
            $chunkNumber = 0;
            
            $ordersQuery->chunkById(100, function ($orders) use (
                $walletService,
                $couponService,
                $notificationService,
                &$processedCount,
                &$failedCount,
                &$chunkNumber
            ) {
                $chunkNumber++;
                Log::channel('daily')->info("--- CHUNK #{$chunkNumber}: Processing " . count($orders) . " orders ---");
                
                foreach ($orders as $orderIndex => $order) {
                    
                    $orderId = $order->id;
                    Log::channel('daily')->info("  → Processing order #{$orderId} (Item {$orderIndex}/" . count($orders) . ")");
                    
                    try {
                        
                        Log::channel('daily')->info("    Starting transaction for order #{$orderId}");
                        
                        DB::transaction(function () use (
                            $order,
                            $walletService,
                            $couponService,
                            $notificationService,
                            $orderId
                        ) {
                            
                            Log::channel('daily')->info("    → Transaction started for order #{$orderId}");
                            
                            // لاگ کردن اطلاعات سفارش قبل از قفل
                            Log::channel('daily')->info("      Order #{$orderId} pre-lock status:", [
                                'status' => $order->status,
                                'payment_status' => $order->payment_status,
                                'created_at' => $order->created_at->toDateTimeString(),
                                'user_id' => $order->user_id,
                            ]);
                            
                            /** @var Order $lockedOrder */
                            $lockedOrder = Order::query()
                                ->with([
                                    'items.variant',
                                    'coupon',
                                    'user.wallet',
                                    'gatewayTransactions',
                                ])
                                ->lockForUpdate()
                                ->find($order->id);
                            
                            if (!$lockedOrder) {
                                Log::channel('daily')->warning("      Order #{$orderId} not found after locking. Skipping.");
                                return;
                            }
                            
                            Log::channel('daily')->info("      Order #{$orderId} locked successfully");
                            
                            // بررسی وضعیت بعد از قفل
                            if (
                                $lockedOrder->status !== 'pending' ||
                                $lockedOrder->payment_status !== 'pending'
                            ) {
                                Log::channel('daily')->info("      Order #{$orderId} status changed during processing. Skipping.", [
                                    'current_status' => $lockedOrder->status,
                                    'current_payment_status' => $lockedOrder->payment_status,
                                ]);
                                return;
                            }
                            
                            // پردازش تراکنش درگاه
                            $gatewayTransaction = $lockedOrder
                                ->gatewayTransactions()
                                ->latest()
                                ->first();
                            
                            if ($gatewayTransaction) {
                                Log::channel('daily')->info("      Gateway transaction found for order #{$orderId}:", [
                                    'transaction_id' => $gatewayTransaction->id,
                                    'status' => $gatewayTransaction->status,
                                    'paid_at' => $gatewayTransaction->paid_at,
                                ]);
                                
                                if (
                                    $gatewayTransaction->paid_at ||
                                    $gatewayTransaction->status === 'paid'
                                ) {
                                    Log::channel('daily')->info("      Order #{$orderId} already paid. Skipping.");
                                    return;
                                }
                                
                                Log::channel('daily')->info("      Updating gateway transaction #{$gatewayTransaction->id} to failed status");
                                
                                $gatewayTransaction->update([
                                    'status' => 'failed',
                                    'message' => 'Payment timeout.',
                                ]);
                                
                                Log::channel('daily')->info("      Gateway transaction updated successfully");
                            } else {
                                Log::channel('daily')->info("      No gateway transaction found for order #{$orderId}");
                            }
                            
                            // بازگردانی موجودی محصولات
                            $itemsCount = $lockedOrder->items->count();
                            Log::channel('daily')->info("      Restoring stock for {$itemsCount} items in order #{$orderId}");
                            
                            foreach ($lockedOrder->items as $item) {
                                if ($item->variant) {
                                    $oldStock = $item->variant->stock;
                                    $item->variant->increment('stock', $item->quantity);
                                    Log::channel('daily')->info("        Item #{$item->id} stock restored: {$oldStock} → " . ($oldStock + $item->quantity) . " (+{$item->quantity})");
                                } else {
                                    Log::channel('daily')->warning("        Item #{$item->id} has no variant. Stock not restored.");
                                }
                            }
                            
                            // آزاد کردن کوپن
                            if ($lockedOrder->coupon) {
                                Log::channel('daily')->info("      Releasing coupon #{$lockedOrder->coupon->id} for order #{$orderId}");
                                try {
                                    $couponService->releaseCoupon(
                                        $lockedOrder->coupon,
                                        $lockedOrder->user_id
                                    );
                                    Log::channel('daily')->info("      Coupon released successfully");
                                } catch (\Exception $e) {
                                    Log::channel('daily')->error("      ❌ Coupon release failed for order #{$orderId}: " . $e->getMessage());
                                    Log::channel('daily')->error("      Stack trace: " . $e->getTraceAsString());
                                    // ادامه می‌دهیم چون نباید کل تراکنش fail شود
                                }
                            } else {
                                Log::channel('daily')->info("      No coupon associated with order #{$orderId}");
                            }
                            
                            // بازگشت مبلغ کیف پول
                            $walletAmount = $lockedOrder->user
                                ->wallet
                                ->transactions()
                                ->where('order_id', $lockedOrder->id)
                                ->where('type', 'debit')
                                ->sum('amount');
                            
                            Log::channel('daily')->info("      Wallet amount to refund for order #{$orderId}: {$walletAmount}");
                            
                            if ($walletAmount > 0) {
                                Log::channel('daily')->info("      Processing wallet refund for order #{$orderId}");
                                try {
                                    $walletService->deposit(
                                        wallet: $lockedOrder->user->wallet,
                                        amount: $walletAmount,
                                        description: "بازگشت وجه سفارش منقضی شده #{$lockedOrder->id}",
                                        order: $lockedOrder,
                                    );
                                    Log::channel('daily')->info("      Wallet refund successful for order #{$orderId}");
                                } catch (\Exception $e) {
                                    Log::channel('daily')->error("      ❌ Wallet refund failed for order #{$orderId}: " . $e->getMessage());
                                    Log::channel('daily')->error("      Stack trace: " . $e->getTraceAsString());
                                    // ادامه می‌دهیم چون نباید کل تراکنش fail شود
                                }
                            } else {
                                Log::channel('daily')->info("      No wallet amount to refund for order #{$orderId}");
                            }
                            
                            // تغییر وضعیت سفارش
                            Log::channel('daily')->info("      Updating order #{$orderId} status to failed");
                            $lockedOrder->update([
                                'status' => 'failed',
                                'payment_status' => 'failed',
                            ]);
                            Log::channel('daily')->info("      Order #{$orderId} status updated successfully");
                            
                            // اعلان
                            Log::channel('daily')->info("      Creating notification for order #{$orderId}");
                            try {
                                $notificationService->create(
                                    'سفارش منقضی شد',
                                    'به دلیل عدم پرداخت در زمان مقرر، سفارش لغو شد.',
                                    'notification_order',
                                    [
                                        'order' => $lockedOrder->id,
                                    ]
                                );
                                Log::channel('daily')->info("      Notification created successfully for order #{$orderId}");
                            } catch (\Exception $e) {
                                Log::channel('daily')->error("      ❌ Notification creation failed for order #{$orderId}: " . $e->getMessage());
                                Log::channel('daily')->error("      Stack trace: " . $e->getTraceAsString());
                                // ادامه می‌دهیم چون نباید کل تراکنش fail شود
                            }
                            
                            Log::channel('daily')->info("    ✅ Transaction completed successfully for order #{$orderId}");
                            
                        }, 3); // تلاش ۳ بار برای تراکنش در صورت deadlock
                        
                        $processedCount++;
                        Log::channel('daily')->info("  ✅ Order #{$orderId} processed successfully (Total processed: {$processedCount})");
                        
                    } catch (\Exception $e) {
                        $failedCount++;
                        Log::channel('daily')->error("  ❌ Order #{$orderId} FAILED:", [
                            'error' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        // ادامه پردازش با سفارش بعدی
                        Log::channel('daily')->info("  Continuing with next order...");
                    }
                    
                }
                
                Log::channel('daily')->info("--- CHUNK #{$chunkNumber} completed ---");
                
            });
            
            Log::channel('daily')->info('Step 5: Chunk processing completed');
            Log::channel('daily')->info('Final summary:', [
                'total_orders_found' => $totalOrders,
                'successfully_processed' => $processedCount,
                'failed' => $failedCount,
            ]);
            
        } catch (\Exception $e) {
            Log::channel('daily')->error('❌ CRITICAL ERROR in orders:expire-unpaid command:', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            Log::channel('daily')->error('Command failed with critical error.');
            $this->error('Command failed with critical error: ' . $e->getMessage());
            
            return self::FAILURE;
        }
        
        Log::channel('daily')->info('END: orders:expire-unpaid command completed successfully');
        Log::channel('daily')->info('Command end time: ' . now()->toDateTimeString());
        Log::channel('daily')->info('===========================');
        
        $this->info('Expired orders processed successfully.');
        $this->info("Processed: {$processedCount} orders, Failed: {$failedCount} orders");
        
        return self::SUCCESS;
    }
}