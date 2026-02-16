<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentTopic extends Model
{
    use HasFactory;

    protected $fillable = [
        'suggestion_id',
        'topic_id',
        'probability',
        'is_primary',
    ];

    protected $casts = [
        'suggestion_id' => 'integer',
        'topic_id' => 'integer',
        'probability' => 'float',
        'is_primary' => 'boolean',
    ];

    public function suggestion()
    {
        return $this->belongsTo(Suggestion::class);
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }
}
