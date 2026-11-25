<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicSubject extends Model
{
    protected $fillable = ['academic_class_id', 'name', 'code', 'teacher_id'];

    public function academicClass() { return $this->belongsTo(AcademicClass::class); }
    public function teacher() { return $this->belongsTo(User::class, 'teacher_id'); }
}
