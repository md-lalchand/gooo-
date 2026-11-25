<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseRoutine extends Model
{
    protected $fillable = ['course_id', 'batch_id', 'day_of_week', 'start_time', 'end_time', 'room_or_link', 'instructor_name'];

    public function course() { return $this->belongsTo(Course::class); }
    public function batch() { return $this->belongsTo(Batch::class); }
}
