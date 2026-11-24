<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A demo request from the public website (platform-level; no subscriber). */
class SiteLead extends Model
{
    protected $fillable = ['name', 'organization', 'phone', 'email', 'city', 'units', 'message', 'consented_at', 'status'];

    protected function casts(): array
    {
        return ['consented_at' => 'datetime'];
    }
}
