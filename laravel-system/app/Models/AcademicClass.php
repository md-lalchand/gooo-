<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicClass extends Model
{
    protected $fillable = ['academic_year_id', 'name', 'code', 'level'];

    public function academicYear() { return $this->belongsTo(AcademicYear::class); }
    public function subjects() { return $this->hasMany(AcademicSubject::class); }
}
