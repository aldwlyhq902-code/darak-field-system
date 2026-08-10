@extends('layouts.panel')
@section('title', 'الفريق والأجهزة')

@section('content')
<h1>الفريق والأجهزة</h1>
<div class="sub">المهنة تُسجَّل كما هي مدوّنة في رخصة العمل. النظام يحفظها ولا يفسّر نظام العمل.</div>

<div class="card">
    <div class="hd">المستخدمون</div>
    <table>
        <thead><tr><th>الاسم</th><th style="width:130px">الدور</th><th>المهنة</th><th style="width:120px">الوردية</th><th style="width:90px">الحالة</th><th style="width:90px"></th></tr></thead>
        <tbody>
        @foreach ($users as $user)
            <tr>
                <td>{{ $user->name }}<br><span style="font-size:12px;color:var(--muted)">{{ $user->email }}</span></td>
                <td>
                    <span class="pill grey">
                        {{ ['owner_supervisor' => 'مالك/مشرف', 'technician' => 'فني', 'admin' => 'إداري'][$user->role] ?? $user->role }}
                    </span>
                </td>
                <td style="font-size:13px">
                    {{ $user->trade ?? '—' }}
                    @if ($user->specialties)
                        <div style="color:var(--muted);font-size:12px">{{ implode(' · ', $user->specialties) }}</div>
                    @endif
                </td>
                <td style="font-size:13px">
                    @if ($user->shift_start)
                        {{ substr($user->shift_start, 0, 5) }}–{{ substr($user->shift_end, 0, 5) }}
                    @else — @endif
                </td>
                <td><span class="pill {{ $user->is_active ? 'green' : 'red' }}">{{ $user->is_active ? 'نشط' : 'موقوف' }}</span></td>
                <td>
                    <form method="POST" action="{{ route('panel.team.toggle', $user) }}">
                        @csrf
                        <button class="btn small ghost">{{ $user->is_active ? 'إيقاف' : 'تفعيل' }}</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="bd">
        <details>
            <summary style="cursor:pointer;color:var(--teal)">إضافة فني</summary>
            <form method="POST" action="{{ route('panel.team.technician') }}" style="margin-top:10px">
                @csrf
                <div class="grid3">
                    <div class="field"><label>الاسم</label><input name="name" required></div>
                    <div class="field"><label>البريد</label><input type="email" name="email" required></div>
                    <div class="field"><label>كلمة المرور</label><input type="password" name="password" required></div>
                </div>
                <div class="grid3">
                    <div class="field"><label>الجوال</label><input name="phone"></div>
                    <div class="field"><label>المهنة في رخصة العمل</label><input name="trade" value="فني تكييف وتبريد"></div>
                    <div class="field">
                        <label>الوردية</label>
                        <div style="display:flex;gap:6px">
                            <input name="shift_start" value="07:00" required>
                            <input name="shift_end" value="15:00" required>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label>التخصصات (تُستخدم في منع الإسناد الخاطئ)</label>
                    @foreach (['split_ac' => 'سبليت', 'package_ac' => 'مركزي', 'chiller' => 'تبريد', 'freezer' => 'تجميد', 'electrical' => 'كهرباء', 'plumbing' => 'سباكة'] as $key => $label)
                        <label style="display:inline-flex;gap:5px;align-items:center;color:var(--ink);margin-inline-end:14px">
                            <input type="checkbox" name="specialties[]" value="{{ $key }}" style="width:auto">{{ $label }}
                        </label>
                    @endforeach
                </div>
                <button class="btn small">إضافة</button>
            </form>
        </details>
    </div>
</div>

<div class="card">
    <div class="hd">الأجهزة</div>
    <div class="bd">
        <div class="note">
            جوال الفني يحمل بيانات عملاء وصوراً وتوقيعات. فقدانه حدث عادي لا نادر —
            الإبطال يُسقط رمز ذلك الجهاز فوراً دون منع الشخص من استخدام جهاز بديل.
        </div>
    </div>
    @if ($devices->isEmpty())
        <div class="empty">لا أجهزة مسجلة. يسجّل الفني دخوله من التطبيق فيظهر جهازه هنا.</div>
    @else
        <table>
            <thead><tr><th>الفني</th><th style="width:150px">آخر ظهور</th><th style="width:130px">آخر مزامنة</th><th style="width:110px">الحالة</th><th style="width:140px"></th></tr></thead>
            <tbody>
            @foreach ($devices as $device)
                <tr>
                    <td>{{ $device->user?->name ?? '—' }}<br>
                        <span style="font-size:11px;color:var(--muted)">{{ Str::limit($device->device_uuid, 18) }}</span></td>
                    <td style="font-size:13px">{{ $device->last_seen_at?->diffForHumans() ?? '—' }}</td>
                    <td style="font-size:13px">{{ $device->last_sync_at?->diffForHumans() ?? '—' }}</td>
                    <td>
                        <span class="pill {{ $device->isRevoked() ? 'red' : 'green' }}">
                            {{ $device->isRevoked() ? 'مُبطل' : 'نشط' }}
                        </span>
                    </td>
                    <td>
                        @unless ($device->isRevoked())
                            <form method="POST" action="{{ route('panel.team.revoke', $device) }}">
                                @csrf
                                <input type="hidden" name="reason" value="أُبطل من اللوحة">
                                <button class="btn small ghost" style="color:var(--red);border-color:var(--red)">إبطال</button>
                            </form>
                        @else
                            <span style="font-size:12px;color:var(--muted)">{{ $device->revoked_reason }}</span>
                        @endunless
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
