<?php
namespace App\Models;
use DomainException;
use Illuminate\Database\Eloquent\Model;
class KardexMovement extends Model {
    public $timestamps = false;
    protected $guarded = ["id"];
    protected $casts = ["created_at" => "datetime"];
    protected static function booted(): void {
        static::updating(fn () => throw new DomainException("RN-06: Los movimientos del kardex son inmutables."));
        static::deleting(fn () => throw new DomainException("RN-06: Los movimientos del kardex nunca se borran."));
    }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function batch() { return $this->belongsTo(Batch::class); }
    public function user() { return $this->belongsTo(User::class); }
}
