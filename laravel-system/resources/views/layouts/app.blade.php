<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', $brand['company_name'] ?? 'Academy LMS')</title>
    <meta name="description" content="{{ $brand['tagline'] ?? '' }}">
    <link rel="icon" href="{{ $brand['favicon_url'] ?? asset('images/default-logo.svg') }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --brand-primary: {{ $brand['primary_color'] ?? '#062436' }};
            --brand-accent: {{ $brand['accent_color'] ?? '#4f46e5' }};
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen flex flex-col">
    <div id="global-loading-bar" role="progressbar" aria-label="Page and API loading progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" class="pointer-events-none fixed inset-x-0 top-0 z-50 h-[3px] opacity-0 transition-opacity duration-200">
        <div id="global-loading-fill" class="h-full w-full origin-left scale-x-0 transition-transform duration-200 ease-out" style="background: linear-gradient(90deg, var(--brand-primary), var(--brand-accent));"></div>
    </div>
    <header class="sticky top-0 z-40 bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-3 sm:px-4 h-16 flex items-center justify-between gap-2 sm:gap-4">
            <a href="{{ route('home') }}" class="flex items-center gap-2 sm:gap-2.5 min-w-0">
                <span class="h-9 w-9 sm:h-10 sm:w-10 rounded-xl p-1 flex items-center justify-center shrink-0" style="background-color: var(--brand-primary)">
                    <img src="{{ $brand['logo_url'] ?? asset('images/default-logo.svg') }}" alt="{{ $brand['company_name'] }}" class="h-full w-full object-contain rounded-lg">
                </span>
                <span class="font-extrabold text-base sm:text-lg tracking-tight truncate" style="color: var(--brand-primary)">{{ $brand['company_name'] }}</span>
            </a>

            @php
                $rawMenu = \Illuminate\Support\Facades\DB::table('settings')->where('key', 'header_menu')->value('value');
                $headerMenu = $rawMenu ? (json_decode($rawMenu, true) ?: []) : [];
                if (empty($headerMenu)) {
                    $headerMenu = [
                        ['label' => 'Home', 'path' => route('home'), 'visible' => true],
                        ['label' => 'Courses', 'path' => route('courses.index'), 'visible' => true],
                        ['label' => 'Learn', 'path' => route('learn'), 'visible' => true],
                        ['label' => 'Instructors', 'path' => route('instructors'), 'visible' => true],
                        ['label' => 'Exams', 'path' => route('exams.index'), 'visible' => true],
                        ['label' => 'Store', 'path' => route('products'), 'visible' => true],
                        ['label' => 'E-Books', 'path' => route('ebooks'), 'visible' => true],
                        ['label' => 'Results', 'path' => route('results'), 'visible' => true],
                        ['label' => 'Certificates', 'path' => route('certificates.verify'), 'visible' => true],
                        ['label' => 'Community', 'path' => route('community'), 'visible' => true],
                    ];
                }
            @endphp

            <nav aria-label="Primary Navigation" class="hidden lg:flex items-center gap-5 text-sm font-medium text-slate-600 shrink-0">
                @foreach($headerMenu as $m)
                    @if(($m['visible'] ?? true) !== false)
                        <a href="{{ $m['path'] ?? '/' }}" class="inline-flex items-center gap-1 hover:text-indigo-600 transition">
                            <span>{{ $m['label'] ?? 'Link' }}</span>
                            @if(!empty($m['badge']))
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800">{{ $m['badge'] }}</span>
                            @endif
                        </a>
                    @endif
                @endforeach
            </nav>

            <div class="flex items-center gap-1.5 sm:gap-2 shrink-0">
                @auth
                    @php
                        $unreadNotifCount = \Illuminate\Support\Facades\Schema::hasTable('in_app_notifications')
                            ? \App\Models\InAppNotification::where('user_id', auth()->id())->where('is_read', false)->count()
                            : 0;
                    @endphp
                    <a href="{{ route('profile.show') }}" class="px-2.5 sm:px-3 py-2 rounded-lg border border-slate-200 bg-slate-50 text-slate-700 text-xs font-semibold whitespace-nowrap">
                        Profile @if($unreadNotifCount > 0)<span class="ml-1 rounded-full bg-indigo-600 text-white px-1.5 py-0.5 text-[10px]">{{ $unreadNotifCount }}</span>@endif
                    </a>
                    <a href="{{ route('portal.index') }}" class="px-2.5 sm:px-4 py-2 rounded-lg text-white text-xs sm:text-sm font-semibold whitespace-nowrap" style="background-color: var(--brand-primary)">Portal</a>
                @else
                    <a href="{{ route('login') }}" class="px-3 sm:px-4 py-2 rounded-lg text-white text-xs sm:text-sm font-semibold whitespace-nowrap" style="background-color: var(--brand-accent)">Sign In</a>
                @endauth
                <button type="button" onclick="document.getElementById('mobile-nav').classList.toggle('hidden')" aria-label="Toggle Menu" class="lg:hidden rounded-lg border border-slate-200 px-2.5 py-2 text-xs font-bold text-slate-700 whitespace-nowrap">
                    Menu
                </button>
            </div>
        </div>

        <nav id="mobile-nav" aria-label="Mobile Navigation" class="hidden lg:hidden border-t border-slate-200 bg-white px-4 py-3 space-y-2">
            @foreach($headerMenu as $m)
                @if(($m['visible'] ?? true) !== false)
                    <a href="{{ $m['path'] ?? '/' }}" class="block py-1.5 text-sm font-medium text-slate-700 hover:text-indigo-600">
                        {{ $m['label'] ?? 'Link' }}
                    </a>
                @endif
            @endforeach
            <a href="{{ route('about') }}" class="block py-1.5 text-sm font-medium text-slate-700">About Us</a>
            <a href="{{ route('contact') }}" class="block py-1.5 text-sm font-medium text-slate-700">Contact Us</a>
        </nav>
    </header>

    <main class="flex-1">
        @if(session('status'))
            <div class="max-w-7xl mx-auto px-4 mt-4">
                <div role="status" class="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>
            </div>
        @endif
        @if($errors->any())
            <div class="max-w-7xl mx-auto px-4 mt-4">
                <div role="alert" class="rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm font-semibold text-rose-800 space-y-1">
                    @foreach($errors->all() as $err)
                        <p>{{ $err }}</p>
                    @endforeach
                </div>
            </div>
        @endif
        @yield('content')
    </main>

    @php
        $footerCustomPages = \Illuminate\Support\Facades\Schema::hasTable('custom_pages')
            ? \App\Models\CustomPage::where('published', true)->where('show_in_footer', true)->get()
            : collect();
    @endphp
    <footer class="text-slate-300 py-12 mt-16" style="background-color: var(--brand-primary)">
        <div class="max-w-7xl mx-auto px-4 space-y-8 text-sm">
            <div class="flex flex-col md:flex-row justify-between gap-8">
                <div class="max-w-md">
                    <p class="font-extrabold text-white text-base">{{ $brand['company_name'] }}</p>
                    <p class="text-slate-300/80 mt-1">{{ $brand['tagline'] }}</p>
                    @if(!empty($brand['office_address']) || !empty($brand['support_email']) || !empty($brand['support_phone']))
                        <p class="text-slate-300/70 text-xs mt-2">
                            {{ $brand['office_address'] }}
                            @if(!empty($brand['support_email'])) · {{ $brand['support_email'] }} @endif
                            @if(!empty($brand['support_phone'])) · {{ $brand['support_phone'] }} @endif
                        </p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-x-6 gap-y-2 text-xs text-slate-300">
                    <a href="{{ route('courses.index') }}" class="hover:text-white">Courses</a>
                    <a href="{{ route('about') }}" class="hover:text-white">About Us</a>
                    <a href="{{ route('contact') }}" class="hover:text-white">Contact Us</a>
                    <a href="{{ route('results') }}" class="hover:text-white">Board Results</a>
                    <a href="{{ route('certificates.verify') }}" class="hover:text-white">Verify Certificate</a>
                    <a href="{{ route('policy.terms') }}" class="hover:text-white">Terms</a>
                    <a href="{{ route('policy.privacy') }}" class="hover:text-white">Privacy</a>
                    <a href="{{ route('policy.refund') }}" class="hover:text-white">Refund Policy</a>
                    @foreach($footerCustomPages as $fPage)
                        <a href="{{ route('pages.show', $fPage->slug) }}" class="hover:text-white">{{ $fPage->title }}</a>
                    @endforeach
                </div>
            </div>
            <div class="border-t border-white/10 pt-5 flex flex-col sm:flex-row justify-between gap-2 text-xs text-slate-300/80">
                <p>{{ $brand['footer_text'] ?: ('© ' . date('Y') . ' ' . $brand['company_name'] . '. All Rights Reserved.') }}</p>
                <p class="text-slate-400">Platform v{{ $brand['system_version'] ?? config('version.version', '1.0.0') }}</p>
            </div>
        </div>
    </footer>
    <script>
        (function () {
            var bar = document.getElementById('global-loading-bar');
            var fill = document.getElementById('global-loading-fill');
            if (!bar || !fill) return;
            var active = 0;
            var progress = 0;
            var timer = null;

            function render() {
                bar.style.opacity = active > 0 ? '1' : '0';
                bar.setAttribute('aria-valuenow', String(Math.round(progress * 100)));
                fill.style.transform = 'scaleX(' + progress + ')';
            }

            function start() {
                active++;
                if (progress === 0) progress = 0.28;
                render();
                if (!timer) {
                    timer = setInterval(function () {
                        if (active <= 0) { clearInterval(timer); timer = null; return; }
                        if (progress < 0.9) { progress = Math.min(0.92, progress + (0.92 - progress) * 0.2); render(); }
                    }, 200);
                }
            }

            function done() {
                active = Math.max(0, active - 1);
                if (active === 0) {
                    if (timer) { clearInterval(timer); timer = null; }
                    progress = 1;
                    render();
                    setTimeout(function () { if (active === 0) { progress = 0; render(); } }, 200);
                }
            }

            document.addEventListener('click', function (e) {
                var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
                if (!a || e.defaultPrevented || a.target === '_blank' || a.hasAttribute('download')) return;
                if (a.origin === window.location.origin && a.getAttribute('href').charAt(0) !== '#') start();
            });
            document.addEventListener('submit', function (e) { if (!e.defaultPrevented) start(); });
            window.addEventListener('pageshow', function () { active = 0; progress = 0; render(); });

            if (typeof window.fetch === 'function') {
                var origFetch = window.fetch.bind(window);
                window.fetch = function () {
                    start();
                    return origFetch.apply(window, arguments).finally(done);
                };
            }
        })();
    </script>
</body>
</html>
