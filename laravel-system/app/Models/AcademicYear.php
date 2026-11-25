<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicYear extends Model
{
    protected $fillable = ['name', 'start_date', 'end_date', 'is_current', 'status'];
    protected $casts = ['is_current' => 'boolean'];

    public function classes() { return $this->hasMany(AcademicClass::class); }
}
