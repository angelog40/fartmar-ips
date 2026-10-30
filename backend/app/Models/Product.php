<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_special_control' => 'boolean'];

    public function batches()
    {
        return $this->hasMany(Batch::class);
    }
}
