<?php

namespace Tests\Feature;

use App\Models\AssignmentSubmission;
use App\Models\ClaimedEbook;
use App\Models\ContactMessage;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CustomPage;
use App\Models\Ebook;
use App\Models\Enrollment;
use App\Models\FeeInvoice;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\Product;
use App\Models\ScormPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FullPlatformParityTest extends TestCase
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

    public function test_static_pages_contact_cms_profile_academics_assignments_and_ebooks_parity(): void
    {
        $admin = User::create([
            'name' => 'Admin Director',
            'email' => 'director@academy.example',
            'role' => 'admin',
            'status' => 'active',
            'password' => Hash::make('AdminSecret123!'),
        ]);

        $student = User::create([
            'name' => 'Nadia Karim',
            'email' => 'nadia@academy.example',
            'role' => 'student',
            'status' => 'active',
            'roll_number' => 'ACD-2026-0001',
            'password' => Hash::make('StudentSecret123!'),
        ]);

        // 1. Static & Policy Pages + Contact Inquiry
        $this->get('/about-us')->assertOk()->assertSee('Active Courses');
        $this->get('/contact-us')->assertOk()->assertSee('Send an Inquiry');
        $this->get('/terms-and-conditions')->assertOk()->assertSee('Terms');
        $this->get('/privacy-policy')->assertOk()->assertSee('Privacy');
        $this->get('/refund-policy')->assertOk()->assertSee('Refund');

        $this->post(route('contact.submit'), [
            'name' => 'Visitor User',
            'email' => 'visitor@example.com',
            'subject' => 'Batch Schedule Inquiry',
            'message' => 'Please share evening cohort details.',
        ])->assertRedirect();
        $this->assertDatabaseHas('contact_messages', ['email' => 'visitor@example.com', 'status' => 'new']);
        $contact = ContactMessage::firstOrFail();

        $this->actingAs($admin)->patch(route('portal.contacts.update', $contact), ['status' => 'replied'])->assertRedirect();
        $this->assertSame('replied', $contact->fresh()->status);

        // 2. Custom CMS Page
        $this->actingAs($admin)->post(route('portal.custom_pages.store'), [
            'title' => 'Scholarship Guide',
            'slug' => 'scholarships',
            'subtitle' => 'Merit grants for learners',
            'content' => 'Apply before October 30 for merit scholarships.',
            'published' => '1',
            'show_in_footer' => '1',
        ])->assertRedirect();
        $this->get('/page/scholarships')->assertOk()->assertSee('Scholarship Guide');
        $this->assertSame(1, CustomPage::count());

        // 3. Self-Service Profile & Password Change
        $this->actingAs($student)->get('/profile')->assertOk()->assertSee('Nadia Karim');
        $this->actingAs($student)->patch(route('profile.update'), [
            'name' => 'Nadia Karim Updated',
            'phone' => '+8801700000000',
            'bio' => 'Focused IELTS candidate.',
        ])->assertRedirect();
        $this->assertSame('Nadia Karim Updated', $student->fresh()->name);

        $this->actingAs($student)->put(route('profile.password'), [
            'current_password' => 'StudentSecret123!',
            'password' => 'NewStudentPass456!',
            'password_confirmation' => 'NewStudentPass456!',
        ])->assertRedirect();
        $this->assertTrue(Hash::check('NewStudentPass456!', $student->fresh()->password));

        // 4. Academic Year, Class, Admission & Fee Invoice
        $this->actingAs($admin)->post(route('portal.academic_years.store'), [
            'name' => '2026-2027 Session',
            'is_current' => '1',
        ])->assertRedirect();
        $this->actingAs($admin)->post(route('portal.academic_classes.store'), [
            'name' => 'Executive Foundation',
            'code' => 'EXF-101',
            'level' => 'Intermediate',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('portal.fee_invoices.store'), [
            'user_id' => $student->id,
            'title' => 'Term 1 Tuition',
            'installment_label' => 'Installment 1',
            'amount_due' => 500,
        ])->assertRedirect();
        $invoice = FeeInvoice::firstOrFail();

        $this->actingAs($admin)->post(route('portal.fee_invoices.pay', $invoice), [
            'payment_amount' => 500,
            'payment_method' => 'Campus Desk',
        ])->assertRedirect();
        $this->assertSame('paid', $invoice->fresh()->status);

        // 5. Course Modules, Assignments, SCORM & Auto-Certificate
        $course = Course::create([
            'slug' => 'applied-grammar-pro',
            'title' => 'Applied Grammar Pro',
            'subtitle' => 'Master complex sentence structures',
            'category' => 'Grammar',
            'level_tag' => 'Intermediate',
            'price' => 0,
            'original_price' => 100,
            'description' => 'Comprehensive grammar studio.',
            'status' => 'active',
        ]);
        $lesson = Lesson::create([
            'course_id' => $course->id,
            'lesson_type' => 'recorded',
            'title' => 'Clause Mastery',
            'module_name' => 'Module 1',
            'duration' => '20:00',
            'order_index' => 1,
        ]);

        Enrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'payment_method' => 'Free',
            'amount_paid' => 0,
            'status' => 'approved',
            'completed_lesson_ids' => [$lesson->id],
        ]);

        $this->actingAs($admin)->post(route('portal.modules.store'), [
            'course_id' => $course->id,
            'title' => 'Module 1: Syntax & Clauses',
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('portal.assignments.store'), [
            'course_id' => $course->id,
            'title' => 'Essay Clause Analysis',
            'instructions' => 'Write 3 complex sentences.',
            'max_marks' => 50,
        ])->assertRedirect();
        $assignment = CourseAssignment::firstOrFail();

        $this->actingAs($student)->post(route('learn.assignments.submit', $assignment), [
            'submission_text' => 'Although the weather shifted, the team continued working diligently.',
        ])->assertRedirect();
        $submission = AssignmentSubmission::firstOrFail();

        $this->actingAs($admin)->patch(route('portal.submissions.grade', $submission), [
            'marks_awarded' => 47,
            'feedback' => 'Well structured complex clause.',
            'status' => 'graded',
        ])->assertRedirect();
        $this->assertSame(47, $submission->fresh()->marks_awarded);

        $scorm = ScormPackage::create([
            'course_id' => $course->id,
            'title' => 'Interactive Syntax Drill',
            'identifier' => 'SCORM-SYN-01',
            'launch_file' => 'index.html',
            'manifest_xml' => '<manifest/>',
            'html_content' => '<p>Drill</p>',
            'xp_reward' => 120,
        ]);
        $this->actingAs($student)->post(route('learn.scorm.complete', $scorm), ['score' => 100])->assertRedirect();
        $this->assertDatabaseHas('scorm_attempts', ['scorm_package_id' => $scorm->id, 'user_id' => $student->id]);

        $this->actingAs($student)->post(route('learn.certificate.claim', $course))->assertRedirect();
        $this->assertDatabaseHas('certificates', ['user_id' => $student->id, 'course_id' => $course->id]);

        // 6. Persistent Claimed Ebook & Store Coupon Order
        $ebook = Ebook::create([
            'title' => 'Grammar Pocket Guide',
            'level' => 'All',
            'is_free' => true,
            'price' => 0,
            'original_price' => 0,
            'author' => 'Faculty',
            'pages_count' => 25,
            'summary' => 'Quick reference.',
        ]);
        $this->actingAs($student)->post(route('ebooks.claim', $ebook))->assertRedirect();
        $this->assertSame(1, ClaimedEbook::where('user_id', $student->id)->count());

        Coupon::create([
            'code' => 'BOOK20',
            'discount_percent' => 20,
            'active' => true,
            'applies_to' => 'products',
        ]);
        $product = Product::create([
            'title' => 'Grammar Workbook',
            'category' => 'Books',
            'price' => 100,
            'original_price' => 120,
            'stock' => 5,
        ]);
        $this->actingAs($student)->post(route('shop.order'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'phone' => '+8801700000000',
            'delivery_address' => '100 Main Road',
            'coupon_code' => 'BOOK20',
        ])->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertSame(20, $order->discount_amount);
        $this->assertSame(130, $order->total_amount); // 100 - 20 + 50 delivery
    }
}
