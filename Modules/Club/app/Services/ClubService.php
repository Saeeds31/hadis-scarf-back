<?php

namespace Modules\Club\Services;

class ClubService
{
    protected VolumeDiscountService $volumeDiscount;

    public function __construct(VolumeDiscountService $volumeDiscount)
    {
        $this->volumeDiscount = $volumeDiscount;
    }

    /**
     * محاسبه تمام تخفیف‌های قابل اعمال برای سبد خرید
     */
    public function calculateVolumeDiscount($cartItems, int $subtotal)
    {

        $totalItems = $this->volumeDiscount->getTotalItems($cartItems);

        // تخفیف حجمی
        $volumeDiscount = $this->volumeDiscount->calculateDiscount($totalItems, $subtotal);

        return $volumeDiscount;
    }
    public function calculateAllDiscounts($cartItems, int $subtotal): array
    {
        $volumeDiscount = $this->calculateVolumeDiscount($cartItems, $subtotal);
        $totalDiscount=$volumeDiscount;
        return [
            'total_discount' => $totalDiscount,
            'has_discount' => $totalDiscount > 0,
            'breakdown' => [
                'volume' => $volumeDiscount,
            ],
        ];
    }

    /**
     * بررسی اینکه آیا باشگاه تخفیفی برای سبد خرید دارد
     */
    public function hasAnyDiscount($cartItems, int $subtotal): bool
    {
        $result = $this->calculateAllDiscounts($cartItems, $subtotal);
        return $result['has_discount'];
    }
}
