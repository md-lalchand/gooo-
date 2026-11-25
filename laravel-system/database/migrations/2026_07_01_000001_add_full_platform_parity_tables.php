<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('avatar_path')->nullable()->after('bio');
            });
        }

        if (!Schema::hasTable('course_modules')) {
            Schema::create('course_modules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->unsignedInteger('order_index')->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('course_chapters')) {
            Schema::create('course_chapters', function (Blueprint $table) {
                $table->id();
                $table->foreignId('module_id')->constrained('course_modules')->cascadeOnDelete();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->string('title');
                $table->text('summary')->nullable();
                $table->unsignedInteger('order_index')->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('course_assignments')) {
            Schema::create('course_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
                $table->string('title');
                $table->text('instructions');
                $table->date('due_date')->nullable();
                $table->unsignedInteger('max_marks')->default(100);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('assignment_submissions')) {
            Schema::create('assignment_submissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('assignment_id')->constrained('course_assignments')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->text('submission_text');
                $table->string('attachment_url')->nullable();
                $table->unsignedInteger('marks_awarded')->nullable();
                $table->text('feedback')->nullable();
                $table->enum('status', ['submitted', 'graded', 'returned'])->default('submitted');
                $table->string('graded_by')->nullable();
                $table->timestamp('graded_at')->nullable();
                $table->timestamps();
                $table->unique(['assignment_id', 'user_id']);
            });
        }

        if (!Schema::hasTable('course_announcements')) {
            Schema::create('course_announcements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title');
                $table->text('body');
                $table->boolean('pinned')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('academic_years')) {
            Schema::create('academic_years', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->boolean('is_current')->default(false);
                $table->enum('status', ['active', 'archived'])->default('active');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('academic_classes')) {
            Schema::create('academic_classes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                $table->string('name');
                $table->string('code')->unique();
                $table->string('level')->default('General');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('academic_subjects')) {
            Schema::create('academic_subjects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('academic_class_id')->constrained('academic_classes')->cascadeOnDelete();
                $table->string('name');
                $table->string('code');
                $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('course_routines')) {
            Schema::create('course_routines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('batches')->nullOnDelete();
                $table->string('day_of_week');
                $table->string('start_time');
                $table->string('end_time');
                $table->string('room_or_link')->nullable();
                $table->string('instructor_name')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('student_admissions')) {
            Schema::create('student_admissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
                $table->foreignId('academic_class_id')->nullable()->constrained('academic_classes')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('admission_no')->unique();
                $table->date('admission_date');
                $table->enum('status', ['active', 'pending', 'transferred', 'graduated'])->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('fee_invoices')) {
            Schema::create('fee_invoices', function (Blueprint $table) {
                $table->id();
                $table->string('invoice_no')->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('title');
                $table->string('installment_label')->default('Full Fee');
                $table->unsignedInteger('amount_due');
                $table->unsignedInteger('amount_paid')->default(0);
                $table->date('due_date')->nullable();
                $table->enum('status', ['unpaid', 'partial', 'paid', 'waived'])->default('unpaid');
                $table->string('payment_method')->nullable();
                $table->string('trx_id')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('recorded_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('contact_messages')) {
            Schema::create('contact_messages', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->string('phone')->nullable();
                $table->string('subject');
                $table->text('message');
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->enum('status', ['new', 'replied', 'archived'])->default('new');
                $table->text('admin_note')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('custom_pages')) {
            Schema::create('custom_pages', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('title');
                $table->string('subtitle')->nullable();
                $table->longText('content');
                $table->boolean('published')->default(true);
                $table->boolean('show_in_footer')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('in_app_notifications')) {
            Schema::create('in_app_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('title');
                $table->text('body');
                $table->string('link_url')->nullable();
                $table->string('category')->default('system');
                $table->boolean('is_read')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name');
                $table->string('actor_role')->default('system');
                $table->string('action');
                $table->string('target_type')->nullable();
                $table->string('target_id')->nullable();
                $table->text('details_json')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('claimed_ebooks')) {
            Schema::create('claimed_ebooks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('ebook_id')->constrained('ebooks')->cascadeOnDelete();
                $table->string('trx_id')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'ebook_id']);
            });
        }

        if (!Schema::hasTable('scorm_attempts')) {
            Schema::create('scorm_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('scorm_package_id')->constrained('scorm_packages')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('lesson_status')->default('completed');
                $table->unsignedInteger('score')->default(100);
                $table->unsignedInteger('xp_awarded')->default(0);
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scorm_attempts');
        Schema::dropIfExists('claimed_ebooks');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('in_app_notifications');
        Schema::dropIfExists('custom_pages');
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('fee_invoices');
        Schema::dropIfExists('student_admissions');
        Schema::dropIfExists('course_routines');
        Schema::dropIfExists('academic_subjects');
        Schema::dropIfExists('academic_classes');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('course_announcements');
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('course_assignments');
        Schema::dropIfExists('course_chapters');
        Schema::dropIfExists('course_modules');

        if (Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('avatar_path');
            });
        }
    }
};
