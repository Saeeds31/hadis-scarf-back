<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Attributes\Models\AttributeValue;
// use Modules\Products\Database\Factories\ProductVariantFactory;
use App\Support\CacheService;

class ProductVariant extends Model
{
    use HasFactory;
    protected $fillable = ['product_id', 'sku', 'price', 'stock', 'admin_note'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
    public function values()
    {
        return $this->belongsToMany(AttributeValue::class, 'product_variant_values');
    }
    protected static function booted()
    {
        $clearCache = function ($variant) {
            CacheService::forgetProducts();
            CacheService::forget("product_detail_{$variant->product_id}");
        };

        static::saved($clearCache);
        static::deleted($clearCache);
    }
}
