<?php

namespace App\Http\Controllers;

use App\Models\AcademicClass;
use App\Models\AcademicSubject;
use App\Models\AcademicYear;
use App\Models\ContactMessage;
use App\Models\CourseRoutine;
use App\Models\CustomPage;
use App\Models\FeeInvoice;
use App\Models\FinancialRecord;
use App\Models\StudentAdmission;
use App\Support\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EnterpriseOpsController extends Controller
{
    public function storeAcademicYear(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:80|unique:academic_years,name',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'is_current' => 'nullable|boolean',
        ]);

        if ($request->boolean('is_current')) {
            AcademicYear::query()->update(['is_current' => false]);
        }

        AcademicYear::create([
            'name' => trim($data['name']),
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'is_current' => $request->boolean('is_current'),
            'status' => 'active',
        ]);

        return back()->with('status', "Academic year '{$data['name']}' created.");
    }

    public function deleteAcademicYear(AcademicYear $academicYear)
    {
        $academicYear->delete();
        return back()->with('status', 'Academic year deleted.');
    }

    public function storeAcademicClass(Request $request)
    {
        $data = $request->validate([
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'name' => 'required|string|max:120',
            'code' => 'required|string|max:40|unique:academic_classes,code',
            'level' => 'required|string|max:60',
        ]);
        $data['code'] = strtoupper(trim($data['code']));
        AcademicClass::create($data);
        return back()->with('status', "Academic class '{$data['name']}' added.");
    }

    public function deleteAcademicClass(AcademicClass $academicClass)
    {
        $academicClass->delete();
        return back()->with('status', 'Academic class deleted.');
    }

    public function storeAcademicSubject(Request $request)
    {
        $data = $request->validate([
            'academic_class_id' => 'required|exists:academic_classes,id',
            'name' => 'required|string|max:120',
            'code' => 'required|string|max:40',
            'teacher_id' => 'nullable|exists:users,id',
        ]);
        $data['code'] = strtoupper(trim($data['code']));
        AcademicSubject::create($data);
        return back()->with('status', "Subject '{$data['name']}' assigned.");
    }

    public function deleteAcademicSubject(AcademicSubject $academicSubject)
    {
        $academicSubject->delete();
        return back()->with('status', 'Subject removed.');
    }

    public function storeRoutine(Request $request)
    {
        $data = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'batch_id' => 'nullable|exists:batches,id',
            'day_of_week' => 'required|string|max:30',
            'start_time' => 'required|string|max:20',
            'end_time' => 'required|string|max:20',
            'room_or_link' => 'nullable|string|max:240',
            'instructor_name' => 'nullable|string|max:120',
        ]);
        CourseRoutine::create($data);
        return back()->with('status', 'Class routine entry added.');
    }

    public function deleteRoutine(CourseRoutine $routine)
    {
        $routine->delete();
        return back()->with('status', 'Routine entry deleted.');
    }

    public function storeAdmission(Request $request)
    {
        $actor = $request->user();
        $data = $request->validate([
            'student_id' => 'required|exists:users,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'academic_class_id' => 'nullable|exists:academic_classes,id',
            'branch_id' => 'nullable|exists:branches,id',
            'admission_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($actor->role === 'branch' && $actor->branch_id) {
            $data['branch_id'] = $actor->branch_id;
        }

        $admissionNo = 'ADM-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $admission = StudentAdmission::create([
            ...$data,
            'admission_no' => $admissionNo,
            'status' => 'active',
        ]);
        AuditRecorder::log($request, 'admission.created', 'student_admission', (string) $admission->id);

        return back()->with('status', "Student admission {$admissionNo} recorded.");
    }

    public function updateAdmissionStatus(Request $request, StudentAdmission $admission)
    {
        $data = $request->validate(['status' => 'required|in:active,pending,transferred,graduated']);
        $admission->update(['status' => $data['status']]);
        return back()->with('status', "Admission {$admission->admission_no} updated to {$data['status']}.");
    }

    public function deleteAdmission(StudentAdmission $admission)
    {
        $admission->delete();
        return back()->with('status', 'Admission record removed.');
    }

    public function storeFeeInvoice(Request $request)
    {
        $actor = $request->user();
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'course_id' => 'nullable|exists:courses,id',
            'branch_id' => 'nullable|exists:branches,id',
            'title' => 'required|string|max:160',
            'installment_label' => 'required|string|max:80',
            'amount_due' => 'required|integer|min:1',
            'due_date' => 'nullable|date',
        ]);

        if ($actor->role === 'branch' && $actor->branch_id) {
            $data['branch_id'] = $actor->branch_id;
        }

        $invoiceNo = 'FEE-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $invoice = FeeInvoice::create([
            ...$data,
            'invoice_no' => $invoiceNo,
            'amount_paid' => 0,
            'status' => 'unpaid',
            'recorded_by' => $actor->name,
        ]);

        AuditRecorder::notify(
            (int) $data['user_id'],
            'New Fee Invoice: ' . $invoiceNo,
            "{$data['title']} ({$data['installment_label']}) issued.",
            route('portal.index'),
            'finance'
        );

        return back()->with('status', "Fee invoice {$invoiceNo} created.");
    }

    public function recordFeePayment(Request $request, FeeInvoice $invoice)
    {
        $actor = $request->user();
        if ($actor->role === 'branch' && $actor->branch_id && (int) $invoice->branch_id !== (int) $actor->branch_id) {
            abort(403, 'Branch accounts can only collect fees for their own branch.');
        }

        $data = $request->validate([
            'payment_amount' => 'required|integer|min:1',
            'payment_method' => 'required|string|max:80',
            'trx_id' => 'nullable|string|max:80',
        ]);

        $newPaid = min($invoice->amount_due, $invoice->amount_paid + (int) $data['payment_amount']);
        $nextStatus = $newPaid >= $invoice->amount_due ? 'paid' : 'partial';

        $invoice->update([
            'amount_paid' => $newPaid,
            'status' => $nextStatus,
            'payment_method' => $data['payment_method'],
            'trx_id' => $data['trx_id'] ?? $invoice->trx_id,
            'paid_at' => now(),
            'recorded_by' => $actor->name,
        ]);

        FinancialRecord::create([
            'type' => 'income',
            'category' => 'Student Fee Invoice',
            'title' => "{$invoice->invoice_no} — {$invoice->title}",
            'amount' => (int) $data['payment_amount'],
            'branch_id' => $invoice->branch_id,
            'entry_date' => now()->toDateString(),
            'recorded_by' => $actor->name,
        ]);

        AuditRecorder::log($request, 'fee_invoice.payment', 'fee_invoice', (string) $invoice->id, [
            'amount' => $data['payment_amount'],
            'status' => $nextStatus,
        ]);

        return back()->with('status', "Payment recorded on {$invoice->invoice_no} ({$nextStatus}).");
    }

    public function deleteFeeInvoice(FeeInvoice $invoice)
    {
        $invoice->delete();
        return back()->with('status', 'Fee invoice deleted.');
    }

    public function storeCustomPage(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:160',
            'slug' => 'nullable|string|max:120',
            'subtitle' => 'nullable|string|max:240',
            'content' => 'required|string|max:20000',
            'published' => 'nullable|boolean',
            'show_in_footer' => 'nullable|boolean',
        ]);

        $slug = Str::slug($data['slug'] ?: $data['title']);
        if ($slug === '') {
            $slug = 'page-' . time();
        }

        CustomPage::updateOrCreate(
            ['slug' => $slug],
            [
                'title' => $data['title'],
                'subtitle' => $data['subtitle'] ?? null,
                'content' => $data['content'],
                'published' => $request->boolean('published', true),
                'show_in_footer' => $request->boolean('show_in_footer', true),
            ]
        );

        return back()->with('status', "Custom CMS page '/page/{$slug}' saved.");
    }

    public function toggleCustomPage(CustomPage $page)
    {
        $page->update(['published' => !$page->published]);
        return back()->with('status', "Page '{$page->title}' visibility updated.");
    }

    public function deleteCustomPage(CustomPage $page)
    {
        $page->delete();
        return back()->with('status', 'Custom CMS page deleted.');
    }

    public function updateContactStatus(Request $request, ContactMessage $contact)
    {
        $data = $request->validate([
            'status' => 'required|in:new,replied,archived',
            'admin_note' => 'nullable|string|max:1000',
        ]);
        $contact->update($data);
        return back()->with('status', 'Inquiry updated.');
    }

    public function deleteContactMessage(ContactMessage $contact)
    {
        $contact->delete();
        return back()->with('status', 'Inquiry removed.');
    }
}
