<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssignmentSubmission extends Model
{
    protected $fillable = [
        'assignment_id', 'user_id', 'submission_text', 'attachment_url',
        'marks_awarded', 'feedback', 'status', 'graded_by', 'graded_at',
    ];

    protected $casts = ['graded_at' => 'datetime'];

    public function assignment() { return $this->belongsTo(CourseAssignment::class, 'assignment_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
