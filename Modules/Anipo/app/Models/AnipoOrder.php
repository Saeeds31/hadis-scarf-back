<?php

namespace Modules\Anipo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Orders\Models\Order;

// use Modules\Anipo\Database\Factories\AnipoOrderFactory;

class AnipoOrder extends Model
{
    use HasFactory;

    protected $table = 'anipo_orders';

    protected $fillable = [
        'order_id',
        'anipo_order_code',
        'anipo_barcode',
        'sync_status',
        'anipo_status',
        'site_status',
        'synced_at',
        'barcode_issued_at',
        'last_polled_at',
        'last_error_at',
        'last_error',
        'last_response',
        'attempts',
    ];

    protected $casts = [
        'synced_at'          => 'datetime',
        'barcode_issued_at'  => 'datetime',
        'last_polled_at'     => 'datetime',
        'last_error_at'      => 'datetime',
        'last_response'      => 'array',
        'attempts'           => 'integer',
    ];

    // ─── ثابت‌های وضعیت sync ───
    public const SYNC_PENDING         = 'pending';
    public const SYNC_SYNCING         = 'syncing';
    public const SYNC_SYNCED          = 'synced';
    public const SYNC_FAILED          = 'failed';
    public const SYNC_BARCODE_ISSUED  = 'barcode_issued';
    public const SYNC_CANCELLED       = 'cancelled';

    // ─── ارتباط‌ها ───
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function statusLogs()
    {
        return $this->hasMany(AnipoStatusLog::class);
    }

    // ─── Helperها ───
    public function markSynced(array $response = []): void
    {
        $this->update([
            'sync_status'   => self::SYNC_SYNCED,
            'synced_at'     => now(),
            'last_response' => $response,
            'last_error'    => null,
            'last_error_at' => null,
            'attempts'      => $this->attempts + 1,
        ]);
    }

    public function markFailed(string $error, array $response = []): void
    {
        $this->update([
            'sync_status'   => self::SYNC_FAILED,
            'last_error'    => $error,
            'last_error_at' => now(),
            'last_response' => $response ?: $this->last_response,
            'attempts'      => $this->attempts + 1,
        ]);
    }

    public function markBarcodeIssued(string $barcode): void
    {
        $this->update([
            'anipo_barcode'      => $barcode,
            'sync_status'        => self::SYNC_BARCODE_ISSUED,
            'barcode_issued_at'  => now(),
            'last_polled_at'     => now(),
        ]);
    }

    // ─── Scopeها ───
    public function scopeNeedsBarcodePolling($query)
    {
        return $query->whereNotNull('synced_at')
            ->whereNull('anipo_barcode')
            ->where('sync_status', '!=', self::SYNC_CANCELLED)
            ->where('synced_at', '>=', now()->subDays(14));
    }
}
