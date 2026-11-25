<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $fillable = [
        'slug', 'title', 'subtitle', 'category', 'level_tag', 'is_combo',
        'included_courses', 'price', 'original_price', 'enrolled_count',
        'exam_system_included', 'thumbnail', 'description', 'demo_video_title', 'status',
    ];

    protected $casts = [
        'is_combo' => 'boolean',
        'included_courses' => 'array',
        'exam_system_included' => 'boolean',
    ];

    public function lessons()
    {
        return $this->hasMany(Lesson::class)->orderBy('order_index');
    }

    public function batches()
    {
        return $this->hasMany(Batch::class);
    }

    public function modules()
    {
        return $this->hasMany(CourseModule::class)->orderBy('order_index');
    }

    public function assignments()
    {
        return $this->hasMany(CourseAssignment::class)->latest();
    }

    public function announcements()
    {
        return $this->hasMany(CourseAnnouncement::class)->latest();
    }

    public function routines()
    {
        return $this->hasMany(CourseRoutine::class);
    }

    public function scormPackages()
    {
        return $this->hasMany(ScormPackage::class);
    }
}
