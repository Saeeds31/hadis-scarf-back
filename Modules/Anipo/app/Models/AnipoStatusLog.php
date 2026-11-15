<?php

namespace Modules\Anipo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AnipoStatusLog extends Model
{
    use HasFactory;

    protected $table = 'anipo_status_logs';

    protected $fillable = [
        'anipo_order_id',
        'source',
        'old_status',
        'new_status',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    // ─── منابع ───
    public const SOURCE_SITE  = 'site';
    public const SOURCE_ANIPO = 'anipo';
    public const SOURCE_POLL  = 'poll';
    public const SOURCE_PUSH  = 'push';

    public static array $sources = [
        self::SOURCE_SITE  => 'تغییر در سایت',
        self::SOURCE_ANIPO => 'از آنی‌پو',
        self::SOURCE_POLL  => 'بررسی دوره‌ای',
        self::SOURCE_PUSH  => 'ارسال به آنی‌پو',
    ];

    // ─── ارتباط‌ها ───
    public function anipoOrder()
    {
        return $this->belongsTo(AnipoOrder::class);
    }

    // ─── Accessorها ───
    public function getSourceLabelAttribute(): string
    {
        return self::$sources[$this->source] ?? $this->source;
    }

    // ─── Helper ثبت لاگ ───
    public static function record(
        AnipoOrder $anipoOrder,
        string $source,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?array $payload = null
    ): self {
        return static::create([
            'anipo_order_id' => $anipoOrder->id,
            'source'         => $source,
            'old_status'     => $oldStatus,
            'new_status'     => $newStatus,
            'payload'        => $payload,
        ]);
    }
}
