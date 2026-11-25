<?php

namespace Tests\Feature;

use App\Models\AssignmentSubmission;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Lesson;
use App\Models\ScormPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeacherCourseAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $lockPath;
    private ?string $originalLock = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockPath = storage_path('installed.lock');
        if (is_file($this->lockPath)) {
            $this->originalLock = file_get_contents($this->lockPath) ?: '';
        }
        file_put_contents($this->lockPath, json_encode(['installed' => true]));
    }

    protected function tearDown(): void
    {
        if ($this->originalLock !== null) {
            file_put_contents($this->lockPath, $this->originalLock);
        } else {
            @unlink($this->lockPath);
        }
        parent::tearDown();
    }

    public function test_teacher_can_manage_assigned_course_but_cannot_manage_another_teachers_course(): void
    {
        [$branchA, $branchB] = [$this->makeBranch('North', 'NTH'), $this->makeBranch('South', 'STH')];
        $teacherA = $this->makeUser('teacher', $branchA->id, 'teacher.north@example.test');
        $teacherB = $this->makeUser('teacher', $branchB->id, 'teacher.south@example.test');
        $courseA = $this->makeCourse('North Course');
        $courseB = $this->makeCourse('South Course');
        $batchA = $this->makeBatch($courseA, $branchA, $teacherA, 'NORTH-01');
        $this->makeBatch($courseB, $branchB, $teacherB, 'SOUTH-01');

        $this->actingAs($teacherA)
            ->post(route('portal.modules.store'), [
                'course_id' => $courseA->id,
                'title' => 'My Module',
                'description' => 'Assigned course module',
            ])->assertRedirect();

        $this->assertDatabaseHas('course_modules', ['course_id' => $courseA->id, 'title' => 'My Module']);

        $this->actingAs($teacherA)
            ->post(route('portal.modules.store'), [
                'course_id' => $courseB->id,
                'title' => 'Foreign Module',
            ])->assertForbidden();

        $foreignModule = CourseModule::create([
            'course_id' => $courseB->id,
            'title' => 'Foreign Existing Module',
            'order_index' => 1,
        ]);
        $this->actingAs($teacherA)
            ->delete(route('portal.modules.delete', $foreignModule))
            ->assertForbidden();

        $this->assertDatabaseHas('course_modules', ['id' => $foreignModule->id]);
        $this->assertSame($batchA->id, $batchA->fresh()->id);
    }

    public function test_teacher_cannot_move_or_delete_foreign_lessons_or_grade_foreign_submissions(): void
    {
        $branchA = $this->makeBranch('East', 'EST');
        $branchB = $this->makeBranch('West', 'WST');
        $teacherA = $this->makeUser('teacher', $branchA->id, 'teacher.east@example.test');
        $teacherB = $this->makeUser('teacher', $branchB->id, 'teacher.west@example.test');
        $studentB = $this->makeUser('student', $branchB->id, 'student.west@example.test');
        $courseA = $this->makeCourse('East Course');
        $courseB = $this->makeCourse('West Course');
        $batchA = $this->makeBatch($courseA, $branchA, $teacherA, 'EAST-01');
        $batchB = $this->makeBatch($courseB, $branchB, $teacherB, 'WEST-01');

        $lessonB = \App\Models\Lesson::create([
            'course_id' => $courseB->id,
            'batch_id' => $batchB->id,
            'lesson_type' => 'recorded',
            'title' => 'West Lesson',
            'module_name' => 'West Module',
            'duration' => '30 min',
        ]);

        $assignmentB = CourseAssignment::create([
            'course_id' => $courseB->id,
            'batch_id' => $batchB->id,
            'title' => 'West Assignment',
            'instructions' => 'Private assignment',
            'max_marks' => 100,
            'created_by' => $teacherB->id,
        ]);
        $submissionB = AssignmentSubmission::create([
            'assignment_id' => $assignmentB->id,
            'user_id' => $studentB->id,
            'submission_text' => 'My answer',
            'status' => 'submitted',
        ]);

        $this->actingAs($teacherA)
            ->put(route('portal.lessons.update', $lessonB), [
                'course_id' => $courseA->id,
                'batch_id' => $batchA->id,
                'lesson_type' => 'recorded',
                'title' => 'Moved Lesson',
                'module_name' => 'New Module',
                'duration' => '30 min',
            ])->assertForbidden();

        $this->actingAs($teacherA)
            ->delete(route('portal.lessons.delete', $lessonB))
            ->assertForbidden();

        $this->actingAs($teacherA)
            ->patch(route('portal.submissions.grade', $submissionB), [
                'marks_awarded' => 90,
                'feedback' => 'Good work',
                'status' => 'graded',
            ])->assertForbidden();

        $this->assertSame($courseB->id, $lessonB->fresh()->course_id);
        $this->assertSame('submitted', $submissionB->fresh()->status);
    }

    public function test_teacher_exam_and_scorm_actions_require_an_assigned_course(): void
    {
        $branchA = $this->makeBranch('Central', 'CTR');
        $branchB = $this->makeBranch('Airport', 'AIR');
        $teacherA = $this->makeUser('teacher', $branchA->id, 'teacher.central@example.test');
        $teacherB = $this->makeUser('teacher', $branchB->id, 'teacher.airport@example.test');
        $studentA = $this->makeUser('student', $branchA->id, 'student.central@example.test');
        $courseA = $this->makeCourse('Central Course');
        $courseB = $this->makeCourse('Airport Course');
        $this->makeBatch($courseA, $branchA, $teacherA, 'CENTRAL-01');
        $this->makeBatch($courseB, $branchB, $teacherB, 'AIRPORT-01');

        $this->actingAs($teacherA)
            ->post(route('portal.exams.store'), [
                'title' => 'Unscoped Exam',
                'category' => 'Assessment',
                'prompt' => 'Question?',
                'options' => ['A', 'B', 'C', 'D'],
                'correct_index' => 0,
            ])->assertForbidden();

        $this->actingAs($teacherA)
            ->post(route('portal.exams.store'), [
                'course_id' => $courseB->id,
                'title' => 'Foreign Exam',
                'category' => 'Assessment',
                'prompt' => 'Question?',
                'options' => ['A', 'B', 'C', 'D'],
                'correct_index' => 0,
            ])->assertForbidden();

        $this->actingAs($teacherA)
            ->post(route('portal.exams.store'), [
                'course_id' => $courseA->id,
                'title' => 'Assigned Exam',
                'category' => 'Assessment',
                'prompt' => 'Question?',
                'options' => ['A', 'B', 'C', 'D'],
                'correct_index' => 0,
            ])->assertRedirect();

        $packageB = ScormPackage::create([
            'course_id' => $courseB->id,
            'title' => 'Foreign SCORM',
            'identifier' => 'FOREIGN-SCORM',
            'launch_file' => 'index.html',
            'manifest_xml' => '<manifest />',
            'html_content' => '<p>Private</p>',
            'xp_reward' => 100,
        ]);

        $this->actingAs($teacherA)
            ->post(route('learn.scorm.complete', $packageB), ['score' => 100])
            ->assertForbidden();

        $this->actingAs($studentA)
            ->post(route('learn.scorm.complete', $packageB), ['score' => 100])
            ->assertForbidden();

        $this->assertDatabaseMissing('scorm_attempts', ['scorm_package_id' => $packageB->id]);
    }

    public function test_student_must_be_enrolled_in_scorm_course_to_earn_xp(): void
    {
        $branch = $this->makeBranch('Learner Branch', 'LRN');
        $student = $this->makeUser('student', $branch->id, 'learner@example.test');
        $course = $this->makeCourse('Learner Course');
        $package = ScormPackage::create([
            'course_id' => $course->id,
            'title' => 'Course SCORM',
            'identifier' => 'COURSE-SCORM',
            'launch_file' => 'index.html',
            'manifest_xml' => '<manifest />',
            'html_content' => '<p>Lesson</p>',
            'xp_reward' => 100,
        ]);

        $this->actingAs($student)
            ->post(route('learn.scorm.complete', $package), ['score' => 100])
            ->assertForbidden();

        Enrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'amount_paid' => 2000,
            'status' => 'approved',
        ]);

        $this->actingAs($student)
            ->post(route('learn.scorm.complete', $package), ['score' => 100])
            ->assertRedirect();

        $this->assertDatabaseHas('scorm_attempts', ['scorm_package_id' => $package->id, 'user_id' => $student->id]);
    }


    public function test_students_only_see_and_complete_lessons_assignments_and_exams_for_their_enrolled_batch(): void
    {
        $branch = $this->makeBranch('Student Branch', 'STU');
        $teacherA = $this->makeUser('teacher', $branch->id, 'batch.teacher.a@example.test');
        $teacherB = $this->makeUser('teacher', $branch->id, 'batch.teacher.b@example.test');
        $student = $this->makeUser('student', $branch->id, 'batch.student@example.test');
        $course = $this->makeCourse('Shared Course');
        $batchA = $this->makeBatch($course, $branch, $teacherA, 'STUDENT-A');
        $batchB = $this->makeBatch($course, $branch, $teacherB, 'STUDENT-B');

        $sharedLesson = Lesson::create([
            'course_id' => $course->id,
            'lesson_type' => 'recorded',
            'title' => 'Shared Course Lesson',
            'module_name' => 'Shared Module',
            'duration' => '20 min',
        ]);
        $lessonA = Lesson::create([
            'course_id' => $course->id,
            'batch_id' => $batchA->id,
            'lesson_type' => 'recorded',
            'title' => 'Batch A Private Lesson',
            'module_name' => 'Batch A Module',
            'duration' => '20 min',
        ]);
        $lessonB = Lesson::create([
            'course_id' => $course->id,
            'batch_id' => $batchB->id,
            'lesson_type' => 'recorded',
            'title' => 'Batch B Private Lesson',
            'module_name' => 'Batch B Module',
            'duration' => '20 min',
        ]);

        $assignmentA = CourseAssignment::create([
            'course_id' => $course->id,
            'batch_id' => $batchA->id,
            'title' => 'Batch A Assignment',
            'instructions' => 'Only Batch A students.',
            'max_marks' => 100,
            'created_by' => $teacherA->id,
        ]);
        $assignmentB = CourseAssignment::create([
            'course_id' => $course->id,
            'batch_id' => $batchB->id,
            'title' => 'Batch B Assignment',
            'instructions' => 'Only Batch B students.',
            'max_marks' => 100,
            'created_by' => $teacherB->id,
        ]);

        $enrollment = Enrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'branch_id' => $branch->id,
            'batch_id' => $batchA->id,
            'payment_method' => 'cash',
            'amount_paid' => 2000,
            'status' => 'approved',
            'completed_lesson_ids' => [$lessonB->id],
        ]);

        $examA = Exam::create(['course_id' => $course->id, 'title' => 'Batch A Exam', 'category' => 'Test']);
        $examB = Exam::create(['course_id' => $course->id, 'title' => 'Batch B Exam', 'category' => 'Test']);
        $globalExam = Exam::create(['title' => 'General Exam', 'category' => 'General']);

        $learn = $this->actingAs($student)->get(route('learn'))->assertOk();
        $learn->assertSee('Shared Course Lesson')
            ->assertSee('Batch A Private Lesson')
            ->assertDontSee('Batch B Private Lesson')
            ->assertSee('Batch A Assignment')
            ->assertDontSee('Batch B Assignment')
            ->assertDontSee('STUDENT-B Batch');

        $this->actingAs($student)
            ->post(route('learn.progress'), ['course_id' => $course->id, 'lesson_id' => $lessonB->id])
            ->assertNotFound();

        $this->actingAs($student)
            ->post(route('learn.assignments.submit', $assignmentB), [
                'submission_text' => 'Attempt from another batch',
            ])->assertForbidden();

        $this->actingAs($student)
            ->post(route('learn.certificate.claim', $course))
            ->assertSessionHasErrors('certificate');

        $examIndex = $this->actingAs($student)->get(route('exams.index'))->assertOk();
        $examIndex->assertViewHas('exams', fn ($exams) => $exams->contains('id', $examA->id)
            && $exams->contains('id', $globalExam->id)
            && !$exams->contains('id', $examB->id));

        $this->actingAs($student)
            ->post(route('exams.submit', $examB), ['answers' => []])
            ->assertForbidden();

        $this->assertSame([$lessonB->id], $enrollment->fresh()->completed_lesson_ids);
        $this->assertDatabaseHas('course_assignments', ['id' => $assignmentA->id]);
    }

    private function makeBranch(string $name, string $code): Branch
    {
        return Branch::create([
            'name' => $name,
            'code' => $code,
            'city' => 'Dhaka',
            'address' => $name . ' Address',
            'status' => 'active',
        ]);
    }

    private function makeUser(string $role, int $branchId, string $email): User
    {
        return User::create([
            'name' => ucfirst($role) . ' User',
            'email' => $email,
            'role' => $role,
            'status' => 'active',
            'branch_id' => $branchId,
            'password' => Hash::make('StrongPass123!'),
        ]);
    }

    private function makeCourse(string $title): Course
    {
        return Course::create([
            'slug' => 'course-' . bin2hex(random_bytes(3)),
            'title' => $title,
            'subtitle' => 'Teacher access test',
            'category' => 'Core Programs',
            'level_tag' => 'Level 1',
            'price' => 2000,
            'original_price' => 3000,
            'description' => 'Test course.',
            'status' => 'active',
        ]);
    }

    private function makeBatch(Course $course, Branch $branch, User $teacher, string $code): Batch
    {
        return Batch::create([
            'course_id' => $course->id,
            'name' => $code . ' Batch',
            'code' => $code,
            'branch_id' => $branch->id,
            'main_teacher_id' => $teacher->id,
            'schedule' => 'Sat 10 AM',
            'seat_limit' => 25,
            'enrolled_seats' => 0,
            'mode' => 'Hybrid',
            'status' => 'open',
        ]);
    }
}
