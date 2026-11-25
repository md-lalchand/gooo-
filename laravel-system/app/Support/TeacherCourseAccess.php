<?php

namespace App\Support;

use App\Models\Batch;
use App\Models\User;

class TeacherCourseAccess
{
    public static function assertCanManageCourse(User $actor, int $courseId): void
    {
        if ($actor->role === 'admin') {
            return;
        }

        if ($actor->role !== 'teacher' || !Batch::where('course_id', $courseId)->where(function ($query) use ($actor) {
            $query->where('main_teacher_id', $actor->id)
                ->orWhere('assistant_teacher_id', $actor->id)
                ->orWhereHas('teachers', fn ($sub) => $sub->where('user_id', $actor->id));
        })->exists()) {
            abort(403, 'Teachers can only manage courses assigned to them.');
        }
    }

    public static function assertCanManageBatch(User $actor, Batch $batch): void
    {
        if ($actor->role === 'admin') {
            return;
        }

        $assigned = $actor->role === 'teacher'
            && ((int) $batch->main_teacher_id === (int) $actor->id
                || (int) $batch->assistant_teacher_id === (int) $actor->id
                || $batch->teachers()->where('user_id', $actor->id)->exists());

        if (!$assigned) {
            abort(403, 'Teachers can only manage batches assigned to them.');
        }
    }
}
