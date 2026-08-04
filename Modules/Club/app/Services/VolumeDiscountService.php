<?php

namespace Modules\Club\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Settings\Models\Setting;

class VolumeDiscountService
{
    /**
     * تنظیمات تخفیف حجمی را از دیتابیس می‌خواند
     * اگر تنظیماتی وجود نداشته باشد، null برمی‌گرداند
     */
    private function getDiscountSettings(): ?array
    {
        $cacheKey = 'club_volume_discount_settings';

        return Cache::remember($cacheKey, 3600, function () {
            // بررسی وجود تنظیمات در دیتابیس
            $settings = Setting::where('group', 'club_discount')
                ->whereIn('key', [
                    'volume_discount_min_items',
                    'volume_discount_per_item'
                ])
                ->get()
                ->pluck('value', 'key')
                ->toArray();

            // اگر حداقل یکی از تنظیمات وجود نداشت، null برگردان
            $requiredKeys = [
                'volume_discount_min_items',
                'volume_discount_per_item',
            ];

            foreach ($requiredKeys as $key) {
                if (!array_key_exists($key, $settings)) {
                    Log::info('تنظیمات تخفیف حجمی کامل نیست', ['missing_key' => $key]);
                    return null;
                }
            }

            // اگر تنظیمات غیرفعال باشد، همچنان تنظیمات را برمی‌گردانیم ولی در محاسبه بررسی می‌کنیم
            return [
                'min_items' => (int) $settings['volume_discount_min_items'] ?? 0,
                'discount_per_item' => (int) $settings['volume_discount_per_item'] ?? 0, // به ریال
            ];
        });
    }

    /**
     * محاسبه تخفیف برای سبد خرید
     * @param int $itemCount تعداد کل آیتم‌های سبد خرید
     * @param int $subtotal مبلغ کل سبد خرید
     * @return array ['discount_amount' => int, 'message' => string|null, 'applied' => bool]
     */
    public function calculateDiscount(int $itemCount, int $subtotal): array
    {
        // دریافت تنظیمات
        $settings = $this->getDiscountSettings();

        // اگر تنظیمات وجود نداشت یا ناقص بود، تخفیف صفر برگردان
        if ($settings === null) {
            return [
                'discount_amount' => 0,
                'message' => null,
                'applied' => false,
                'reason' => 'تنظیمات تخفیف وجود ندارد',
            ];
        }

        // بررسی حداقل تعداد آیتم
        if ($itemCount < $settings['min_items']) {
            return [
                'discount_amount' => 0,
                'message' => sprintf(
                    'برای دریافت تخفیف نیاز به حداقل %s آیتم دارید (شما %s آیتم دارید)',
                    number_format($settings['min_items']),
                    number_format($itemCount)
                ),
                'applied' => false,
                'reason' => 'تعداد آیتم کمتر از حداقل است',
            ];
        }

        // محاسبه تخفیف: تعداد آیتم * مبلغ تخفیف هر آیتم
        $discountAmount = $itemCount * $settings['discount_per_item'];

        // اطمینان از اینکه تخفیف از مبلغ کل بیشتر نشود
        $discountAmount = min($discountAmount, $subtotal);

        return [
            'discount_amount' => $discountAmount,
            'message' => sprintf(
                'تخفیف به ازای هر آیتم %s تومان برای %s آیتم',
                number_format($settings['discount_per_item'] / 10),
                number_format($itemCount)
            ),
            'applied' => $discountAmount > 0,
            'settings' => $settings, // برای دیباگ و نمایش در فرانت
        ];
    }

    /**
     * متد کمکی برای دریافت تعداد کل آیتم‌های سبد خرید
     */
    public function getTotalItems($cartItems): int
    {
        return $cartItems->sum('quantity');
    }

    /**
     * بررسی وجود تنظیمات تخفیف در دیتابیس
     */
    public function hasValidSettings(): bool
    {
        $settings = $this->getDiscountSettings();

        if ($settings === null) {
            return false;
        }

        return  $settings['min_items'] > 0 && $settings['discount_per_item'] > 0;
    }

    /**
     * پاک کردن کش تنظیمات (وقتی تنظیمات تغییر می‌کند)
     */
    public function clearCache(): void
    {
        Cache::forget('club_volume_discount_settings');
    }
}
