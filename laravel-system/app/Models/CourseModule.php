<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseModule extends Model
{
    protected $fillable = ['course_id', 'title', 'description', 'order_index'];

    public function course() { return $this->belongsTo(Course::class); }
    public function chapters() { return $this->hasMany(CourseChapter::class, 'module_id')->orderBy('order_index'); }
}
