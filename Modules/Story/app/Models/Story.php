<?php

namespace Modules\Story\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Story\Database\Factories\StoryFactory;

class Story extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'title',
        'cover',
        'video',
        'link',
        'status',
        'seen_count'
    ];
    protected $table = "stories";
    protected $attributes = [
        'status' => 'draft',
        'seen_count' => 0
    ];
    public function incrementSeenCount()
    {
        $this->increment('seen_count');
    }
    public function scopeActive($query)
    {
        return $query->where('status', 'published');
    }
}
