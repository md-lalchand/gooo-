<?php

namespace App\Http\Controllers;

use App\Models\AssignmentSubmission;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CenterResult;
use App\Models\Certificate;
use App\Models\CommunityPost;
use App\Models\ContactMessage;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\CustomPage;
use App\Models\Enrollment;
use App\Models\FinancialRecord;
use App\Models\Lesson;
use App\Models\Review;
use App\Models\ScormAttempt;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicController extends Controller
{
    public function home()
    {
        $courses = Course::where('status', 'active')->latest()->take(6)->get();
        $reviews = Review::where('status', 'approved')->latest()->get();

        $slidesRaw = DB::table('settings')->where('key', 'homepage_slides')->value('value');
        $configRaw = DB::table('settings')->where('key', 'homepage_config')->value('value');
        $heroSlides = $slidesRaw ? (json_decode($slidesRaw, true) ?? []) : [];
        $homepageConfig = $configRaw ? (json_decode($configRaw, true) ?? []) : [];

        $liveStats = [
            'students' => User::where('role', 'student')->count() + Enrollment::where('status', 'approved')->count(),
            'courses' => Course::where('status', 'active')->count(),
            'teachers' => User::where('role', 'teacher')->count(),
            'videos' => Lesson::count(),
        ];

        $stats = ($homepageConfig['stats_mode'] ?? 'auto') === 'custom'
            ? [
                'students' => (int) ($homepageConfig['custom_students'] ?? $liveStats['students']),
                'courses' => (int) ($homepageConfig['custom_courses'] ?? $liveStats['courses']),
                'teachers' => (int) ($homepageConfig['custom_teachers'] ?? $liveStats['teachers']),
                'videos' => (int) ($homepageConfig['custom_videos'] ?? $liveStats['videos']),
            ]
            : $liveStats;

        return view('home', compact('courses', 'stats', 'reviews', 'heroSlides', 'homepageConfig'));
    }

    public function about()
    {
        $branches = Branch::where('status', 'active')->get();
        $stats = [
            'courses' => Course::where('status', 'active')->count(),
            'teachers' => User::where('role', 'teacher')->count(),
            'students' => User::where('role', 'student')->count(),
            'branches' => $branches->count(),
        ];
        return view('static.about', compact('branches', 'stats'));
    }

    public function contact()
    {
        $branches = Branch::where('status', 'active')->get();
        return view('static.contact', compact('branches'));
    }

    public function submitContact(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:140',
            'phone' => 'nullable|string|max:40',
            'subject' => 'required|string|max:160',
            'message' => 'required|string|max:3000',
            'branch_id' => 'nullable|exists:branches,id',
        ]);

        $msg = ContactMessage::create($data);
        $admin = User::where('role', 'admin')->first();
        if ($admin) {
            AuditRecorder::notify($admin->id, 'New Inquiry: ' . $data['subject'], "From {$data['name']} ({$data['email']})", route('portal.index'), 'inquiry');
        }

        return back()->with('status', "Thank you, {$msg->name}! Your inquiry has been received.");
    }

    public function policy(string $type)
    {
        $allowed = ['terms', 'privacy', 'refund'];
        if (!in_array($type, $allowed, true)) {
            abort(404);
        }
        return view('static.policy', compact('type'));
    }

    public function customPage(string $slug)
    {
        $page = CustomPage::where('slug', $slug)->where('published', true)->firstOrFail();
        return view('static.custom-page', compact('page'));
    }

    public function courses(Request $request)
    {
        $category = $request->query('cat', 'All Courses');
        $search = trim((string) $request->query('q', ''));
        $sort = (string) $request->query('sort', 'featured');
        $showDrafts = $request->user()?->role === 'admin' && $request->boolean('drafts', true);

        $query = Course::query();
        if (!$showDrafts) {
            $query->where('status', 'active');
        }
        if ($category === 'Combos') {
            $query->where('is_combo', true);
        } elseif ($category !== 'All Courses') {
            $query->where(function ($sub) use ($category) {
                $sub->where('category', $category)
                    ->orWhere('level_tag', $category);
            });
        }
        if ($search !== '') {
            $query->where(function ($sub) use ($search) {
                $sub->where('title', 'like', "%{$search}%")
                    ->orWhere('subtitle', 'like', "%{$search}%")
                    ->orWhere('level_tag', 'like', "%{$search}%");
            });
        }

        if ($sort === 'price_asc') {
            $query->orderBy('price', 'asc');
        } elseif ($sort === 'price_desc') {
            $query->orderBy('price', 'desc');
        } elseif ($sort === 'popular') {
            $query->orderBy('enrolled_count', 'desc');
        } else {
            $query->latest();
        }

        $courses = $query->get();
        $combos = $courses->where('is_combo', true)->values();
        $regularCourses = $courses->where('is_combo', false)->values();

        $dbCats = Category::where('type', 'course')->where('active', true)->pluck('name')->all();
        $courseCategories = array_values(array_unique(array_merge(
            ['All Courses', 'Combos'],
            $dbCats
        )));

        return view('courses.index', compact('courses', 'combos', 'regularCourses', 'category', 'search', 'sort', 'courseCategories'));
    }

    public function courseDetail(string $slug)
    {
        $course = Course::where('slug', $slug)
            ->where('status', 'active')
            ->with(['lessons.batch', 'batches.branch', 'modules.chapters', 'routines'])
            ->firstOrFail();
        $branches = Branch::where('status', 'active')->get();
        $gatewaysRaw = DB::table('settings')->where('key', 'payment_gateways')->value('value');
        $allGateways = $gatewaysRaw ? (json_decode($gatewaysRaw, true) ?? []) : PortalController::defaultPaymentGateways();
        $activeGateways = array_values(array_filter($allGateways, fn ($g) => !empty($g['active'])));
        $reviews = Review::where('status', 'approved')->latest()->take(4)->get();
        $instructors = User::where('role', 'teacher')->where('status', 'active')->with('branch')->take(4)->get();
        return view('courses.show', compact('course', 'branches', 'activeGateways', 'reviews', 'instructors'));
    }

    public function enroll(Request $request, Course $course)
    {
        $hasBranches = Branch::where('status', 'active')->exists();

        $data = $request->validate([
            'branch_id' => $hasBranches ? 'required|exists:branches,id' : 'nullable|exists:branches,id',
            'payment_method' => 'required|string|max:80',
            'coupon_code' => 'nullable|string|max:40',
            'trx_id' => 'nullable|string|max:80',
        ]);

        if (!empty($data['branch_id'])) {
            $activeBranch = Branch::where('id', $data['branch_id'])->where('status', 'active')->exists();
            if (!$activeBranch) {
                return back()->withErrors(['branch_id' => 'Selected branch is currently inactive. Choose an active campus.']);
            }
        }

        $gatewaysRaw = DB::table('settings')->where('key', 'payment_gateways')->value('value');
        $allGateways = $gatewaysRaw ? (json_decode($gatewaysRaw, true) ?? []) : PortalController::defaultPaymentGateways();
        $activeGateways = array_values(array_filter($allGateways, fn ($g) => !empty($g['active'])));
        $selectedGateway = collect($activeGateways)->first(fn ($g) => strcasecmp($g['name'], $data['payment_method']) === 0);
        if (!$selectedGateway) {
            return back()->withErrors(['payment_method' => 'Selected payment gateway is currently inactive or unavailable.']);
        }

        $finalPrice = $course->price;
        $couponCode = null;
        if (!empty($data['coupon_code'])) {
            $coupon = Coupon::where('code', strtoupper(trim($data['coupon_code'])))
                ->where('active', true)
                ->whereIn('applies_to', ['all', 'courses'])
                ->first();
            if ($coupon) {
                $finalPrice = max(0, (int) round($course->price * (1 - $coupon->discount_percent / 100)));
                $couponCode = $coupon->code;
            }
        }

        // Only zero-cost enrollments approve immediately; paid enrollments stay pending until verified gateway webhook or admin/branch approval.
        $status = $finalPrice === 0 ? 'approved' : 'pending';
        $invoiceNo = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $enrollment = Enrollment::create([
            'user_id' => $request->user()->id,
            'course_id' => $course->id,
            'branch_id' => $data['branch_id'] ?? null,
            'payment_method' => $selectedGateway['name'],
            'trx_id' => !empty($data['trx_id']) ? strtoupper(trim((string) $data['trx_id'])) : null,
            'invoice_no' => $invoiceNo,
            'amount_paid' => $finalPrice,
            'coupon_used' => $couponCode,
            'status' => $status,
            'completed_lesson_ids' => [],
        ]);

        if ($status === 'approved') {
            $course->increment('enrolled_count');
        }

        return back()->with(
            'status',
            $status === 'approved'
                ? 'Enrolled successfully!'
                : "Enrollment #{$enrollment->id} submitted ({$invoiceNo}). Awaiting payment gateway webhook or branch verification."
        );
    }

    public function paymentWebhook(Request $request, string $gateway)
    {
        $rawBody = $request->getContent();
        $providedSig = (string) ($request->header('X-Webhook-Signature') ?? $request->input('signature', ''));
        $secret = trim((string) config('services.payment_webhook_secret'));
        if ($secret === '') {
            return response()->json(['error' => 'Payment webhook secret is not configured.'], 503);
        }

        $signedPayload = $rawBody !== '' ? $rawBody : (string) json_encode($request->only(['enrollment_id', 'trx_id', 'amount', 'status']));
        $expectedSig = hash_hmac('sha256', $signedPayload, $secret);

        if ($providedSig === '' || !hash_equals($expectedSig, trim($providedSig))) {
            return response()->json(['error' => 'Invalid payment webhook signature.'], 401);
        }

        $data = $request->validate([
            'enrollment_id' => 'required|integer|exists:enrollments,id',
            'trx_id' => 'required|string|max:80',
            'amount' => 'required|integer|min:0',
            'status' => 'required|string|in:SUCCESS,VALID,COMPLETED',
        ]);

        $enrollment = Enrollment::findOrFail($data['enrollment_id']);
        if ((int) $data['amount'] !== (int) $enrollment->amount_paid) {
            return response()->json(['error' => 'Webhook amount mismatch.'], 400);
        }

        $cleanTrx = strtoupper(trim($data['trx_id']));
        $settlement = DB::transaction(function () use ($enrollment, $cleanTrx, $gateway) {
            if (Enrollment::where('trx_id', $cleanTrx)
                ->where('id', '!=', $enrollment->id)
                ->exists()
            ) {
                return 'duplicate_trx';
            }

            // The conditional status update is the atomic claim on this enrollment.
            // Concurrent callbacks can only move pending -> approved once.
            $updated = Enrollment::whereKey($enrollment->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'approved',
                    'trx_id' => $cleanTrx,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                $current = Enrollment::findOrFail($enrollment->id);
                return $current->status === 'approved' ? 'deduplicated' : 'not_pending';
            }


            $settled = Enrollment::with(['course', 'user'])->findOrFail($enrollment->id);
            if ($settled->course) {
                $settled->course->increment('enrolled_count');
            }
            FinancialRecord::create([
                'type' => 'income',
                'category' => 'Verified Gateway Payment',
                'title' => "{$gateway} ({$settled->invoice_no}) — {$settled->user?->name}",
                'amount' => $settled->amount_paid,
                'branch_id' => $settled->branch_id,
                'entry_date' => now()->toDateString(),
                'recorded_by' => "{$gateway} Webhook",
            ]);

            return 'approved';
        });

        if ($settlement === 'deduplicated') {
            return response()->json(['ok' => true, 'deduplicated' => true, 'enrollment_id' => $enrollment->id]);
        }
        if ($settlement === 'not_pending') {
            return response()->json(['error' => 'Enrollment is not awaiting payment.'], 409);
        }
        if ($settlement === 'duplicate_trx') {
            return response()->json(['error' => 'Duplicate Transaction ID rejected.'], 409);
        }

        return response()->json(['ok' => true, 'enrollment_id' => $enrollment->id, 'status' => 'approved']);
    }

    public function instructors()
    {
        $teachers = User::where('role', 'teacher')->with('branch')->get();
        return view('instructors', compact('teachers'));
    }

    public function learn(Request $request)
    {
        $user = $request->user();
        $enrollments = Enrollment::where('user_id', $user->id)
            ->where('status', 'approved')
            ->with([
                'course.lessons.batch',
                'course.modules.chapters',
                'course.announcements.author',
                'course.routines',
                'batch',
            ])
            ->get();

        foreach ($enrollments as $enrollment) {
            $course = $enrollment->course;
            $batchId = $enrollment->batch_id;
            $lessonsQuery = $course->lessons()->with('batch');
            $assignmentsQuery = $course->assignments();
            if ($batchId) {
                $lessonsQuery->where(fn ($query) => $query->whereNull('batch_id')->orWhere('batch_id', $batchId));
                $assignmentsQuery->where(fn ($query) => $query->whereNull('batch_id')->orWhere('batch_id', $batchId));
            } else {
                $lessonsQuery->whereNull('batch_id');
                $assignmentsQuery->whereNull('batch_id');
            }

            $accessibleLessons = $lessonsQuery->get();
            $validLessonIds = $accessibleLessons->pluck('id')->map(fn ($id) => (int) $id)->all();
            $completedIds = array_map('intval', $enrollment->completed_lesson_ids ?? []);
            $enrollment->setAttribute('accessible_lessons', $accessibleLessons);
            $enrollment->setAttribute('accessible_assignments', $assignmentsQuery->get());
            $enrollment->setAttribute('accessible_batches', $enrollment->batch ? collect([$enrollment->batch]) : collect());
            $enrollment->setAttribute('accessible_scorm_packages', $course->scormPackages()->get());
            $enrollment->setAttribute('accessible_completed_lesson_ids', array_values(array_intersect($completedIds, $validLessonIds)));
        }

        $submissions = AssignmentSubmission::where('user_id', $user->id)
            ->get()
            ->keyBy('assignment_id');
        $completedScormIds = ScormAttempt::where('user_id', $user->id)
            ->where('lesson_status', 'completed')
            ->pluck('scorm_package_id')
            ->all();
        $earnedCertificates = Certificate::where('user_id', $user->id)
            ->where('revoked', false)
            ->get()
            ->keyBy('course_id');

        return view('learn', compact('enrollments', 'submissions', 'completedScormIds', 'earnedCertificates'));
    }

    public function markLessonComplete(Request $request)
    {
        $data = $request->validate(['course_id' => 'required|integer', 'lesson_id' => 'required|integer']);
        $enrollment = Enrollment::where('user_id', $request->user()->id)
            ->where('course_id', $data['course_id'])
            ->where('status', 'approved')
            ->firstOrFail();

        Lesson::whereKey($data['lesson_id'])
            ->where('course_id', $enrollment->course_id)
            ->where(fn ($query) => $enrollment->batch_id
                ? $query->whereNull('batch_id')->orWhere('batch_id', $enrollment->batch_id)
                : $query->whereNull('batch_id'))
            ->firstOrFail();

        $ids = $enrollment->completed_lesson_ids ?? [];
        if (!in_array((int) $data['lesson_id'], $ids, true)) {
            $ids[] = (int) $data['lesson_id'];
            $enrollment->update(['completed_lesson_ids' => $ids]);
        }
        return back()->with('status', 'Lesson marked complete!');
    }

    public function results()
    {
        return view('results', ['record' => null, 'roll' => '']);
    }

    public function lookupResult(Request $request)
    {
        $roll = strtoupper(trim((string) $request->input('roll', '')));
        $record = $roll !== '' ? CenterResult::where('roll_number', $roll)->first() : null;
        return view('results', compact('record', 'roll'));
    }

    public function community()
    {
        $posts = CommunityPost::where('status', 'published')->with('author')->latest()->get();
        return view('community', compact('posts'));
    }

    public function submitCommunityPost(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:180',
            'category' => 'required|string|max:80',
            'content' => 'required|string|max:4000',
        ]);

        CommunityPost::create([
            'title' => $data['title'],
            'category' => $data['category'],
            'content' => $data['content'],
            'author_id' => $request->user()->id,
            'likes' => 0,
            'status' => 'published',
        ]);

        return back()->with('status', 'Discussion published to the community.');
    }

    public function likeCommunityPost(CommunityPost $post)
    {
        $post->increment('likes');
        return back()->with('status', 'Thank you for engaging with the community!');
    }

    public function verifyCertificate(Request $request)
    {
        $code = strtoupper(trim((string) $request->query('code', '')));
        $certificate = $code !== '' ? Certificate::where('certificate_code', $code)->first() : null;
        return view('certificates.verify', compact('certificate', 'code'));
    }

    public function lookupCertificate(Request $request)
    {
        $code = strtoupper(trim((string) $request->input('code', '')));
        $certificate = $code !== '' ? Certificate::where('certificate_code', $code)->first() : null;
        return view('certificates.verify', compact('certificate', 'code'));
    }
}
