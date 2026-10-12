<div wire:poll.5s>
    <h1>Percakapan</h1>
    <p class="muted">Pesan dari pelanggan Anda dan balasan chatbot. Daftar menampilkan {{ $conversations->count() }} percakapan terbaru.
        Percakapan dihapus otomatis dari sistem setelah {{ $retentionDays }} hari.</p>

    @if ($historyDays !== null)
        <div class="lock">
            Paket <strong>{{ $planLabel }}</strong> menampilkan riwayat <strong>{{ $historyDays }} hari terakhir</strong>.
            Percakapan lebih lama tetap tersimpan sampai dihapus otomatis, tetapi baru dapat dilihat dengan paket yang mencakup riwayat penuh.
            <a href="{{ route('app.subscription') }}">Lihat paket</a>.
        </div>
    @endif

    <div class="row">
        <div class="card" style="flex:1 1 260px; max-height:70vh; overflow:auto">
            <h2>Pelanggan</h2>
            @forelse ($conversations as $c)
                <p wire:key="c{{ $c->id }}" style="margin:0; padding:8px 0; border-top:1px solid var(--line)">
                    <a href="#" wire:click.prevent="select({{ $c->id }})" style="{{ $current && $current->id === $c->id ? 'font-weight:700' : '' }}">
                        {{ \App\Livewire\Tenant\ConversationHistory::customerLabel($c) }}
                    </a><br>
                    <span class="muted">{{ $c->last_message_at?->diffForHumans() ?? 'belum ada pesan' }}</span>
                </p>
            @empty
                <p class="muted">Belum ada percakapan. Percakapan muncul saat pelanggan mengirim pesan ke nomor WhatsApp yang terhubung.</p>
            @endforelse
        </div>

        <div class="card" style="flex:2 1 380px; max-height:70vh; overflow:auto">
            @if ($current)
                <h2>{{ \App\Livewire\Tenant\ConversationHistory::customerLabel($current) }}</h2>
                @forelse ($messages as $m)
                    <div wire:key="m{{ $m->id }}" style="margin:0 0 12px; {{ $m->direction === 'inbound' ? '' : 'text-align:right' }}">
                        <span class="badge {{ $m->direction === 'inbound' ? '' : 'ok' }}">
                            {{ $m->direction === 'inbound' ? 'Pelanggan' : ($m->source === 'fallback' ? 'Bot (pesan cadangan)' : 'Bot') }}
                        </span>
                        <span class="muted">{{ $m->created_at->format('d M H:i') }}</span><br>
                        {!! nl2br(e($m->body)) !!}<br>
                        <span class="muted">{{ \App\Livewire\Tenant\ConversationHistory::statusLabel($m) }}</span>
                    </div>
                @empty
                    <p class="muted">Belum ada pesan.</p>
                @endforelse
            @else
                <p class="muted">Pilih pelanggan di sebelah kiri untuk melihat percakapan.</p>
            @endif
        </div>
    </div>
</div>
