<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('anipo_orders', function (Blueprint $table) {
            $table->id();

            // اتصال به سفارش سایت
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // کد سفارش در آنی‌پو (کلید یکتا برای idempotency)
            $table->string('anipo_order_code')->unique();

            // بارکد پستی
            $table->string('anipo_barcode')->nullable()->index();

            // وضعیت‌ها
            $table->string('sync_status')->default('pending')->index();
            // pending | syncing | synced | failed | barcode_issued | cancelled

            $table->string('anipo_status')->nullable();
            // وضعیت برگشتی از آنی‌پو (اگر داد)

            $table->string('site_status')->nullable();
            // وضعیت سفارش در سایت هنگام ارسال (برای مقایسه/دیباگ)

            // زمان‌ها
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('barcode_issued_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('last_error_at')->nullable();

            // اطلاعات خطا و پاسخ خام
            $table->text('last_error')->nullable();
            $table->json('last_response')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            // اگر خواستی چند رکورد آنی‌پو برای یک سفارش داشته باشی، این را نگذار unique
            // ولی معمولاً یکی کافیه چون anipo_order_code یونیکه

            $table->timestamps();

            $table->unique(['order_id', 'anipo_order_code']);
            $table->index(['sync_status', 'barcode_issued_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anipo_orders');
    }
};
