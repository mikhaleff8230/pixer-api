<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Community extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'avatar' => 'array',
        'cover' => 'array',
        'is_system' => 'boolean',
        'created_by_admin' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function places()
    {
        return $this->hasMany(Place::class);
    }

    public function members()
    {
        return $this->belongsToMany(Profile::class, 'community_user')
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function owner()
    {
        return $this->belongsTo(Profile::class, 'owner_profile_id');
    }
}
