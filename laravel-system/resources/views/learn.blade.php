@extends('layouts.app')
@section('title', 'Classroom — ' . $brand['company_name'])
@section('content')
<div class="max-w-6xl mx-auto px-4 py-10 space-y-6">
    <div>
        <h1 class="text-3xl font-extrabold text-slate-900">{{ $brand['company_name'] }} Classroom</h1>
        <p class="text-sm text-slate-600 mt-1">Join live interactive classes, watch recorded lessons, track completion, and download lecture materials.</p>
    </div>

    @forelse($enrollments as $enr)
        @php
            $completedIds = $enr->accessible_completed_lesson_ids ?? [];
            $totalLessons = max(1, $enr->accessible_lessons->count());
            $progressPct = $enr->course->lessons->count() > 0 ? (int) round((count($completedIds) / $totalLessons) * 100) : 0;
            $courseCert = ($earnedCertificates ?? collect())->get($enr->course_id);
        @endphp
        <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900">{{ $enr->course->title }}</h2>
                    <p class="text-xs text-slate-500">Completion: {{ count($completedIds) }}/{{ $enr->course->lessons->count() }} lessons ({{ $progressPct }}%)</p>
                </div>
                <div class="flex items-center gap-2">
                    @if($courseCert)
                        <a href="{{ route('certificates.verify', ['code' => $courseCert->certificate_code]) }}" class="rounded-lg bg-emerald-100 text-emerald-800 px-3 py-1.5 text-xs font-bold">
                            Certificate: {{ $courseCert->certificate_code }}
                        </a>
                    @elseif($progressPct >= 100)
                        <form method="POST" action="{{ route('learn.certificate.claim', $enr->course) }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-emerald-600 text-white px-3 py-1.5 text-xs font-bold">Claim Certificate</button>
                        </form>
                    @endif
                    <span class="text-xs font-bold uppercase text-emerald-700">{{ $enr->course->lessons->count() }} Classes</span>
                </div>
            </div>

            <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                <div class="h-full rounded-full" style="width: {{ $progressPct }}%; background-color: var(--brand-accent)"></div>
            </div>

            @if($enr->course->announcements->count())
                <div class="space-y-2">
                    <h3 class="text-xs font-extrabold uppercase text-indigo-700">Course Announcements</h3>
                    @foreach($enr->course->announcements as $ann)
                        <div class="rounded-xl border border-indigo-200 bg-indigo-50/40 px-4 py-3 text-xs">
                            <p class="font-bold text-slate-900">{{ $ann->title }}</p>
                            <p class="text-slate-600 mt-0.5">{{ $ann->body }}</p>
                        </div>
                    @endforeach
                </div>
            @endif

            @if($enr->accessible_batches->count())
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @foreach($enr->course->batches as $bat)
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs">
                            <p class="font-extrabold text-slate-900">{{ $bat->name }} ({{ $bat->code }})</p>
                            <p class="text-slate-600 mt-0.5">{{ $bat->schedule }} · {{ $bat->mode }}</p>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="space-y-2.5">
                @forelse($enr->course->lessons as $l)
                    @php $done = in_array((int) $l->id, $completedIds, true); @endphp
                    <div class="border border-slate-200 rounded-xl p-4 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <span class="inline-block rounded px-2 py-0.5 text-[10px] font-extrabold uppercase {{ $l->lesson_type === 'live' ? 'bg-rose-50 text-rose-700' : ($l->lesson_type === 'lecture_sheet' ? 'bg-amber-50 text-amber-800' : 'bg-sky-50 text-sky-700') }}">
                                {{ str_replace('_', ' ', $l->lesson_type) }}
                            </span>
                            @if($l->batch)
                                <span class="inline-block rounded bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-700 ml-1">{{ $l->batch->name }}</span>
                            @endif
                            <p class="text-sm font-bold text-slate-900 mt-1">{{ $l->title }}</p>
                            <p class="text-xs text-slate-500">{{ $l->module_name }} · {{ $l->duration }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if($l->video_url)
                                <a href="{{ $l->video_url }}" target="_blank" rel="noopener noreferrer" class="rounded-lg bg-slate-900 text-white px-3 py-1.5 text-xs font-bold">Watch Lesson</a>
                            @endif
                            @if($l->lesson_type === 'live' && $l->live_join_url && $l->live_status !== 'ended')
                                <a href="{{ $l->live_join_url }}" target="_blank" rel="noopener noreferrer" class="rounded-lg bg-rose-600 text-white px-3 py-1.5 text-xs font-bold">Join Live</a>
                            @endif
                            @if($l->lecture_sheet_url)
                                <a href="{{ $l->lecture_sheet_url }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-700">Material / PDF</a>
                            @endif
                            <form method="POST" action="{{ route('learn.progress') }}">
                                @csrf
                                <input type="hidden" name="course_id" value="{{ $enr->course_id }}">
                                <input type="hidden" name="lesson_id" value="{{ $l->id }}">
                                <button type="submit" class="rounded-lg px-3 py-1.5 text-xs font-bold {{ $done ? 'bg-emerald-100 text-emerald-800' : 'bg-indigo-600 text-white' }}">
                                    {{ $done ? 'Completed' : 'Mark Complete' }}
                                </button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-slate-500">No lessons published in this course yet.</p>
                @endforelse
            </div>

            @if($enr->accessible_assignments->count())
                <div class="border-t border-slate-100 pt-4 space-y-3">
                    <h3 class="text-sm font-extrabold text-slate-900">Assignments ({{ $enr->course->assignments->count() }})</h3>
                    @foreach($enr->course->assignments as $asg)
                        @php $sub = ($submissions ?? collect())->get($asg->id); @endphp
                        <div class="rounded-xl border border-slate-200 p-4 text-xs space-y-2">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-bold text-sm text-slate-900">{{ $asg->title }}</p>
                                <span class="font-bold text-indigo-700">Max {{ $asg->max_marks }} Marks @if($asg->due_date) · Due {{ $asg->due_date }} @endif</span>
                            </div>
                            <p class="text-slate-600">{{ $asg->instructions }}</p>
                            @if($sub)
                                <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-2.5">
                                    <p class="font-bold text-emerald-800">Status: {{ strtoupper($sub->status) }} @if($sub->marks_awarded !== null) · Score: {{ $sub->marks_awarded }}/{{ $asg->max_marks }} @endif</p>
                                    @if($sub->feedback)<p class="text-emerald-700 mt-0.5">Feedback: {{ $sub->feedback }}</p>@endif
                                </div>
                            @endif
                            <form method="POST" action="{{ route('learn.assignments.submit', $asg) }}" class="space-y-2">
                                @csrf
                                <textarea name="submission_text" rows="2" required placeholder="Write your answer or summary..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">{{ $sub?->submission_text }}</textarea>
                                <div class="flex flex-wrap gap-2">
                                    <input type="text" name="attachment_url" value="{{ $sub?->attachment_url }}" placeholder="Optional attachment URL" class="flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                                    <button type="submit" class="rounded-lg text-white font-bold px-4 py-1.5 text-xs" style="background-color: var(--brand-primary)">{{ $sub ? 'Resubmit Assignment' : 'Submit Assignment' }}</button>
                                </div>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif

            @if($enr->accessible_scorm_packages->count())
                <div class="border-t border-slate-100 pt-4 space-y-2">
                    <h3 class="text-sm font-extrabold text-slate-900">Interactive SCORM Activities</h3>
                    @foreach($enr->course->scormPackages as $pkg)
                        @php $scormDone = in_array($pkg->id, $completedScormIds ?? [], true); @endphp
                        <div class="rounded-xl border border-slate-200 p-4 flex flex-wrap items-center justify-between gap-2 text-xs">
                            <div>
                                <p class="font-bold text-slate-900">{{ $pkg->title }}</p>
                                <p class="text-slate-500">Reward: +{{ $pkg->xp_reward }} XP · {{ $scormDone ? 'Completed' : 'Ready' }}</p>
                            </div>
                            <form method="POST" action="{{ route('learn.scorm.complete', $pkg) }}">
                                @csrf
                                <input type="hidden" name="score" value="100">
                                <button type="submit" class="rounded-lg px-3 py-1.5 font-bold {{ $scormDone ? 'bg-emerald-100 text-emerald-800' : 'text-white' }}" @if(!$scormDone) style="background-color: var(--brand-accent)" @endif>
                                    {{ $scormDone ? 'SCORM Completed' : 'Complete Activity' }}
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <div class="rounded-2xl bg-white border border-slate-200 p-8 text-center text-slate-600">
            <p class="font-bold text-slate-800">No active enrollments yet.</p>
            <p class="text-xs mt-1">Browse the course catalog and enroll to unlock classroom lessons.</p>
            <a href="{{ route('courses.index') }}" class="mt-4 inline-block rounded-xl text-white font-bold px-5 py-2.5 text-xs" style="background-color: var(--brand-primary)">Browse Courses</a>
        </div>
    @endforelse
</div>
@endsection
