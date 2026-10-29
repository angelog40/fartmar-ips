<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Inventory extends Model {
    protected $guarded = ["id"];
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function batch() { return $this->belongsTo(Batch::class); }
}
