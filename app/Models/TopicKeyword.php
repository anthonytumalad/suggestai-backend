<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TopicKeyword extends Model
{
    use HasFactory;

    protected $fillable = [
        'topic_id',
        'keyword',
        'rank',
        'score',
    ];


    protected $casts = [
        'topic_id' => 'integer',
        'rank' => 'integer',
        'score' => 'float',
    ];

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('rank');
    }
}
