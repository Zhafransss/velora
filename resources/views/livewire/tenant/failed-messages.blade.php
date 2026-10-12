<div wire:poll.15s>
    <h1>Pesan Gagal</h1>
    <p class="muted">Pesan pelanggan yang belum terjawab karena kendala teknis dalam {{ $windowHours }} jam terakhir. Anda bisa mengirim ulang setelah WhatsApp terhubung. Yang lebih lama tidak ditampilkan karena sudah tidak relevan untuk dibalas.</p>

    @if ($notice)
        <div class="flash" style="{{ $noticeType === 'error' ? 'border-color:var(--bad)' : '' }}">{{ $notice }}</div>
    @endif

    @forelse ($messages as $m)
        @php
            $isOut = $m->direction === \App\Models\Message::OUT;
            $question = $isOut ? $m->inReplyTo?->body : $m->body;
            $ambiguous = \App\Livewire\Tenant\FailedMessages::isAmbiguous($m);
        @endphp
        <div class="card" wire:key="f{{ $m->id }}" style="border-left:4px solid var(--warn)">
            <p style="margin:0 0 4px">
                <strong>{{ \App\Livewire\Tenant\ConversationHistory::customerLabel($m->conversation) }}</strong>
                <span class="badge">{{ $isOut ? 'Balasan belum terkirim' : 'Pertanyaan belum terjawab' }}</span>
                <span class="muted">{{ $m->created_at->diffForHumans() }}</span>
            </p>
            @if ($question)
                <p style="margin:0 0 4px"><span class="muted">Pertanyaan:</span> {{ \Illuminate\Support\Str::limit($question, 200) }}</p>
            @endif
            @if ($isOut)
                <p style="margin:0 0 4px"><span class="muted">Balasan:</span> {{ \Illuminate\Support\Str::limit($m->body, 200) }}</p>
            @endif
            <p style="margin:0 0 8px"><span class="muted">Penyebab:</span> {{ \App\Livewire\Tenant\FailedMessages::reason($m) }}</p>

            @if ($canManage)
                <button class="btn" type="button" wire:click="retry({{ $m->id }})" wire:loading.attr="disabled"
                    @if ($ambiguous) wire:confirm="Pesan ini mungkin SUDAH sampai ke pelanggan. Jika dikirim ulang, pelanggan bisa menerima pesan yang sama dua kali. Tetap kirim ulang?" @endif>
                    {{ $isOut ? 'Kirim ulang balasan' : 'Proses ulang & balas' }}
                </button>
                @if ($ambiguous)<span class="muted">Berisiko terkirim ganda.</span>@endif
            @else
                <span class="muted">Hanya pemilik atau admin workspace yang dapat mengirim ulang.</span>
            @endif
        </div>
    @empty
        <div class="card"><p class="muted" style="margin:0">Tidak ada pesan gagal. Semua pesan pelanggan tertangani.</p></div>
    @endforelse
</div>
