<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Topic extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'topic_id',
        'original_name',
        'label',
        'language',
        'document_count',
        'representation_score',
    ];

    protected $casts = [
        'document_count' => 'integer',
        'topic_id' => 'integer',
        'representation_score' => 'float',
    ];

    public function session()
    {
        return $this->belongsTo(
            TopicModelingSession::class,
            'session_id'
        );
    }

    public function keywords()
    {
        return $this->hasMany(TopicKeyword::class, 'topic_id')
                    ->orderBy('rank');
    }

    public function suggestions()
    {
        return $this->belongsToMany(
            Suggestion::class,
            'document_topics',
            'topic_id',
            'suggestion_id'
        )
        ->withPivot('probability', 'is_primary')
        ->withTimestamps();
    }

    public function primarySuggestions()
    {
        return $this->suggestions()
                    ->wherePivot('is_primary', true);
    }
}
