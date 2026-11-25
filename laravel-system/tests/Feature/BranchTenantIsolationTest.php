<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Batch;
use App\Models\BatchActivityLog;
use App\Models\BatchWaitlist;
use App\Models\Branch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FeeInvoice;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\PayrollRecord;
use App\Models\StudentAdmission;
use App\Models\User;
use App\Services\BatchManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BranchTenantIsolationTest extends TestCase
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

    public function test_branch_cannot_read_or_mutate_other_branch_finance_admissions_orders_or_attendance(): void
    {
        $branchA = $this->makeBranch('North Branch', 'NTH');
        $branchB = $this->makeBranch('South Branch', 'STH');
        $branchUser = $this->makeUser('branch', $branchA->id, 'north.manager@example.test');
        $studentB = $this->makeUser('student', $branchB->id, 'south.student@example.test');
        $employeeB = $this->makeUser('teacher', $branchB->id, 'south.teacher@example.test');

        $admission = StudentAdmission::create([
            'student_id' => $studentB->id,
            'branch_id' => $branchB->id,
            'admission_no' => 'ADM-SOUTH-001',
            'admission_date' => now()->toDateString(),
            'status' => 'active',
        ]);
        $invoice = FeeInvoice::create([
            'invoice_no' => 'FEE-SOUTH-001',
            'user_id' => $studentB->id,
            'branch_id' => $branchB->id,
            'title' => 'Tuition Fee',
            'installment_label' => 'November',
            'amount_due' => 5000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
        $order = Order::create([
            'user_id' => $studentB->id,
            'phone' => '01700000000',
            'delivery_address' => 'South Campus',
            'branch_pickup_id' => $branchB->id,
            'items' => [['name' => 'Book', 'quantity' => 1]],
            'total_amount' => 500,
            'status' => 'processing',
        ]);
        $payroll = PayrollRecord::create([
            'employee_id' => $employeeB->id,
            'month_key' => '2026-11',
            'base_salary' => 30000,
            'net_salary' => 30000,
            'status' => 'pending',
        ]);

        $this->actingAs($branchUser);

        $this->patch(route('portal.admissions.status', $admission), ['status' => 'graduated'])->assertForbidden();
        $this->delete(route('portal.admissions.delete', $admission))->assertForbidden();
        $this->delete(route('portal.fee_invoices.delete', $invoice))->assertForbidden();
        $this->post(route('portal.fee_invoices.pay', $invoice), [
            'payment_amount' => 1000,
            'payment_method' => 'Cash',
        ])->assertForbidden();
        $this->patch(route('portal.orders.update', $order), ['status' => 'shipped'])->assertForbidden();
        $this->patch(route('portal.payroll.paid', $payroll))->assertForbidden();
        $this->post(route('portal.payroll.store'), [
            'employee_id' => $employeeB->id,
            'month_key' => '2026-12',
            'base_salary' => 30000,
        ])->assertForbidden();
        $this->post(route('portal.attendance.store'), [
            'target_id' => $studentB->id,
            'status' => 'present',
            'attendance_date' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertSame('active', $admission->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('pending', $payroll->fresh()->status);
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_branch_cannot_create_admissions_or_fee_invoices_for_another_branches_student(): void
    {
        $branchA = $this->makeBranch('East Branch', 'EST');
        $branchB = $this->makeBranch('West Branch', 'WST');
        $branchUser = $this->makeUser('branch', $branchA->id, 'east.manager@example.test');
        $studentB = $this->makeUser('student', $branchB->id, 'west.student@example.test');

        $this->actingAs($branchUser);

        $this->post(route('portal.admissions.store'), [
            'student_id' => $studentB->id,
            'branch_id' => $branchA->id,
            'admission_date' => now()->toDateString(),
        ])->assertForbidden();

        $this->post(route('portal.fee_invoices.store'), [
            'user_id' => $studentB->id,
            'branch_id' => $branchA->id,
            'title' => 'Tuition',
            'installment_label' => 'November',
            'amount_due' => 5000,
        ])->assertForbidden();

        $this->assertSame(0, StudentAdmission::count());
        $this->assertSame(0, FeeInvoice::count());
    }

    public function test_branch_batch_operations_cannot_cross_branch_or_promote_foreign_waitlists(): void
    {
        $branchA = $this->makeBranch('Central Branch', 'CTR');
        $branchB = $this->makeBranch('Airport Branch', 'AIR');
        $branchUser = $this->makeUser('branch', $branchA->id, 'central.manager@example.test');
        $studentA = $this->makeUser('student', $branchA->id, 'central.student@example.test');
        $studentB = $this->makeUser('student', $branchB->id, 'airport.student@example.test');
        $course = $this->makeCourse();

        $batchA = $this->makeBatch($course, $branchA, 'CENTRAL-01');
        $batchB = $this->makeBatch($course, $branchB, 'AIRPORT-01');
        $enrollment = app(BatchManagementService::class)->enrollStudent(
            $batchA,
            $studentA,
            $this->makeUser('admin', null, 'main.admin@example.test')
        );
        $foreignWaitlist = BatchWaitlist::create([
            'batch_id' => $batchB->id,
            'course_id' => $course->id,
            'user_id' => $studentB->id,
            'priority' => 1,
            'status' => 'waiting',
        ]);

        $this->actingAs($branchUser);

        $this->post(route('portal.batches.enterprise.store'), [
            'course_id' => $course->id,
            'name' => 'Foreign Batch',
            'code' => 'FOREIGN-01',
            'branch_id' => $branchB->id,
        ])->assertForbidden();

        $this->post(route('portal.batches.enroll', $batchA), [
            'student_id' => $studentB->id,
        ])->assertForbidden();

        $this->post(route('portal.batches.transfer', $batchA), [
            'enrollment_id' => $enrollment->id,
            'target_batch_id' => $batchB->id,
        ])->assertForbidden();

        $this->post(route('portal.batches.waitlist.promote', [$batchA, $foreignWaitlist]))->assertNotFound();

        $this->assertSame($batchA->id, $enrollment->fresh()->batch_id);
        $this->assertSame('waiting', $foreignWaitlist->fresh()->status);
        $this->assertDatabaseMissing('batches', ['code' => 'FOREIGN-01']);
    }


    public function test_unassigned_branch_account_cannot_access_batches_or_probe_another_branch_schedule(): void
    {
        $branchA = $this->makeBranch('Local Branch', 'LOC');
        $branchB = $this->makeBranch('Remote Branch', 'REM');
        $unassignedBranchUser = $this->makeUser('branch', null, 'unassigned.manager@example.test');
        $admin = $this->makeUser('admin', null, 'schedule.admin@example.test');
        $course = $this->makeCourse();

        app(BatchManagementService::class)->createOrUpdateEnterpriseBatch([
            'course_id' => $course->id,
            'name' => 'Remote Schedule',
            'code' => 'REMOTE-ROOM-01',
            'branch_id' => $branchB->id,
            'schedules' => [
                ['day_of_week' => 'Mon', 'start_time' => '09:00', 'end_time' => '10:00', 'room_label' => 'Private Room B'],
            ],
        ], $admin);

        $this->actingAs($unassignedBranchUser)
            ->get(route('portal.batches.dashboard'))
            ->assertOk()
            ->assertJsonCount(0, 'batches');

        $this->actingAs($unassignedBranchUser)
            ->post(route('portal.batches.enterprise.store'), [
                'course_id' => $course->id,
                'name' => 'Unassigned Branch Batch',
                'code' => 'UNASSIGNED-01',
            ])->assertForbidden();

        $assignedBranchUser = $this->makeUser('branch', $branchA->id, 'local.manager@example.test');
        $response = $this->actingAs($assignedBranchUser)
            ->post(route('portal.batches.conflicts'), [
                'branch_id' => $branchB->id,
                'schedules' => [
                    ['day_of_week' => 'Mon', 'start_time' => '09:15', 'end_time' => '09:45', 'room_label' => 'Private Room B'],
                ],
            ])
            ->assertOk();

        $this->assertSame([], $response->json('conflicts'));
    }


    public function test_branch_and_teacher_portals_only_include_their_assigned_course_and_batch_data(): void
    {
        $branchA = $this->makeBranch('North Learning Branch', 'NLB');
        $branchB = $this->makeBranch('South Learning Branch', 'SLB');
        $branchUser = $this->makeUser('branch', $branchA->id, 'north.portal@example.test');
        $teacherA = $this->makeUser('teacher', $branchA->id, 'north.teacher@example.test');
        $teacherB = $this->makeUser('teacher', $branchB->id, 'south.teacher@example.test');
        $courseA = $this->makeCourse('North Branch Curriculum');
        $courseB = $this->makeCourse('South Branch Curriculum');
        $batchA = $this->makeBatch($courseA, $branchA, 'NORTH-PORTAL-01');
        $batchB = $this->makeBatch($courseB, $branchB, 'SOUTH-PORTAL-01');
        $batchA->update(['main_teacher_id' => $teacherA->id]);
        $batchB->update(['main_teacher_id' => $teacherB->id]);

        $lessonA = Lesson::create([
            'course_id' => $courseA->id,
            'batch_id' => $batchA->id,
            'lesson_type' => 'recorded',
            'title' => 'North Private Lesson',
            'module_name' => 'North Module',
            'duration' => '30 min',
            'order_index' => 1,
        ]);
        $lessonB = Lesson::create([
            'course_id' => $courseB->id,
            'batch_id' => $batchB->id,
            'lesson_type' => 'recorded',
            'title' => 'South Private Lesson',
            'module_name' => 'South Module',
            'duration' => '30 min',
            'order_index' => 1,
        ]);

        $this->actingAs($branchUser)
            ->get(route('portal.index'))
            ->assertOk()
            ->assertSee('North Branch Curriculum')
            ->assertDontSee('South Branch Curriculum')
            ->assertViewHas('lessons', fn ($lessons) => $lessons->contains('id', $lessonA->id) && !$lessons->contains('id', $lessonB->id));

        $this->actingAs($teacherA)
            ->get(route('portal.index'))
            ->assertOk()
            ->assertSee('North Branch Curriculum')
            ->assertDontSee('South Branch Curriculum')
            ->assertViewHas('lessons', fn ($lessons) => $lessons->contains('id', $lessonA->id) && !$lessons->contains('id', $lessonB->id));
    }

    public function test_branch_dashboard_activity_feed_is_scoped_to_its_own_branch(): void
    {
        $branchA = $this->makeBranch('Uptown Branch', 'UPT');
        $branchB = $this->makeBranch('Downtown Branch', 'DWT');
        $branchUser = $this->makeUser('branch', $branchA->id, 'uptown.manager@example.test');
        $course = $this->makeCourse();
        $batchA = $this->makeBatch($course, $branchA, 'UPT-01');
        $batchB = $this->makeBatch($course, $branchB, 'DWT-01');

        BatchActivityLog::create([
            'batch_id' => $batchA->id,
            'actor_id' => $branchUser->id,
            'actor_name' => $branchUser->name,
            'actor_role' => 'branch',
            'action' => 'attendance_marked',
            'details' => 'Local activity',
        ]);
        BatchActivityLog::create([
            'batch_id' => $batchB->id,
            'actor_id' => $branchUser->id,
            'actor_name' => $branchUser->name,
            'actor_role' => 'branch',
            'action' => 'attendance_marked',
            'details' => 'Foreign activity',
        ]);

        $dashboard = app(BatchManagementService::class)->dashboardAnalytics($branchUser);

        $this->assertSame([$batchA->id], $dashboard['batches']->pluck('id')->all());
        $this->assertSame([$batchA->id], $dashboard['recent_activities']->pluck('batch_id')->all());
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

    private function makeUser(string $role, ?int $branchId, string $email): User
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

    private function makeCourse(?string $title = null): Course
    {
        $title ??= 'Tenant Isolation Course';
        return Course::create([
            'slug' => 'course-' . bin2hex(random_bytes(3)),
            'title' => $title,
            'subtitle' => 'Branch security test',
            'category' => 'Core Programs',
            'level_tag' => 'Level 1',
            'price' => 2000,
            'original_price' => 3000,
            'description' => 'Test course for branch isolation.',
            'status' => 'active',
        ]);
    }

    private function makeBatch(Course $course, Branch $branch, string $code): Batch
    {
        return Batch::create([
            'course_id' => $course->id,
            'name' => $code . ' Batch',
            'code' => $code,
            'branch_id' => $branch->id,
            'schedule' => 'Sat 10 AM',
            'seat_limit' => 25,
            'enrolled_seats' => 0,
            'mode' => 'Hybrid',
            'status' => 'open',
        ]);
    }
}
