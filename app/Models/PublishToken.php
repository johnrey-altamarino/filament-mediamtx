<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublishToken extends Model
{
    protected $fillable = [
        'name', 'path', 'token', 'is_enabled', 'created_by_user_id', 'last_generated_at', 'notes',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'last_generated_at' => 'datetime',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
