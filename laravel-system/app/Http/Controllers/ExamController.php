<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Enrollment;
use App\Models\Batch;
use App\Support\TeacherCourseAccess;
use App\Models\LearnerReward;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $examQuery = Exam::with('questions');

        if ($user->role === 'student') {
            $courseIds = Enrollment::where('user_id', $user->id)->where('status', 'approved')->pluck('course_id');
            $examQuery->where(function ($query) use ($courseIds) {
                $query->whereNull('course_id')->orWhereIn('course_id', $courseIds);
            });
        } elseif ($user->role === 'teacher') {
            $courseIds = Batch::where(function ($query) use ($user) {
                $query->where('main_teacher_id', $user->id)
                    ->orWhere('assistant_teacher_id', $user->id)
                    ->orWhereHas('teachers', fn ($sub) => $sub->where('user_id', $user->id));
            })->pluck('course_id');
            $examQuery->where(function ($query) use ($user, $courseIds) {
                $query->where('teacher_id', $user->id)->orWhereIn('course_id', $courseIds);
            });
        } elseif ($user->role === 'branch' && $user->branch_id) {
            $courseIds = Batch::where('branch_id', $user->branch_id)->pluck('course_id');
            $examQuery->whereIn('course_id', $courseIds);
        } elseif ($user->role !== 'admin') {
            $examQuery->whereRaw('1 = 0');
        }

        $exams = $examQuery->latest()->get();
        $attempts = ExamAttempt::where('user_id', $request->user()->id)->with('exam')->latest()->get();
        $hiddenUserIds = LearnerReward::where('show_on_leaderboard', false)->pluck('user_id')->all();
        $leaderboard = ExamAttempt::with(['user', 'exam'])
            ->where('passed', true)
            ->orderByDesc('percentage')
            ->orderByDesc('score')
            ->take(10)
            ->get()
            ->map(function ($att) use ($hiddenUserIds) {
                $att->display_name = in_array($att->user_id, $hiddenUserIds, true)
                    ? ('Learner #' . ($att->user?->roll_number ?: $att->user_id))
                    : ($att->user?->name ?? 'Student');
                return $att;
            });
        return view('exams.index', compact('exams', 'attempts', 'leaderboard'));
    }

    public function submit(Request $request, Exam $exam)
    {
        $user = $request->user();
        if ($user->role === 'student') {
            if ($exam->course_id && !Enrollment::where('user_id', $user->id)->where('course_id', $exam->course_id)->where('status', 'approved')->exists()) {
                abort(403, 'Approved enrollment in this exam course is required.');
            }
        } elseif ($user->role === 'teacher') {
            if ($exam->course_id) {
                TeacherCourseAccess::assertCanManageCourse($user, (int) $exam->course_id);
            } elseif ((int) $exam->teacher_id !== (int) $user->id) {
                abort(403, 'Teachers can only submit exams they created.');
            }
        } elseif ($user->role !== 'admin') {
            abort(403);
        }

        $exam->load('questions');
        $answers = $request->input('answers', []);
        $score = 0;
        $total = 0;

        foreach ($exam->questions as $q) {
            $total += $q->marks;
            if (isset($answers[$q->id]) && (int) $answers[$q->id] === (int) $q->correct_index) {
                $score += $q->marks;
            }
        }

        $pct = $total > 0 ? (int) round(($score / $total) * 100) : 0;
        $grade = $pct >= 80 ? 'A+' : ($pct >= 70 ? 'A' : ($pct >= 60 ? 'A-' : ($pct >= 50 ? 'B' : ($pct >= 40 ? 'C' : 'F'))));

        ExamAttempt::create([
            'exam_id' => $exam->id,
            'user_id' => $request->user()->id,
            'score' => $score,
            'total_marks' => $total,
            'percentage' => $pct,
            'grade' => $grade,
            'passed' => $pct >= $exam->pass_percentage,
            'answers' => $answers,
        ]);

        return back()->with('status', "Exam submitted! Score: {$score}/{$total} ({$grade})");
    }
}
