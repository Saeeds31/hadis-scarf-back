<?php

namespace Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Addresses\Models\Address;
use Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\CardTransfer\Models\CardTransferReceipt;
use Modules\Coupons\Models\Coupon;
use Modules\Gateway\Models\GatewayTransaction;
use Modules\Shipping\Models\Shipping;

// use Modules\Orders\Database\Factories\OrderFactory;

class Order extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'coupon_id',
        'address_id',
        'shipping_id',
        'user_note',
        'subtotal',
        'club_volume_discount',
        'discount_amount',
        'shipping_cost',
        'total',
        'payment_method',
        'payment_status',
        'status',
    ];
    // #status: pending,reserved, paid, shipped, completed, canceled , returned
    // #payment methods:
    // 'online' → پرداخت آنلاین با درگاه بانکی
    // 'wallet' → پرداخت از کیف پول
    // 'cod' → پرداخت در محل (Cash on Delivery)
    // #payment status:
    // 'pending' → در انتظار پرداخت (default)
    // 'paid' → پرداخت شده
    // 'failed' → پرداخت ناموفق
    // 'refunded' → برگشت داده شده
    public function getStatusLabelAttribute()
    {
        $statuses = [
            'pending' => 'در انتظار پرداخت',
            'paid' => 'پرداخت شده',
            'shipped' => 'ارسال شده',
            'delivered' => 'تحویل داده شده',
            'cancelled' => 'لغو شده',
            'completed' => 'کامل شده',
            'returned' => 'مرجوع شده',
            'card_transfer_pending' => 'در انتظار آپلود رسید',
            'card_transfer_review' => 'در انتظار بررسی ادمین',
            'failed' => 'ناموفق',
        ];
        return $statuses[$this->status] ?? $this->status;
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    // در مدل Order اضافه کن

    public function cardTransferReceipt()
    {
        return $this->hasOne(CardTransferReceipt::class);
    }

    public function getPaymentMethodLabelAttribute()
    {
        $methods = [
            'online' => 'پرداخت اینترنتی',
            'wallet' => 'کیف پول',
            'card_transfer' => 'کارت به کارت',
            'cod' => 'پرداخت در محل',
        ];

        return $methods[$this->payment_method] ?? $this->payment_method;
    }



    public function getPaymentStatusLabelAttribute()
    {
        $statuses = [
            'pending' => 'در انتظار پرداخت',
            'paid' => 'پرداخت شده',
            'failed' => 'ناموفق',
            'refunded' => 'بازگشت داده شده',
        ];

        return $statuses[$this->payment_status] ?? $this->payment_status;
    }
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function shipping()
    {
        return $this->belongsTo(Shipping::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public static function dashboardReport()
    {
        // وضعیت‌های معتبر برای سفارشات موفق
        $validStatuses = ['paid', 'completed', 'shipped', 'delivered'];
        // کوئری پایه برای سفارشات موفق
        $baseQuery = self::where(function ($query) use ($validStatuses) {
            $query->whereIn('status', $validStatuses)
                ->orWhere('payment_status', 'paid');
        });
        return [
            // تعداد کل سفارشات موفق
            'total_orders' => $baseQuery->count(),

            // مجموع مبلغ فروش (فقط سفارشات موفق)
            'total_sales' => $baseQuery->sum('total'),

            // مجموع تخفیف‌ها (فقط سفارشات موفق)
            'total_discount' => $baseQuery->sum('discount_amount'),

            // سفارشات امروز (موفق)
            'today_orders' => self::where(function ($query) use ($validStatuses) {
                $query->whereIn('status', $validStatuses)
                    ->orWhere('payment_status', 'paid');
            })->whereDate('created_at', Carbon::today())->count(),

            // سفارشات ماه جاری (موفق)
            'month_orders' => self::where(function ($query) use ($validStatuses) {
                $query->whereIn('status', $validStatuses)
                    ->orWhere('payment_status', 'paid');
            })->whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
                ->count(),

            // میانگین مبلغ هر سفارش
            'average_order_value' => $baseQuery->avg('total') ?? 0,

            // بیشترین مبلغ سفارش
            'max_order_value' => $baseQuery->max('total') ?? 0,

            // کمترین مبلغ سفارش
            'min_order_value' => $baseQuery->min('total') ?? 0,

            // تعداد سفارشات امروز به تفکیک وضعیت
            'today_status_breakdown' => self::whereDate('created_at', Carbon::today())
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->pluck('count', 'status')
                ->toArray(),

            // تعداد سفارشات ماه جاری به تفکیک روز
            'monthly_daily_breakdown' => self::where(function ($query) use ($validStatuses) {
                $query->whereIn('status', $validStatuses)
                    ->orWhere('payment_status', 'paid');
            })
                ->whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('count(*) as count'),
                    DB::raw('sum(total) as total_sales')
                )
                ->groupBy('date')
                ->orderBy('date')
                ->get(),
        ];
    }

    public function gatewayTransactions()
    {
        return $this->morphMany(

            GatewayTransaction::class,

            'payable'

        );
    }
}
