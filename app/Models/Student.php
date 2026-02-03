<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Student extends Authenticatable
{
    use HasFactory, SoftDeletes, Notifiable;

    protected $fillable = [
        'google_id',
        'email',
        'name',
        'profile_picture',
        'access_granted_at',
    ];

    protected $casts = [
        'access_granted_at' => 'datetime:Y-m-d H:i:s',
        'created_at'       => 'datetime:Y-m-d H:i:s',
        'updated_at'       => 'datetime:Y-m-d H:i:s',
        'deleted_at'       => 'datetime:Y-m-d H:i:s',
    ];

    public function scopeWithAccessGranted($query)
    {
        return $query->whereNotNull('access_granted_at');
    }

    public function getProfilePictureUrlAttribute()
    {
        if (empty($this->profile_picture)) {
            return null;
        }

        return asset($this->profile_picture);
    }
}
