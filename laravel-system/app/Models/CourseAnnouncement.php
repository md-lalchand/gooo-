<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseAnnouncement extends Model
{
    protected $fillable = ['course_id', 'author_id', 'title', 'body', 'pinned'];
    protected $casts = ['pinned' => 'boolean'];

    public function course() { return $this->belongsTo(Course::class); }
    public function author() { return $this->belongsTo(User::class, 'author_id'); }
}
