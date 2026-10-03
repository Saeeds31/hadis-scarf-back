<?php

namespace Modules\Attributes\Models;

use App\Support\CacheService;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Products\Database\Factories\AttributeFactory;

class Attribute extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'value_type'];

    public function values()
    {
        return $this->hasMany(AttributeValue::class);
    }
    protected static function booted()
    {
        $clearCache = fn() => CacheService::forgetAttributes();
        static::saved($clearCache);
        static::deleted($clearCache);
    }
}
