@php
    $hour = (int) now($tenant->timezone ?: 'Asia/Jakarta')->format('G');
    $greeting = match (true) { $hour < 11 => 'Selamat pagi', $hour < 15 => 'Selamat siang', $hour < 18 => 'Selamat sore', default => 'Selamat malam' };
    $channelState = match (true) {
        $channel?->isConnected() => ['ok', 'Terhubung', $channel->name],
        $channel?->status === 'qr' => ['warn', 'Menunggu scan QR', 'Buka halaman Koneksi'],
        default => ['off', 'Belum terhubung', 'Hubungkan nomor bisnis Anda'],
    };
    $allDone = $stepsDone === count($steps);
@endphp

<div class="dash">
    <section class="hero">
        <div>
            <p class="eyebrow">{{ $greeting }} 👋</p>
            <h1>{{ auth()->user()->name }}</h1>
            <p class="sub">
                Workspace <strong>{{ $tenant->name }}</strong>
                @if ($isSubscribed)
                    <span class="pill">Paket {{ strtoupper($subscription->plan_code) }}</span>
                @else
                    <span class="pill warn">Belum berlangganan</span>
                @endif
            </p>
        </div>
        <div class="hero-actions">
            @if ($isSubscribed)
                <a class="btn light" href="{{ route('app.chat-test') }}">@include('tenant.partials.icon', ['name' => 'sparkles']) Tes chatbot</a>
                <a class="btn outline" href="{{ route('app.conversations') }}">@include('tenant.partials.icon', ['name' => 'chat']) Percakapan</a>
            @else
                <a class="btn light" href="{{ route('app.subscription') }}">@include('tenant.partials.icon', ['name' => 'card']) Lihat cara berlangganan</a>
            @endif
        </div>
    </section>

    @foreach ($alerts as $alert)
        <div class="callout {{ $alert->severity === 'critical' ? 'bad' : 'warn' }}" wire:key="alert{{ $alert->id }}">
            @include('tenant.partials.icon', ['name' => 'warning'])
            <div>
                <strong>{{ $alert->title }}</strong>@if ($alert->occurrences > 1) <span class="muted">({{ $alert->occurrences }} kali)</span>@endif
                @if ($alert->body)<div>{{ $alert->body }}</div>@endif
            </div>
            @if ($alert->action_route && \Illuminate\Support\Facades\Route::has($alert->action_route))
                <a class="go" href="{{ route($alert->action_route) }}">Buka @include('tenant.partials.icon', ['name' => 'arrow-right'])</a>
            @endif
        </div>
    @endforeach
    @if ($alerts->count() > 0)
        <p class="muted see-all"><a href="{{ route('app.notifications') }}">Lihat semua notifikasi</a></p>
    @endif

    @unless ($isSubscribed)
        <div class="callout warn">
            @include('tenant.partials.icon', ['name' => 'card'])
            <div>
                <strong>Workspace ini belum berlangganan.</strong>
                <div>Fitur chatbot dan WhatsApp aktif setelah langganan diaktifkan oleh tim Velora.</div>
            </div>
            <a class="go" href="{{ route('app.subscription') }}">Cara berlangganan @include('tenant.partials.icon', ['name' => 'arrow-right'])</a>
        </div>
    @endunless

    <section class="stats">
        <div class="stat">
            <span class="chip indigo">@include('tenant.partials.icon', ['name' => 'bolt'])</span>
            <span class="lbl">Pesan AI hari ini</span>
            <span class="val">{{ number_format($used, 0, ',', '.') }}<small>@if ($limit) / {{ number_format($limit, 0, ',', '.') }}@endif</small></span>
            @if ($limit)
                <span class="bar"><i style="width: {{ $usedPercent }}%" class="{{ $usedPercent >= 90 ? 'bad' : ($usedPercent >= 70 ? 'warn' : '') }}"></i></span>
                <span class="foot">{{ $usedPercent }}% kuota harian terpakai</span>
            @else
                <span class="foot">{{ $isSubscribed ? 'Tanpa batas harian' : 'Aktif setelah berlangganan' }}</span>
            @endif
        </div>

        <div class="stat">
            <span class="chip sky">@include('tenant.partials.icon', ['name' => 'chat'])</span>
            <span class="lbl">Percakapan hari ini</span>
            <span class="val">{{ number_format($conversationsToday, 0, ',', '.') }}</span>
            <span class="foot">
                @if ($failedCount > 0)
                    <a class="bad-link" href="{{ route('app.failed-messages') }}">{{ $failedCount }} pesan gagal terkirim</a>
                @else
                    Pelanggan yang chat hari ini
                @endif
            </span>
        </div>

        <a class="stat link" href="{{ route('app.whatsapp') }}">
            <span class="chip green">@include('tenant.partials.icon', ['name' => 'phone'])</span>
            <span class="lbl">WhatsApp</span>
            <span class="val status {{ $channelState[0] }}"><i></i>{{ $channelState[1] }}</span>
            <span class="foot">{{ $channelState[2] }}</span>
        </a>

        <a class="stat link" href="{{ route('app.subscription') }}">
            <span class="chip amber">@include('tenant.partials.icon', ['name' => 'card'])</span>
            <span class="lbl">Langganan</span>
            @if ($isSubscribed)
                <span class="val">{{ strtoupper($subscription->plan_code) }}</span>
                <span class="foot {{ $daysLeft !== null && $daysLeft <= 7 ? 'bad-text' : '' }}">
                    {{ $daysLeft === null ? 'Aktif' : "Berakhir dalam {$daysLeft} hari · " . $subscription->current_period_end->timezone($tenant->timezone ?: 'Asia/Jakarta')->locale('id')->translatedFormat('d M Y') }}
                </span>
            @else
                <span class="val muted-val">—</span>
                <span class="foot">Belum berlangganan</span>
            @endif
        </a>
    </section>

    <section class="cols">
        <div class="panel">
            <div class="panel-h">
                <div>
                    <h2>{{ $allDone ? 'Chatbot Anda siap 🎉' : 'Siapkan chatbot Anda' }}</h2>
                    <p class="muted">{{ $stepsDone }} dari {{ count($steps) }} langkah selesai</p>
                </div>
                <span class="ring" style="--p: {{ (int) round($stepsDone / count($steps) * 100) }}">{{ (int) round($stepsDone / count($steps) * 100) }}%</span>
            </div>
            <ol class="steps">
                @foreach ($steps as $step)
                    <li class="{{ $step['done'] ? 'done' : '' }}">
                        <a href="{{ route($step['route']) }}">
                            <span class="tick">@if ($step['done'])@include('tenant.partials.icon', ['name' => 'check'])@else{{ $loop->iteration }}@endif</span>
                            <span class="txt"><strong>{{ $step['label'] }}</strong><span>{{ $step['hint'] }}</span></span>
                            @include('tenant.partials.icon', ['name' => 'arrow-right', 'class' => 'go-ic'])
                        </a>
                    </li>
                @endforeach
            </ol>
        </div>

        <div class="panel">
            <div class="panel-h">
                <div>
                    <h2>Percakapan terbaru</h2>
                    <p class="muted">Chat pelanggan lewat WhatsApp</p>
                </div>
                <a class="small-link" href="{{ route('app.conversations') }}">Lihat semua</a>
            </div>
            @forelse ($recent as $c)
                @php
                    // Sama dengan halaman Percakapan: nomor tanpa akhiran "@s.whatsapp.net".
                    $number = \Illuminate\Support\Str::before($c->customer_alt_id ?: $c->customer_external_id, '@');
                    // Nama profil WhatsApp bisa hanya simbol (mis. "~"): pakai nomornya saja.
                    $name = preg_match('/[\p{L}\p{N}]/u', (string) $c->customer_name) ? trim($c->customer_name) : null;
                    $who = $name ?: $number;
                    $at = $c->last_message_at->timezone($tenant->timezone ?: 'Asia/Jakarta')->locale('id');
                    $when = $at->isToday() ? $at->format('H:i') : ($at->isYesterday() ? 'Kemarin' : $at->translatedFormat('d M'));
                @endphp
                <a class="convo" href="{{ route('app.conversations') }}" wire:key="c{{ $c->id }}">
                    <span class="avatar soft">{{ $name ? \Illuminate\Support\Str::upper(mb_substr($name, 0, 1)) : '#' }}</span>
                    <span class="txt">
                        <strong>{{ $who }}@if ($name) <small class="num">{{ $number }}</small>@endif</strong>
                        <span>{{ \Illuminate\Support\Str::limit($c->last_body ?? '', 70) }}</span>
                    </span>
                    <time datetime="{{ $at->toIso8601String() }}" title="{{ $at->translatedFormat('d M Y H:i') }}">{{ $when }}</time>
                </a>
            @empty
                <div class="empty">
                    @include('tenant.partials.icon', ['name' => 'chat'])
                    <p><strong>Belum ada percakapan.</strong><br>Setelah WhatsApp terhubung, chat pelanggan akan muncul di sini.</p>
                </div>
            @endforelse
        </div>
    </section>
</div>
