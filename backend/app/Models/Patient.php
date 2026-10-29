<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Patient extends Model {
    protected $guarded = ["id"];
    protected $casts = ["birth_date" => "date"];
    public function prescriptions() { return $this->hasMany(Prescription::class); }
}
