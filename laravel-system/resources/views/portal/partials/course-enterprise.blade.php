{{-- Course Modules, Chapters, Assignments, Grading, Announcements, SCORM & Exam Builder --}}
<div id="course-enterprise-studio" class="rounded-2xl bg-white border border-slate-200 p-6 space-y-6">
    <div>
        <h2 class="text-lg font-extrabold text-slate-900">Curriculum Modules, Assignments, Announcements &amp; SCORM Studio</h2>
        <p class="text-xs text-slate-500">Organize structured course modules/chapters, publish &amp; grade student assignments, post course notices, and upload SCORM 1.2 packages.</p>
    </div>

    {{-- Course Modules & Chapters --}}
    <div class="border-t border-slate-100 pt-4 space-y-3">
        <h3 class="text-sm font-extrabold text-slate-900">Course Modules &amp; Chapters ({{ ($courseModules ?? collect())->count() }})</h3>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
            <form method="POST" action="{{ route('portal.modules.store') }}" class="grid grid-cols-1 sm:grid-cols-3 gap-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
                @csrf
                <select name="course_id" required class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                    @foreach($courses as $c)
                        <option value="{{ $c->id }}">{{ $c->title }}</option>
                    @endforeach
                </select>
                <input type="text" name="title" required placeholder="Module Title" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs sm:col-span-2">
                <input type="text" name="description" placeholder="Module summary (optional)" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs sm:col-span-2">
                <button type="submit" class="rounded-lg text-white font-bold px-3 py-1.5 text-xs" style="background-color: var(--brand-accent)">+ Add Module</button>
            </form>

            <form method="POST" action="{{ route('portal.chapters.store') }}" class="grid grid-cols-1 sm:grid-cols-3 gap-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
                @csrf
                <select name="module_id" required class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                    @forelse($courseModules ?? [] as $mod)
                        <option value="{{ $mod->id }}">{{ $mod->course?->title }} — {{ $mod->title }}</option>
                    @empty
                        <option value="" disabled>Create a module first</option>
                    @endforelse
                </select>
                <input type="text" name="title" required placeholder="Chapter Title" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs sm:col-span-2">
                <input type="text" name="summary" placeholder="Chapter overview" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs sm:col-span-2">
                <button type="submit" class="rounded-lg text-white font-bold px-3 py-1.5 text-xs" style="background-color: var(--brand-primary)">+ Add Chapter</button>
            </form>
        </div>

        @foreach($courseModules ?? [] as $mod)
            <div class="rounded-xl border border-slate-200 p-3 text-xs space-y-1.5">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-bold text-slate-900">{{ $mod->course?->title }} · {{ $mod->title }}</span>
                    <form method="POST" action="{{ route('portal.modules.delete', $mod) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-rose-700 font-bold">Delete</button>
                    </form>
                </div>
                @if($mod->chapters->count() > 0)
                    <div class="pl-3 space-y-1">
                        @foreach($mod->chapters as $chap)
                            <div class="flex items-center justify-between text-slate-600">
                                <span>• {{ $chap->title }}</span>
                                <form method="POST" action="{{ route('portal.chapters.delete', $chap) }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-rose-600">Remove</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Assignments & Submissions Grading --}}
    <div class="border-t border-slate-100 pt-4 space-y-3">
        <h3 class="text-sm font-extrabold text-slate-900">Course Assignments &amp; Student Grading ({{ ($assignments ?? collect())->count() }})</h3>
        <form method="POST" action="{{ route('portal.assignments.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
            @csrf
            <select name="course_id" required class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                @foreach($courses as $c)
                    <option value="{{ $c->id }}">{{ $c->title }}</option>
                @endforeach
            </select>
            <input type="text" name="title" required placeholder="Assignment Title" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs md:col-span-2">
            <input type="number" name="max_marks" required value="100" min="1" placeholder="Max Marks" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
            <input type="date" name="due_date" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
            <input type="text" name="instructions" required placeholder="Assignment instructions / prompt..." class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs md:col-span-2">
            <button type="submit" class="rounded-lg text-white font-bold px-3 py-1.5 text-xs" style="background-color: var(--brand-accent)">+ Publish Assignment</button>
        </form>

        @foreach($assignments ?? [] as $asg)
            <div class="rounded-xl border border-slate-200 p-4 text-xs space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="font-bold text-slate-900">{{ $asg->title }} <span class="text-indigo-700">({{ $asg->course?->title }})</span></p>
                        <p class="text-slate-500">Max Marks: {{ $asg->max_marks }} @if($asg->due_date) · Due {{ $asg->due_date }} @endif · Submissions: {{ $asg->submissions->count() }}</p>
                    </div>
                    <form method="POST" action="{{ route('portal.assignments.delete', $asg) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-rose-700 font-bold">Delete</button>
                    </form>
                </div>
                @foreach($asg->submissions as $sub)
                    <form method="POST" action="{{ route('portal.submissions.grade', $sub) }}" class="rounded-lg bg-slate-50 border border-slate-200 p-2.5 grid grid-cols-1 md:grid-cols-5 gap-2 items-center">
                        @csrf @method('PATCH')
                        <div class="md:col-span-2">
                            <p class="font-bold text-slate-900">{{ $sub->user?->name ?? 'Student' }} ({{ strtoupper($sub->status) }})</p>
                            <p class="text-slate-600 line-clamp-2">{{ $sub->submission_text }}</p>
                        </div>
                        <input type="number" name="marks_awarded" min="0" max="{{ $asg->max_marks }}" value="{{ $sub->marks_awarded ?? $asg->max_marks }}" required class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs">
                        <input type="text" name="feedback" value="{{ $sub->feedback }}" placeholder="Instructor feedback" class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs">
                        <div class="flex items-center gap-1.5">
                            <input type="hidden" name="status" value="graded">
                            <button type="submit" class="rounded-lg bg-emerald-600 text-white px-3 py-1 font-bold">Grade</button>
                        </div>
                    </form>
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- Course Announcements & Build Exam from Bank --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 border-t border-slate-100 pt-4">
        <div class="space-y-3">
            <h3 class="text-sm font-extrabold text-slate-900">Course Announcements ({{ ($courseAnnouncements ?? collect())->count() }})</h3>
            <form method="POST" action="{{ route('portal.course_announcements.store') }}" class="space-y-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
                @csrf
                <select name="course_id" required class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                    @foreach($courses as $c)
                        <option value="{{ $c->id }}">{{ $c->title }}</option>
                    @endforeach
                </select>
                <input type="text" name="title" required placeholder="Announcement Title" class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                <textarea name="body" rows="2" required placeholder="Notice details..." class="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs"></textarea>
                <button type="submit" class="rounded-lg text-white font-bold px-3 py-1.5 text-xs" style="background-color: var(--brand-primary)">Post Course Notice</button>
            </form>
        </div>

        <div class="space-y-3">
            <h3 class="text-sm font-extrabold text-slate-900">Build Exam from Question Bank</h3>
            <form method="POST" action="{{ route('portal.exams.from_bank') }}" class="grid grid-cols-1 sm:grid-cols-2 gap-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
                @csrf
                <select name="course_id" {{ $user->role === 'teacher' ? 'required' : '' }} class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs sm:col-span-2">
                    @if($user->role === 'admin')<option value="">General Exam (not course-linked)</option>@endif
                    @foreach($courses as $c)<option value="{{ $c->id }}">{{ $c->title }}</option>@endforeach
                </select>
                <input type="text" name="title" required placeholder="New Exam Title" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs sm:col-span-2">
                <input type="text" name="category" required value="Assessment" placeholder="Category" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                <input type="text" name="subject" placeholder="Filter Subject (optional)" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                <input type="number" name="count" required value="5" min="1" max="50" placeholder="# Questions" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                <input type="number" name="duration_minutes" required value="20" min="5" max="180" placeholder="Duration (min)" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                <button type="submit" class="sm:col-span-2 rounded-lg text-white font-bold px-3 py-1.5 text-xs" style="background-color: var(--brand-accent)">Assemble Exam from Bank</button>
            </form>
        </div>
    </div>

    {{-- SCORM 1.2 Packages --}}
    <div class="border-t border-slate-100 pt-4 space-y-3">
        <h3 class="text-sm font-extrabold text-slate-900">SCORM 1.2 Interactive Modules ({{ ($scormPackages ?? collect())->count() }})</h3>
        <form method="POST" action="{{ route('portal.scorm.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
            @csrf
            <select name="course_id" {{ $user->role === 'teacher' ? 'required' : '' }} class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                @if($user->role === 'admin')<option value="">Any Course</option>@endif
                @foreach($courses as $c)
                    <option value="{{ $c->id }}">{{ $c->title }}</option>
                @endforeach
            </select>
            <input type="text" name="title" required placeholder="SCORM Activity Title" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
            <input type="text" name="identifier" required placeholder="Identifier (e.g. MOD-SCORM-01)" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
            <input type="number" name="xp_reward" required value="150" min="10" max="2000" placeholder="XP Reward" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
            <textarea name="html_content" rows="2" required placeholder="Interactive lesson HTML / activity instructions..." class="md:col-span-3 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-mono"></textarea>
            <button type="submit" class="rounded-lg text-white font-bold px-3 py-1.5 text-xs" style="background-color: var(--brand-primary)">+ Publish SCORM</button>
        </form>
        @foreach($scormPackages ?? [] as $pkg)
            <div class="flex items-center justify-between border border-slate-200 rounded-lg px-3 py-2 text-xs">
                <span><strong>{{ $pkg->title }}</strong> ({{ $pkg->identifier }}) · {{ $pkg->xp_reward }} XP</span>
                <form method="POST" action="{{ route('portal.scorm.delete', $pkg) }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-rose-700 font-bold">Delete</button>
                </form>
            </div>
        @endforeach
    </div>
</div>
