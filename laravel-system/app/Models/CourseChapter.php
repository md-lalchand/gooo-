<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseChapter extends Model
{
    protected $fillable = ['module_id', 'course_id', 'title', 'summary', 'order_index'];

    public function module() { return $this->belongsTo(CourseModule::class, 'module_id'); }
    public function course() { return $this->belongsTo(Course::class); }
}
