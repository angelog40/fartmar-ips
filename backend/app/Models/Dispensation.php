<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Dispensation extends Model {
    protected $guarded = ["id"];
    public function items() { return $this->hasMany(DispensationItem::class); }
    public function prescription() { return $this->belongsTo(Prescription::class); }
    public function patient() { return $this->belongsTo(Patient::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function dispenser() { return $this->belongsTo(User::class, "dispensed_by"); }
    public function authorizer() { return $this->belongsTo(User::class, "authorized_by"); }
}
