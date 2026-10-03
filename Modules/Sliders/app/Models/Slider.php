<?php

namespace Modules\Sliders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Sliders\Database\Factories\SliderFactory;
use App\Support\CacheService;

class Slider extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'link',
        'description',
        'image',
        'type',
        'button_text',
    ];
    protected static function booted()
    {
        $clearCache = fn() => CacheService::forgetSliders();
        static::saved($clearCache);
        static::deleted($clearCache);
    }
}
