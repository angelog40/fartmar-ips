<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['expiration_date' => 'date'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function isExpired(): bool
    {
        return $this->expiration_date->startOfDay()->lte(now()->startOfDay());
    }
}
