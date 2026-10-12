<div wire:poll.{{ $pollSeconds }}s>
    <h1>WhatsApp</h1>
    <p class="muted">Hubungkan nomor WhatsApp bisnis Anda agar chatbot dapat membalas pelanggan.</p>

    @unless ($canManage)<div class="lock">Anda hanya memiliki akses lihat. Hanya owner/admin yang dapat mengelola koneksi.</div>@endunless
    @if ($error)<div class="lock">{{ $error }}</div>@endif

    @if ($channel === null)
        <div class="card">
            @if (! $hasBot)
                <div class="lock">Anda belum menyimpan pengaturan bot. <a href="{{ route('app.bot') }}">Atur bot dulu</a>, lalu kembali ke sini.</div>
            @elseif ($canManage)
                <h2>Buat kanal WhatsApp</h2>
                <form wire:submit="createChannel">
                    <label for="name">Nama kanal</label>
                    <input id="name" wire:model="name" maxlength="100">
                    @error('name')<div class="err">{{ $message }}</div>@enderror
                    <button class="btn block" type="submit">Buat kanal</button>
                </form>
            @else
                <p class="muted">Belum ada kanal WhatsApp.</p>
            @endif
        </div>
    @else
        @if ($channel->bot && $channel->bot->status !== 'active')
            <div class="lock">
                Bot masih berstatus <strong>{{ $channel->bot->status }}</strong>, sehingga <strong>belum membalas pelanggan</strong>.
                <a href="{{ route('app.bot') }}">Ubah status bot menjadi Aktif</a>.
            </div>
        @endif

        <div class="card">
            <h2>{{ $channel->name }}</h2>
            <p>
                Status:
                <span class="badge {{ $channel->status === 'connected' ? 'ok' : '' }}">{{ $labels[$channel->status] ?? $channel->status }}</span>
            </p>

            @if ($channel->status === 'connected' && $channel->external_account_id)
                <p class="muted">Akun tertaut: {{ \Illuminate\Support\Str::before($channel->external_account_id, '@') }}</p>
            @endif

            @if ($qrImage)
                <div style="text-align:center; margin:16px 0">
                    <img src="{{ $qrImage }}" alt="QR WhatsApp" width="280" height="280" style="max-width:100%; height:auto; background:#fff; padding:8px; border:1px solid var(--line); border-radius:8px">
                    <ol style="text-align:left; display:inline-block; margin-top:12px">
                        <li>Buka WhatsApp di ponsel Anda.</li>
                        <li>Ketuk <strong>Perangkat tertaut</strong> → <strong>Tautkan perangkat</strong>.</li>
                        <li>Arahkan kamera ke QR di atas.</li>
                    </ol>
                    <p class="muted">QR berganti otomatis dan hanya berlaku sebentar. Jangan bagikan QR ini kepada siapa pun.</p>
                </div>
            @elseif ($channel->status === 'qr' && $canManage)
                <p class="muted">Menyiapkan QR…</p>
            @endif

            @if ($canManage)
                <p style="display:flex; gap:8px; flex-wrap:wrap; margin-top:16px">
                    @if ($channel->status !== 'connected')
                        <button class="btn" type="button" wire:click="connect" wire:loading.attr="disabled" wire:target="connect">
                            <span wire:loading.remove wire:target="connect">Hubungkan</span>
                            <span wire:loading wire:target="connect">Memproses…</span>
                        </button>
                    @endif
                    @if (in_array($channel->status, ['connected', 'qr', 'reconnecting', 'starting', 'qr_expired'], true))
                        <button class="btn ghost" type="button" wire:click="disconnect"
                                wire:confirm="Putuskan WhatsApp? Perangkat akan ditautkan-lepas dan chatbot berhenti membalas sampai dihubungkan lagi.">
                            Putuskan
                        </button>
                    @endif
                </p>
            @endif
        </div>

        <p class="muted">Lihat percakapan yang masuk di menu <a href="{{ route('app.conversations') }}">Percakapan</a>.</p>
    @endif
</div>
