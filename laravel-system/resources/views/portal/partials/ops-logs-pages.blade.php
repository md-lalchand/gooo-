{{-- Custom CMS Pages, Header Menu Builder, Contact Inbox, Connectors & Audit Logs --}}
<div id="cms-ops-logs" class="rounded-2xl bg-white border border-slate-200 p-6 space-y-6">
    <div>
        <h2 class="text-lg font-extrabold text-slate-900">CMS Pages, Navigation Menu, Contact Inbox, Connectors &amp; Audit Logs</h2>
        <p class="text-xs text-slate-500">Create custom pages at <code>/page/{slug}</code>, customize top navigation links, manage contact inquiries, add payment/API integrations, and inspect security audit events.</p>
    </div>

    {{-- Custom CMS Pages --}}
    <div class="border-t border-slate-100 pt-4 space-y-3">
        <h3 class="text-sm font-extrabold text-slate-900">Custom CMS Pages ({{ ($customPages ?? collect())->count() }})</h3>
        <form method="POST" action="{{ route('portal.custom_pages.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
            @csrf
            <input type="text" name="title" required placeholder="Page Title (e.g. Scholarship Program)" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
            <input type="text" name="slug" placeholder="URL Slug (auto if blank)" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
            <input type="text" name="subtitle" placeholder="Subtitle (optional)" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
            <textarea name="content" rows="2" required placeholder="Full page content..." class="md:col-span-3 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs"></textarea>
            <div class="md:col-span-3 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-4 text-xs font-semibold text-slate-700">
                    <label class="flex items-center gap-1.5"><input type="checkbox" name="published" value="1" checked> Published</label>
                    <label class="flex items-center gap-1.5"><input type="checkbox" name="show_in_footer" value="1" checked> Show in Footer</label>
                </div>
                <button type="submit" class="rounded-lg text-white font-bold px-4 py-1.5 text-xs" style="background-color: var(--brand-accent)">+ Save CMS Page</button>
            </div>
        </form>
        @foreach($customPages ?? [] as $cp)
            <div class="flex flex-wrap items-center justify-between gap-2 border border-slate-200 rounded-lg px-3 py-2 text-xs">
                <span><strong>{{ $cp->title }}</strong> · <a href="{{ route('pages.show', $cp->slug) }}" class="text-indigo-700 underline">/page/{{ $cp->slug }}</a> ({{ $cp->published ? 'Published' : 'Hidden' }})</span>
                <div class="flex items-center gap-2">
                    <form method="POST" action="{{ route('portal.custom_pages.toggle', $cp) }}">@csrf @method('PATCH')<button type="submit" class="font-bold text-slate-700">Toggle</button></form>
                    <form method="POST" action="{{ route('portal.custom_pages.delete', $cp) }}">@csrf @method('DELETE')<button type="submit" class="font-bold text-rose-700">Delete</button></form>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Contact Inquiries Inbox --}}
    <div class="border-t border-slate-100 pt-4 space-y-2">
        <h3 class="text-sm font-extrabold text-slate-900">Contact Inquiries Inbox ({{ ($contactMessages ?? collect())->count() }})</h3>
        @forelse($contactMessages ?? [] as $msg)
            <div class="border border-slate-200 rounded-xl p-3 text-xs space-y-1.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span><strong>{{ $msg->subject }}</strong> — {{ $msg->name }} ({{ $msg->email }})</span>
                    <span class="uppercase font-bold {{ $msg->status === 'new' ? 'text-amber-700' : 'text-emerald-700' }}">{{ $msg->status }}</span>
                </div>
                <p class="text-slate-600">{{ $msg->message }}</p>
                <div class="flex items-center gap-2">
                    <form method="POST" action="{{ route('portal.contacts.update', $msg) }}" class="inline">
                        @csrf @method('PATCH')
                        <input type="hidden" name="status" value="replied">
                        <button type="submit" class="rounded bg-emerald-50 text-emerald-700 px-2 py-0.5 font-bold">Mark Replied</button>
                    </form>
                    <form method="POST" action="{{ route('portal.contacts.delete', $msg) }}" class="inline">
                        @csrf @method('DELETE')
                        <button type="submit" class="rounded bg-rose-50 text-rose-700 px-2 py-0.5 font-bold">Delete</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="text-xs text-slate-500">No contact inquiries yet.</p>
        @endforelse
    </div>

    {{-- Add Payment Gateway & API Connectors --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 border-t border-slate-100 pt-4">
        <form method="POST" action="{{ route('portal.gateways.store') }}" class="space-y-2 bg-slate-50 p-3 rounded-xl border border-slate-200">
            @csrf
            <h3 class="text-xs font-extrabold uppercase text-slate-700">+ Add Payment Gateway</h3>
            <div class="grid grid-cols-2 gap-2">
                <input type="text" name="name" required placeholder="Gateway Name" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                <select name="provider_type" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                    <option value="online">Online Gateway</option>
                    <option value="mfs">Mobile Wallet / MFS</option>
                    <option value="manual">Manual Bank</option>
                    <option value="cash">Campus Cash</option>
                </select>
                <input type="text" name="merchant_account" required placeholder="Merchant / Account ID" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                <select name="mode" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                    <option value="sandbox">Sandbox</option>
                    <option value="live">Live</option>
                </select>
            </div>
            <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" name="active" value="1" checked> Active on Checkout</label>
            <button type="submit" class="rounded-lg text-white font-bold px-3 py-1.5 text-xs" style="background-color: var(--brand-primary)">Save Payment Gateway</button>
        </form>

        <div class="space-y-2">
            <h3 class="text-xs font-extrabold uppercase text-slate-700">API Connectors &amp; Integrations ({{ count($apiConnectors ?? []) }})</h3>
            @foreach($apiConnectors ?? [] as $conn)
                <div class="flex items-center justify-between border border-slate-200 rounded-lg px-3 py-2 text-xs">
                    <span><strong>{{ $conn['name'] }}</strong> ({{ $conn['category'] ?? 'Integration' }} · {{ !empty($conn['active']) ? 'Active' : 'Inactive' }})</span>
                    <form method="POST" action="{{ route('portal.connectors.status', $conn['id']) }}">
                        @csrf @method('PATCH')
                        <button type="submit" class="rounded border border-slate-200 px-2 py-0.5 font-bold">Toggle</button>
                    </form>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Security Audit Trail --}}
    <div class="border-t border-slate-100 pt-4 space-y-2">
        <h3 class="text-sm font-extrabold text-slate-900">Recent Audit Trail ({{ ($auditLogs ?? collect())->count() }})</h3>
        <div class="max-h-48 overflow-y-auto space-y-1.5">
            @forelse($auditLogs ?? [] as $log)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-xs">
                    <span><strong class="font-mono text-indigo-700">{{ $log->action }}</strong> by {{ $log->actor_name }} ({{ strtoupper($log->actor_role) }})</span>
                    <span class="text-slate-500">{{ $log->created_at?->format('Y-m-d H:i') }}</span>
                </div>
            @empty
                <p class="text-xs text-slate-500">No audit events recorded yet.</p>
            @endforelse
        </div>
    </div>
</div>
