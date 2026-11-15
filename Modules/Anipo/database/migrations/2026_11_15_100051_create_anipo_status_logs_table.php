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
        Schema::create('anipo_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anipo_order_id')
                ->constrained('anipo_orders')
                ->cascadeOnDelete();

            $table->string('source'); // site | anipo | poll | push
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anipo_status_logs');
    }
};
