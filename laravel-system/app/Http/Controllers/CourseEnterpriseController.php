<?php

namespace App\Http\Controllers;

use App\Models\AssignmentSubmission;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseAnnouncement;
use App\Models\CourseAssignment;
use App\Models\CourseChapter;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\LearnerReward;
use App\Models\ScormAttempt;
use App\Models\ScormPackage;
use App\Support\AuditRecorder;
use App\Support\BrandSettings;
use App\Support\TeacherCourseAccess;
use Illuminate\Http\Request;

class CourseEnterpriseController extends Controller
{
    public function storeModule(Request $request)
    {
        $data = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'title' => 'required|string|max:160',
            'description' => 'nullable|string|max:1000',
        ]);
        TeacherCourseAccess::assertCanManageCourse($request->user(), (int) $data['course_id']);
        $order = CourseModule::where('course_id', $data['course_id'])->count() + 1;
        CourseModule::create([
            ...$data,
            'order_index' => $order,
        ]);
        return back()->with('status', "Course module '{$data['title']}' added.");
    }

    public function deleteModule(Request $request, CourseModule $module)
    {
        TeacherCourseAccess::assertCanManageCourse($request->user(), (int) $module->course_id);
        $module->delete();
        return back()->with('status', 'Course module removed.');
    }

    public function storeChapter(Request $request)
    {
        $data = $request->validate([
            'module_id' => 'required|exists:course_modules,id',
            'title' => 'required|string|max:160',
            'summary' => 'nullable|string|max:1000',
        ]);
        $module = CourseModule::findOrFail($data['module_id']);
        TeacherCourseAccess::assertCanManageCourse($request->user(), (int) $module->course_id);
        $order = CourseChapter::where('module_id', $module->id)->count() + 1;
        CourseChapter::create([
            'module_id' => $module->id,
            'course_id' => $module->course_id,
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'order_index' => $order,
        ]);
        return back()->with('status', "Chapter '{$data['title']}' added.");
    }

    public function deleteChapter(Request $request, CourseChapter $chapter)
    {
        TeacherCourseAccess::assertCanManageCourse($request->user(), (int) $chapter->course_id);
        $chapter->delete();
        return back()->with('status', 'Chapter removed.');
    }

    public function storeAssignment(Request $request)
    {
        $data = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'batch_id' => 'nullable|exists:batches,id',
            'title' => 'required|string|max:180',
            'instructions' => 'required|string|max:4000',
            'due_date' => 'nullable|date',
            'max_marks' => 'required|integer|min:1|max:500',
        ]);

        TeacherCourseAccess::assertCanManageCourse($request->user(), (int) $data['course_id']);
        if (!empty($data['batch_id'])) {
            $batch = Batch::findOrFail($data['batch_id']);
            if ((int) $batch->course_id !== (int) $data['course_id']) {
                abort(403, 'Assignment batch must belong to the selected course.');
            }
            TeacherCourseAccess::assertCanManageBatch($request->user(), $batch);
        }

        CourseAssignment::create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);
        AuditRecorder::log($request, 'assignment.created', 'course', (string) $data['course_id'], ['title' => $data['title']]);

        return back()->with('status', "Assignment '{$data['title']}' published.");
    }

    public function deleteAssignment(Request $request, CourseAssignment $assignment)
    {
        TeacherCourseAccess::assertCanManageCourse($request->user(), (int) $assignment->course_id);
        if ($assignment->batch_id) TeacherCourseAccess::assertCanManageBatch($request->user(), $assignment->batch);
        $assignment->delete();
        return back()->with('status', 'Assignment deleted.');
    }

    public function submitAssignment(Request $request, CourseAssignment $assignment)
    {
        $user = $request->user();
        if ($user->role === 'teacher') {
            TeacherCourseAccess::assertCanManageCourse($user, (int) $assignment->course_id);
        }
        $hasAccess = $user->role === 'admin' || ($user->role === 'student' && Enrollment::where('user_id', $user->id)
            ->where('course_id', $assignment->course_id)
            ->where('status', 'approved')
            ->exists());

        if (!$hasAccess) {
            abort(403, 'Approved enrollment in this course is required to submit assignments.');
        }

        $data = $request->validate([
            'submission_text' => 'required|string|max:5000',
            'attachment_url' => 'nullable|string|max:500',
        ]);

        AssignmentSubmission::updateOrCreate(
            ['assignment_id' => $assignment->id, 'user_id' => $user->id],
            [
                'submission_text' => $data['submission_text'],
                'attachment_url' => $data['attachment_url'] ?? null,
                'status' => 'submitted',
            ]
        );

        return back()->with('status', "Assignment '{$assignment->title}' submitted for review.");
    }

    public function gradeSubmission(Request $request, AssignmentSubmission $submission)
    {
        $assignment = $submission->assignment;
        abort_unless($assignment, 404);
        TeacherCourseAccess::assertCanManageCourse($request->user(), (int) $assignment->course_id);
        if ($assignment->batch_id) TeacherCourseAccess::assertCanManageBatch($request->user(), $assignment->batch);
        $max = $assignment->max_marks;
        $data = $request->validate([
            'marks_awarded' => "required|integer|min:0|max:{$max}",
            'feedback' => 'nullable|string|max:2000',
            'status' => 'required|in:graded,returned',
        ]);

        $submission->update([
            'marks_awarded' => $data['marks_awarded'],
            'feedback' => $data['feedback'] ?? '',
            'status' => $data['status'],
            'graded_by' => $request->user()->name,
            'graded_at' => now(),
        ]);

        AuditRecorder::notify(
            $submission->user_id,
            'Assignment Graded: ' . ($assignment?->title ?? 'Coursework'),
            "Score awarded: {$data['marks_awarded']}/{$max}.",
            route('learn'),
            'academics'
        );

        return back()->with('status', 'Assignment submission graded.');
    }

    public function storeAnnouncement(Request $request)
    {
        $data = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'title' => 'required|string|max:180',
            'body' => 'required|string|max:4000',
            'pinned' => 'nullable|boolean',
        ]);

        TeacherCourseAccess::assertCanManageCourse($request->user(), (int) $data['course_id']);
        CourseAnnouncement::create([
            'course_id' => $data['course_id'],
            'author_id' => $request->user()->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'pinned' => $request->boolean('pinned'),
        ]);

        return back()->with('status', 'Course announcement posted.');
    }

    public function deleteAnnouncement(Request $request, CourseAnnouncement $announcement)
    {
        TeacherCourseAccess::assertCanManageCourse($request->user(), (int) $announcement->course_id);
        $announcement->delete();
        return back()->with('status', 'Announcement removed.');
    }

    public function storeScormPackage(Request $request)
    {
        $data = $request->validate([
            'course_id' => 'nullable|exists:courses,id',
            'title' => 'required|string|max:160',
            'identifier' => 'required|string|max:120',
            'xp_reward' => 'required|integer|min:10|max:2000',
            'html_content' => 'required|string|max:30000',
        ]);

        if ($request->user()->role === 'teacher') {
            if (empty($data['course_id'])) abort(403, 'Teachers must attach SCORM packages to an assigned course.');
            TeacherCourseAccess::assertCanManageCourse($request->user(), (int) $data['course_id']);
        }

        $safeXml = '<manifest identifier="' . htmlspecialchars($data['identifier'], ENT_XML1, 'UTF-8') . '" version="1.2"><organizations><organization identifier="org-1"><title>' . htmlspecialchars($data['title'], ENT_XML1, 'UTF-8') . '</title></organization></organizations></manifest>';

        ScormPackage::create([
            'course_id' => $data['course_id'] ?? null,
            'title' => $data['title'],
            'identifier' => $data['identifier'],
            'launch_file' => 'index.html',
            'manifest_xml' => $safeXml,
            'html_content' => $data['html_content'],
            'xp_reward' => $data['xp_reward'],
        ]);

        return back()->with('status', "SCORM 1.2 package '{$data['title']}' published.");
    }

    public function deleteScormPackage(Request $request, ScormPackage $package)
    {
        if ($package->course_id) {
            TeacherCourseAccess::assertCanManageCourse($request->user(), (int) $package->course_id);
        } elseif ($request->user()->role !== 'admin') {
            abort(403, 'Only administrators can remove unassigned SCORM packages.');
        }
        $package->delete();
        return back()->with('status', 'SCORM package removed.');
    }

    public function completeScorm(Request $request, ScormPackage $package)
    {
        $user = $request->user();
        if ($user->role === 'student') {
            if (!$package->course_id || !Enrollment::where('user_id', $user->id)->where('course_id', $package->course_id)->where('status', 'approved')->exists()) {
                abort(403, 'Approved enrollment in the SCORM course is required.');
            }
        } elseif ($user->role === 'teacher') {
            if (!$package->course_id) abort(403, 'Teachers can only preview SCORM packages attached to assigned courses.');
            TeacherCourseAccess::assertCanManageCourse($user, (int) $package->course_id);
        } elseif ($user->role !== 'admin') {
            abort(403);
        }
        $score = max(0, min(100, (int) $request->input('score', 100)));

        $alreadyCompleted = ScormAttempt::where('scorm_package_id', $package->id)
            ->where('user_id', $user->id)
            ->where('lesson_status', 'completed')
            ->exists();

        $xpAwarded = $alreadyCompleted ? 0 : (int) $package->xp_reward;

        ScormAttempt::create([
            'scorm_package_id' => $package->id,
            'user_id' => $user->id,
            'lesson_status' => 'completed',
            'score' => $score,
            'xp_awarded' => $xpAwarded,
            'completed_at' => now(),
        ]);

        if ($xpAwarded > 0) {
            $reward = LearnerReward::firstOrCreate(['user_id' => $user->id], ['xp' => 0, 'streak_days' => 1, 'badges' => [], 'show_on_leaderboard' => true]);
            $reward->increment('xp', $xpAwarded);
        }

        return back()->with('status', "SCORM module '{$package->title}' completed (Score: {$score}%)." . ($xpAwarded > 0 ? " +{$xpAwarded} XP earned!" : ''));
    }

    public function claimCourseCertificate(Request $request, Course $course)
    {
        $user = $request->user();
        $enrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', 'approved')
            ->firstOrFail();

        $totalLessons = $course->lessons()->count();
        $completedCount = count($enrollment->completed_lesson_ids ?? []);
        if ($totalLessons > 0 && $completedCount < $totalLessons) {
            return back()->withErrors(['certificate' => "Complete all {$totalLessons} lessons before claiming your certificate ({$completedCount}/{$totalLessons} done)."]);
        }

        $existing = Certificate::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('revoked', false)
            ->first();

        if ($existing) {
            return redirect()->route('certificates.verify', ['code' => $existing->certificate_code])
                ->with('status', "Your verified certificate code is {$existing->certificate_code}.");
        }

        $brand = BrandSettings::all();
        $prefix = strtoupper($brand['roll_prefix'] ?? 'CERT');
        $code = $prefix . '-CERT-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));

        Certificate::create([
            'certificate_code' => $code,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'student_name' => $user->name,
            'course_title' => $course->title,
            'grade' => 'Completed',
            'issued_by' => $brand['company_name'],
            'issued_at' => now(),
            'revoked' => false,
        ]);

        AuditRecorder::notify($user->id, 'Certificate Issued', "Verified certificate {$code} issued for {$course->title}.", route('certificates.verify', ['code' => $code]), 'certificate');

        return redirect()->route('certificates.verify', ['code' => $code])
            ->with('status', "Verified certificate {$code} issued!");
    }
}
