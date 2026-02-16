<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TopicModelingSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'source_type',
        'total_topics',
        'total_documents',
        'outliers',
        'model_parameters',
        'status',
    ];

    protected $casts = [
        'total_topics' => 'integer',
        'total_documents' => 'integer',
        'outliers' => 'integer',
        'model_parameters' => 'array',
    ];

    public function topics()
    {
        return $this->hasMany(Topic::class, 'session_id');
    }

    /**
     * Scope: completed sessions
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('created_at');
    }
}
