<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentAdmission extends Model
{
    protected $fillable = [
        'student_id', 'academic_year_id', 'academic_class_id', 'branch_id',
        'admission_no', 'admission_date', 'status', 'notes',
    ];

    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function academicYear() { return $this->belongsTo(AcademicYear::class); }
    public function academicClass() { return $this->belongsTo(AcademicClass::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
}
