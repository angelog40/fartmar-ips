<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Prescription extends Model {
    protected $guarded = ["id"];
    protected $casts = ["valid_until" => "date"];
    public function patient() { return $this->belongsTo(Patient::class); }
    public function doctor() { return $this->belongsTo(User::class, "doctor_id"); }
    public function items() { return $this->hasMany(PrescriptionItem::class); }
}
