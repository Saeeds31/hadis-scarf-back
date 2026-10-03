<?php

namespace Modules\Attributes\Models;

use App\Support\CacheService;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Products\Models\ProductVariant;
// use Modules\Products\Database\Factories\AttributeValueFactory;

class AttributeValue extends Model
{
    use HasFactory;
    protected $fillable = ['attribute_id', 'value', 'extra_value'];

    public function attribute()
    {
        return $this->belongsTo(Attribute::class);
    }
    public function variants()
    {
        return $this->belongsToMany(ProductVariant::class, 'product_variant_values');
    }
    protected static function booted()
    {
        $clearCache = fn() => CacheService::forgetAttributes();
        static::saved($clearCache);
        static::deleted($clearCache);
    }
}
