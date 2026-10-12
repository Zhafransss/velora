<div>
    <h1>Tes Chatbot</h1>
    <p class="muted">Coba tanya seperti pelanggan Anda. <strong>Ini hanya uji coba — tidak ada pesan yang dikirim ke pelanggan.</strong>
        Setiap tes memakai kuota AI Anda (maks. {{ $limit }} tes per jam).</p>

    @if (! $canTest)
        <div class="lock">Hanya owner/admin yang dapat menjalankan tes.</div>
    @elseif (! $hasBot)
        <div class="lock">Anda belum menyimpan pengaturan bot. <a href="{{ route('app.bot') }}">Atur bot dulu</a>.</div>
    @else
        @if ($truncated)
            <div class="lock">Data bisnis Anda terlalu besar sehingga sebagian tidak dibaca bot. Nonaktifkan atau ringkas data yang jarang dipakai.</div>
        @endif

        <div class="card">
            @forelse ($turns as $turn)
                <p wire:key="t{{ $loop->index }}" style="margin:0 0 12px; {{ $turn['role'] === 'user' ? 'text-align:right' : '' }}">
                    <span class="badge {{ $turn['role'] === 'user' ? '' : 'ok' }}">{{ $turn['role'] === 'user' ? 'Pelanggan (Anda)' : 'Bot' }}</span><br>
                    {!! nl2br(e($turn['content'])) !!}
                </p>
            @empty
                <p class="muted">Belum ada percakapan. Ketik pertanyaan di bawah.</p>
            @endforelse

            @if ($error)<div class="err" style="margin-bottom:8px">{{ $error }}</div>@endif

            <form wire:submit="send" style="display:flex; gap:8px; flex-wrap:wrap">
                <input wire:model="message" placeholder="mis. Berapa harga kopi susu?" maxlength="1000" style="flex:1 1 260px" autocomplete="off">
                <button class="btn" type="submit" wire:loading.attr="disabled" wire:target="send">
                    <span wire:loading.remove wire:target="send">Kirim</span>
                    <span wire:loading wire:target="send">Memproses…</span>
                </button>
                @if ($turns !== [])
                    <button class="btn ghost" type="button" wire:click="newConversation">Mulai percakapan baru</button>
                @endif
            </form>
            @error('message')<div class="err">{{ $message }}</div>@enderror
        </div>
    @endif

    <h2>Riwayat tes terakhir</h2>
    <div class="card" style="overflow-x:auto">
        <table style="width:100%; border-collapse:collapse">
            <thead><tr style="text-align:left"><th>Waktu</th><th>Pertanyaan</th><th>Jawaban</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($recent as $r)
                <tr wire:key="r{{ $r->id }}" style="border-top:1px solid var(--line); vertical-align:top">
                    <td style="white-space:nowrap">{{ $r->created_at->format('d M H:i') }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($r->question, 80) }}</td>
                    <td>{{ $r->answer ? \Illuminate\Support\Str::limit($r->answer, 120) : '—' }}</td>
                    <td><span class="badge {{ $r->status === 'success' ? 'ok' : '' }}">{{ $r->status === 'success' ? 'Berhasil' : 'Gagal' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Belum ada tes.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
