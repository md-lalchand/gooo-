<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\Ebook;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use App\Notifications\PasswordRecoveryToken;
use Tests\TestCase;

class SecurityAuditAndPaymentWebhookTest extends TestCase
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
        config(['services.payment_webhook_secret' => 'test-dedicated-webhook-secret']);
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



    public function test_registration_rejects_weak_passwords(): void
    {
        $this->post(route('register.post'), [
            'name' => 'Weak Password User',
            'email' => 'weak-password@student.example',
            'phone' => '01710000000',
            'role' => 'student',
            'password' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'weak-password@student.example']);
    }

    public function test_self_service_password_recovery_emails_a_long_one_time_token(): void
    {
        Notification::fake();

        $user = User::create([
            'name' => 'Recovery Student',
            'email' => 'recovery@student.example',
            'role' => 'student',
            'status' => 'active',
            'password' => Hash::make('ExistingPass123!'),
        ]);

        $this->post(route('recover.request'), [
            'email' => $user->email,
            'expected_role' => 'student',
        ])->assertRedirect(route('recover', ['role' => 'student']));

        $user->refresh();
        $this->assertNotEmpty($user->password_reset_token_hash);
        $this->assertNotNull($user->password_reset_expires_at);
        Notification::assertSentTo($user, PasswordRecoveryToken::class, function (PasswordRecoveryToken $notification) use ($user): bool {
            $rawTokenHash = $notification->tokenHash();

            return strlen($rawTokenHash) === 64
                && hash_equals($rawTokenHash, $user->password_reset_token_hash);
        });
    }


    public function test_password_recovery_rejects_weak_passwords_and_consumes_a_valid_token_once(): void
    {
        Notification::fake();

        $user = User::create([
            'name' => 'Strong Recovery Student',
            'email' => 'strong-recovery@student.example',
            'role' => 'student',
            'status' => 'active',
            'password' => Hash::make('ExistingPass123!'),
            'remember_token' => 'old-remember-token',
        ]);

        $this->post(route('recover.request'), [
            'email' => $user->email,
            'expected_role' => 'student',
        ])->assertRedirect();

        $notification = Notification::sent($user, PasswordRecoveryToken::class)->first();
        $this->assertNotNull($notification);
        $tokenProperty = new \ReflectionProperty($notification, 'token');
        $rawToken = $tokenProperty->getValue($notification);

        $this->post(route('recover.confirm'), [
            'email' => $user->email,
            'token' => $rawToken,
            'password' => 'weak',
            'expected_role' => 'student',
        ])->assertSessionHasErrors('password');

        $this->assertNotEmpty($user->fresh()->password_reset_token_hash);

        $this->post(route('recover.confirm'), [
            'email' => $user->email,
            'token' => $rawToken,
            'password' => 'StrongReset123!',
            'expected_role' => 'student',
        ])->assertRedirect(route('login', ['role' => 'student']));

        $user->refresh();
        $this->assertTrue(Hash::check('StrongReset123!', $user->password));
        $this->assertNull($user->password_reset_token_hash);
        $this->assertNull($user->password_reset_expires_at);
        $this->assertNotSame('old-remember-token', $user->remember_token);

        $this->post(route('recover.confirm'), [
            'email' => $user->email,
            'token' => $rawToken,
            'password' => 'AnotherStrong123!',
            'expected_role' => 'student',
        ])->assertSessionHasErrors('token');

        $this->assertTrue(Hash::check('StrongReset123!', $user->fresh()->password));
    }

    public function test_password_recovery_rejects_expired_tokens(): void
    {
        $token = 'A'.str_repeat('B', 63);
        $user = User::create([
            'name' => 'Expired Recovery Student',
            'email' => 'expired-recovery@student.example',
            'role' => 'student',
            'status' => 'active',
            'password' => Hash::make('ExistingPass123!'),
            'password_reset_token_hash' => hash('sha256', $token),
            'password_reset_expires_at' => now()->subMinute(),
        ]);

        $this->post(route('recover.confirm'), [
            'email' => $user->email,
            'token' => $token,
            'password' => 'StrongReset123!',
            'expected_role' => 'student',
        ])->assertSessionHasErrors('token');

        $this->assertTrue(Hash::check('ExistingPass123!', $user->fresh()->password));
    }

    public function test_guardian_registration_requires_student_phone_or_hmac_linking_code(): void
    {
        $branch = Branch::create([
            'name' => 'Central Campus',
            'code' => 'CNT',
            'city' => 'Dhaka',
            'address' => '10 Main St',
            'status' => 'active',
        ]);

        $student = User::create([
            'name' => 'Nadia Karim',
            'email' => 'nadia@student.example',
            'phone' => '01710000088',
            'role' => 'student',
            'branch_id' => $branch->id,
            'roll_number' => 'AEC-2026-8801',
            'password' => Hash::make('StudentPass123!'),
        ]);

        // 1. Missing/wrong verification must fail and not create user or link
        $this->post(route('register.post'), [
            'name' => 'Fake Guardian',
            'email' => 'fake.guardian@example.com',
            'phone' => '01811111111',
            'role' => 'guardian',
            'branch_id' => $branch->id,
            'student_roll_link' => 'AEC-2026-8801',
            'student_verification' => '01999999999',
            'password' => 'GuardPass123!',
        ])->assertSessionHasErrors('student_verification');

        $this->assertNull(User::where('email', 'fake.guardian@example.com')->first());

        // 2. Valid HMAC linking code succeeds and enters pending approval
        $linkCode = AuthController::guardianLinkCode($student);
        $this->post(route('register.post'), [
            'name' => 'Verified Parent',
            'email' => 'verified.parent@example.com',
            'phone' => '01822223333',
            'role' => 'guardian',
            'branch_id' => $branch->id,
            'student_roll_link' => 'AEC-2026-8801',
            'student_verification' => $linkCode,
            'password' => 'GuardPass123!',
        ])->assertRedirect(route('login', ['role' => 'guardian']));

        $guardian = User::where('email', 'verified.parent@example.com')->firstOrFail();
        $this->assertSame('pending', $guardian->status);
        $this->assertTrue(
            DB::table('guardian_student')
                ->where('guardian_id', $guardian->id)
                ->where('student_id', $student->id)
                ->exists()
        );
    }

    public function test_branch_teacher_registration_pending_approval_admin_modify_and_password_recovery(): void
    {
        $admin = User::create([
            'name' => 'Main Admin',
            'email' => 'admin@academy.example',
            'phone' => '01700000001',
            'role' => 'admin',
            'status' => 'active',
            'password' => Hash::make('AdminPass123!'),
        ]);

        // Branch self-registers -> creates inactive branch + pending branch account
        $this->post(route('register.post'), [
            'name' => 'Banani Lead',
            'email' => 'banani@branch.example',
            'phone' => '01711112222',
            'role' => 'branch',
            'branch_name' => 'Banani Campus',
            'branch_code' => 'BNN01',
            'branch_city' => 'Dhaka',
            'branch_address' => 'Road 11, Banani',
            'password' => 'BranchPass123!',
        ])->assertRedirect(route('login', ['role' => 'branch']));

        $branchUser = User::where('email', 'banani@branch.example')->firstOrFail();
        $this->assertSame('pending', $branchUser->status);
        $this->assertNotNull($branchUser->branch_id);
        $this->assertSame('inactive', Branch::findOrFail($branchUser->branch_id)->status);

        // Login blocked while pending
        $this->post(route('login.post'), [
            'email' => 'banani@branch.example',
            'password' => 'BranchPass123!',
            'expected_role' => 'branch',
        ])->assertSessionHasErrors('email');

        // Teacher self-registers
        $this->post(route('register.post'), [
            'name' => 'Sadia Rahman',
            'email' => 'sadia@teacher.example',
            'phone' => '01722223333',
            'role' => 'teacher',
            'subject_specialty' => 'IELTS Writing',
            'education' => 'M.A. in TESOL',
            'password' => 'TeachPass123!',
        ])->assertRedirect(route('login', ['role' => 'teacher']));

        $teacherUser = User::where('email', 'sadia@teacher.example')->firstOrFail();
        $this->assertSame('pending', $teacherUser->status);

        // Main Admin approves Branch (activates Branch campus too) and modifies Teacher
        $this->actingAs($admin)->post(route('portal.users.approve', $branchUser))->assertRedirect();
        $this->assertSame('active', $branchUser->fresh()->status);
        $this->assertSame('active', Branch::findOrFail($branchUser->branch_id)->status);

        $this->actingAs($admin)->patch(route('portal.users.update', $teacherUser), [
            'name' => 'Sadia Rahman Verified',
            'email' => 'sadia@teacher.example',
            'phone' => '01722223333',
            'role' => 'teacher',
            'status' => 'active',
            'subject_specialty' => 'IELTS Band 8',
            'education' => 'M.A. TESOL, CELTA',
        ])->assertRedirect();
        $this->assertSame('active', $teacherUser->fresh()->status);
        $this->assertSame('Sadia Rahman Verified', $teacherUser->fresh()->name);

        // Admin issues recovery token and Teacher resets password
        $tokenResp = $this->actingAs($admin)->post(route('portal.users.reset_token', $teacherUser));
        $issuedToken = (string) session('issued_reset_token');
        $this->assertNotEmpty($issuedToken);

        $this->post('/logout');
        $this->post(route('recover.confirm'), [
            'email' => 'sadia@teacher.example',
            'token' => $issuedToken,
            'password' => 'NewTeachPass99!',
            'expected_role' => 'teacher',
        ])->assertRedirect(route('login', ['role' => 'teacher']));

        $this->post(route('login.post'), [
            'email' => 'sadia@teacher.example',
            'password' => 'NewTeachPass99!',
            'expected_role' => 'teacher',
        ])->assertRedirect(route('portal.index'));
    }


    public function test_payment_webhook_fails_closed_when_dedicated_secret_is_missing(): void
    {
        config(['services.payment_webhook_secret' => '']);
        $payload = json_encode([
            'enrollment_id' => 1,
            'trx_id' => 'TRX-NO-SECRET',
            'amount' => 100,
            'status' => 'SUCCESS',
        ]);

        $this->call(
            'POST',
            '/payments/webhook/bkash',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => 'anything', 'CONTENT_TYPE' => 'application/json'],
            (string) $payload
        )->assertStatus(503)->assertJson(['error' => 'Payment webhook secret is not configured.']);
    }

    public function test_paid_enrollment_rejects_invalid_webhook_and_requires_signed_settlement(): void
    {
        $branch = Branch::create([
            'name' => 'Uttara Campus',
            'code' => 'UTR',
            'city' => 'Dhaka',
            'address' => 'Road 7, Sector 4',
            'status' => 'active',
        ]);
        $student = User::create([
            'name' => 'Tariq Hasan',
            'email' => 'tariq@student.example',
            'phone' => '01755551111',
            'role' => 'student',
            'branch_id' => $branch->id,
            'roll_number' => 'AEC-2026-4402',
            'password' => Hash::make('StudentPass123!'),
        ]);
        $course = Course::create([
            'slug' => 'ielts-intensive',
            'title' => 'IELTS Intensive',
            'subtitle' => 'Band 8.0 Cohort',
            'category' => 'IELTS',
            'level_tag' => 'Advanced',
            'price' => 4500,
            'original_price' => 6000,
            'description' => 'Full IELTS preparation course.',
            'status' => 'active',
        ]);

        $inactiveBranch = Branch::create([
            'name' => 'Closed Campus',
            'code' => 'CLS',
            'city' => 'Sylhet',
            'address' => '99 Old Rd',
            'status' => 'inactive',
        ]);
        Coupon::create([
            'code' => 'SHOPONLY50',
            'discount_percent' => 50,
            'applies_to' => 'products',
            'description' => 'Store products only',
            'active' => true,
        ]);

        // Inactive branch must be rejected on course enrollment
        $this->actingAs($student)->post(route('courses.enroll', $course), [
            'branch_id' => $inactiveBranch->id,
            'payment_method' => 'Online Card / Gateway',
        ])->assertSessionHasErrors('branch_id');

        // Product-only coupon must NOT discount course enrollment price
        $this->actingAs($student)->post(route('courses.enroll', $course), [
            'branch_id' => $branch->id,
            'payment_method' => 'Online Card / Gateway',
            'coupon_code' => 'SHOPONLY50',
        ])->assertRedirect();

        $enrollment = Enrollment::where('user_id', $student->id)->firstOrFail();
        $this->assertSame('pending', $enrollment->status);
        $this->assertSame(4500, $enrollment->amount_paid);
        $this->assertNull($enrollment->coupon_used);

        $badPayload = json_encode([
            'enrollment_id' => $enrollment->id,
            'trx_id' => 'TRX-FAKE-1',
            'amount' => 4500,
            'status' => 'SUCCESS',
        ]);

        // Bad signature returns 401
        $this->call(
            'POST',
            '/payments/webhook/bkash',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => 'forged-signature', 'CONTENT_TYPE' => 'application/json'],
            (string) $badPayload
        )->assertStatus(401);
        $this->assertSame('pending', $enrollment->fresh()->status);

        // Valid signature but mismatched amount returns 400
        $secret = (string) config('services.payment_webhook_secret');
        $mismatchPayload = json_encode([
            'enrollment_id' => $enrollment->id,
            'trx_id' => 'TRX-UNDERPAY-1',
            'amount' => 100,
            'status' => 'SUCCESS',
        ]);
        $mismatchSig = hash_hmac('sha256', (string) $mismatchPayload, $secret);
        $this->call(
            'POST',
            '/payments/webhook/bkash',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $mismatchSig, 'CONTENT_TYPE' => 'application/json'],
            (string) $mismatchPayload
        )->assertStatus(400);

        // Valid signature & matching amount approves enrollment and deduplicates replays
        $goodPayload = json_encode([
            'enrollment_id' => $enrollment->id,
            'trx_id' => 'TRX-BKASH-7788',
            'amount' => 4500,
            'status' => 'SUCCESS',
        ]);
        $goodSig = hash_hmac('sha256', (string) $goodPayload, $secret);
        $this->call(
            'POST',
            '/payments/webhook/bkash',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $goodSig, 'CONTENT_TYPE' => 'application/json'],
            (string) $goodPayload
        )->assertOk()->assertJson(['ok' => true, 'status' => 'approved']);

        $this->assertSame('approved', $enrollment->fresh()->status);
        $this->assertSame(1, $course->fresh()->enrolled_count);

        // Replay is deduplicated without double counting
        $this->call(
            'POST',
            '/payments/webhook/bkash',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $goodSig, 'CONTENT_TYPE' => 'application/json'],
            (string) $goodPayload
        )->assertOk()->assertJson(['ok' => true, 'deduplicated' => true]);
        $this->assertSame(1, $course->fresh()->enrolled_count);

        // Paid e-book cannot be claimed without trx_id
        $paidEbook = Ebook::create([
            'title' => 'Premium IELTS Essays',
            'level' => 'IELTS',
            'author' => 'Faculty',
            'pages_count' => 110,
            'is_free' => false,
            'price' => 450,
            'original_price' => 600,
            'summary' => 'Paid guide.',
        ]);
        $this->actingAs($student)->post(route('ebooks.claim', $paidEbook))
            ->assertSessionHasErrors('ebook');
    }
}
