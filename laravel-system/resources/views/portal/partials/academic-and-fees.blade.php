@php $currency = $brand['currency_symbol'] ?? '$'; @endphp

{{-- Academic Years, Classes, Subjects, Routines, Admissions & Fee Invoices --}}
<div id="academic-fees-studio" class="rounded-2xl bg-white border border-slate-200 p-6 space-y-6">
    <div>
        <h2 class="text-lg font-extrabold text-slate-900">Academic Structure, Admissions, Routines &amp; Fee Invoices</h2>
        <p class="text-xs text-slate-500">Manage academic years, classes, subjects, weekly class routines, student admissions, fee installments, attendance, and staff payroll.</p>
    </div>

    @if($user->role === 'admin')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 border-t border-slate-100 pt-4">
            {{-- Academic Years --}}
            <div class="space-y-2">
                <h3 class="text-xs font-extrabold uppercase text-slate-700">Academic Years ({{ ($academicYears ?? collect())->count() }})</h3>
                <form method="POST" action="{{ route('portal.academic_years.store') }}" class="space-y-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
                    @csrf
                    <input type="text" name="name" required placeholder="e.g. 2026-2027 Session" class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                    <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" name="is_current" value="1" checked> Current Active Session</label>
                    <button type="submit" class="w-full rounded-lg text-white font-bold py-1.5 text-xs" style="background-color: var(--brand-primary)">+ Add Year</button>
                </form>
                @foreach($academicYears ?? [] as $ay)
                    <div class="flex items-center justify-between border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs">
                        <span><strong>{{ $ay->name }}</strong> @if($ay->is_current) <span class="text-emerald-700 font-bold">(Current)</span> @endif</span>
                        <form method="POST" action="{{ route('portal.academic_years.delete', $ay) }}">@csrf @method('DELETE')<button type="submit" class="text-rose-600 font-bold">×</button></form>
                    </div>
                @endforeach
            </div>

            {{-- Academic Classes --}}
            <div class="space-y-2">
                <h3 class="text-xs font-extrabold uppercase text-slate-700">Classes / Programs ({{ ($academicClasses ?? collect())->count() }})</h3>
                <form method="POST" action="{{ route('portal.academic_classes.store') }}" class="space-y-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
                    @csrf
                    <input type="text" name="name" required placeholder="Class / Program Name" class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                    <div class="grid grid-cols-2 gap-1.5">
                        <input type="text" name="code" required placeholder="Code (CLS-01)" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs uppercase">
                        <input type="text" name="level" required value="Standard" placeholder="Level" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                    </div>
                    <button type="submit" class="w-full rounded-lg text-white font-bold py-1.5 text-xs" style="background-color: var(--brand-accent)">+ Add Class</button>
                </form>
                @foreach($academicClasses ?? [] as $ac)
                    <div class="flex items-center justify-between border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs">
                        <span><strong>{{ $ac->name }}</strong> ({{ $ac->code }})</span>
                        <form method="POST" action="{{ route('portal.academic_classes.delete', $ac) }}">@csrf @method('DELETE')<button type="submit" class="text-rose-600 font-bold">×</button></form>
                    </div>
                @endforeach
            </div>

            {{-- Weekly Class Routine --}}
            <div class="space-y-2">
                <h3 class="text-xs font-extrabold uppercase text-slate-700">Course Routines ({{ ($routines ?? collect())->count() }})</h3>
                <form method="POST" action="{{ route('portal.routines.store') }}" class="space-y-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
                    @csrf
                    <select name="course_id" required class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                        @foreach($courses as $c)
                            <option value="{{ $c->id }}">{{ $c->title }}</option>
                        @endforeach
                    </select>
                    <div class="grid grid-cols-3 gap-1.5">
                        <input type="text" name="day_of_week" required placeholder="Day (Mon)" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs">
                        <input type="text" name="start_time" required value="04:00 PM" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs">
                        <input type="text" name="end_time" required value="05:30 PM" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs">
                    </div>
                    <button type="submit" class="w-full rounded-lg text-white font-bold py-1.5 text-xs" style="background-color: var(--brand-primary)">+ Add Routine</button>
                </form>
                @foreach($routines ?? [] as $rt)
                    <div class="flex items-center justify-between border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs">
                        <span>{{ $rt->course?->title }} · {{ $rt->day_of_week }} {{ $rt->start_time }}</span>
                        <form method="POST" action="{{ route('portal.routines.delete', $rt) }}">@csrf @method('DELETE')<button type="submit" class="text-rose-600 font-bold">×</button></form>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Admissions & Fee Invoices (Admin + Branch) --}}
    <div class="border-t border-slate-100 pt-4 space-y-4">
        <h3 class="text-sm font-extrabold text-slate-900">Student Admissions &amp; Fee Invoices ({{ ($feeInvoices ?? collect())->count() }} Invoices)</h3>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <form method="POST" action="{{ route('portal.admissions.store') }}" class="grid grid-cols-1 sm:grid-cols-2 gap-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
                @csrf
                <p class="sm:col-span-2 text-xs font-extrabold text-slate-800">+ Record Student Admission</p>
                <select name="student_id" required class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                    @foreach(($users ?? collect())->where('role', 'student') as $stu)
                        <option value="{{ $stu->id }}">{{ $stu->name }} ({{ $stu->roll_number ?: $stu->email }})</option>
                    @endforeach
                </select>
                <input type="date" name="admission_date" required value="{{ date('Y-m-d') }}" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                <select name="academic_class_id" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                    <option value="">Select Class (optional)</option>
                    @foreach($academicClasses ?? [] as $ac)
                        <option value="{{ $ac->id }}">{{ $ac->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-lg text-white font-bold px-3 py-1.5 text-xs" style="background-color: var(--brand-primary)">Create Admission</button>
            </form>

            <form method="POST" action="{{ route('portal.fee_invoices.store') }}" class="grid grid-cols-1 sm:grid-cols-2 gap-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
                @csrf
                <p class="sm:col-span-2 text-xs font-extrabold text-slate-800">+ Issue Student Fee Invoice</p>
                <select name="user_id" required class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                    @foreach(($users ?? collect())->where('role', 'student') as $stu)
                        <option value="{{ $stu->id }}">{{ $stu->name }} ({{ $stu->roll_number ?: $stu->email }})</option>
                    @endforeach
                </select>
                <input type="text" name="title" required placeholder="Fee Title (e.g. Tuition Term 1)" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                <input type="text" name="installment_label" required value="Installment 1" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                <input type="number" name="amount_due" required min="1" placeholder="Amount Due ({{ $currency }})" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                <button type="submit" class="sm:col-span-2 rounded-lg text-white font-bold px-3 py-1.5 text-xs" style="background-color: var(--brand-accent)">Issue Fee Invoice</button>
            </form>
        </div>

        @foreach($feeInvoices ?? [] as $inv)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 px-3 py-2 text-xs">
                <div>
                    <span class="font-mono font-bold text-indigo-700">{{ $inv->invoice_no }}</span>
                    <strong> · {{ $inv->user?->name }}</strong> — {{ $inv->title }} ({{ $currency . $inv->amount_paid }}/{{ $currency . $inv->amount_due }})
                    <span class="uppercase font-bold ml-1 {{ $inv->status === 'paid' ? 'text-emerald-700' : 'text-amber-700' }}">[{{ $inv->status }}]</span>
                </div>
                @if($inv->status !== 'paid')
                    <form method="POST" action="{{ route('portal.fee_invoices.pay', $inv) }}" class="flex items-center gap-1.5">
                        @csrf
                        <input type="number" name="payment_amount" required min="1" max="{{ max(1, $inv->amount_due - $inv->amount_paid) }}" value="{{ max(1, $inv->amount_due - $inv->amount_paid) }}" class="w-24 rounded-lg border border-slate-300 px-2 py-1 text-xs">
                        <input type="hidden" name="payment_method" value="Campus Desk">
                        <button type="submit" class="rounded-lg bg-emerald-600 text-white px-2.5 py-1 font-bold">Record Payment</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Attendance & Staff Payroll --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 border-t border-slate-100 pt-4">
        <form method="POST" action="{{ route('portal.attendance.store') }}" class="space-y-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
            @csrf
            <h3 class="text-xs font-extrabold uppercase text-slate-700">Record Attendance ({{ ($attendance ?? collect())->count() }})</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <select name="target_id" required class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs">
                    @foreach($users ?? [] as $u)
                        <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->role }})</option>
                    @endforeach
                </select>
                <select name="status" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs">
                    <option value="present">Present</option>
                    <option value="late">Late</option>
                    <option value="absent">Absent</option>
                    <option value="leave">Leave</option>
                </select>
                <input type="date" name="attendance_date" required value="{{ date('Y-m-d') }}" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs">
            </div>
            <button type="submit" class="rounded-lg text-white font-bold px-3 py-1.5 text-xs" style="background-color: var(--brand-primary)">Mark Attendance</button>
        </form>

        <form method="POST" action="{{ route('portal.payroll.store') }}" class="space-y-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
            @csrf
            <h3 class="text-xs font-extrabold uppercase text-slate-700">Staff Payroll ({{ ($payrolls ?? collect())->count() }})</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <select name="employee_id" required class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs">
                    @foreach(($users ?? collect())->whereIn('role', ['teacher', 'branch']) as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->name }} ({{ $emp->role }})</option>
                    @endforeach
                </select>
                <input type="text" name="month_key" required value="{{ date('Y-m') }}" placeholder="YYYY-MM" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs">
                <input type="number" name="base_salary" required min="0" value="500" placeholder="Base Salary" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-xs">
            </div>
            <button type="submit" class="rounded-lg text-white font-bold px-3 py-1.5 text-xs" style="background-color: var(--brand-accent)">Generate Payroll</button>
        </form>
    </div>
</div>
