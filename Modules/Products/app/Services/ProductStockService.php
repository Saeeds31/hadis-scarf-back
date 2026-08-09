<?php

namespace Modules\Products\Services;

use Modules\Products\Models\Product;

class ProductStockService
{
    /**
     * وضعیت موجودی محصول را بر اساس تنوع‌ها بروزرسانی می‌کند.
     *
     * اگر حداقل یک تنوع موجود باشد:
     *      product.status = in_stock
     *
     * اگر همه تنوع‌ها ناموجود باشند:
     *      product.status = out_of_stock
     *
     * @param Product $product
     * @return Product
     */
    public function sync(Product $product): Product
    {
        if (!$product->variants()->exists()) {
            return $product;
        }
        $hasAvailableVariant = $product->variants()
            ->where('stock', '>', 0)
            ->exists();

        $newStatus = $hasAvailableVariant
            ? 'published'
            : 'unpublished';

        if ($product->status !== $newStatus) {
            $product->update([
                'status' => $newStatus,
            ]);
        }

        return $product->refresh();
    }
}
