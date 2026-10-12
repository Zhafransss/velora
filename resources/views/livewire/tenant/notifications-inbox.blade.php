<div wire:poll.15s>
    <h1>Notifikasi</h1>
    <p class="muted">Hal-hal yang perlu perhatian Anda, mis. chatbot tidak dapat membalas pelanggan. Notifikasi lama dihapus otomatis setelah {{ $retentionDays }} hari.</p>

    <p style="display:flex; gap:8px; flex-wrap:wrap; align-items:center">
        <label style="margin:0; font-weight:400"><input type="checkbox" wire:model.live="onlyUnread" style="width:auto"> Hanya yang belum dibaca</label>
        @if ($canManage && $unread > 0)
            <button class="btn ghost" type="button" wire:click="markAllRead">Tandai semua dibaca ({{ $unread }})</button>
        @endif
    </p>

    @forelse ($notifications as $n)
        @php
            $color = ['critical' => 'var(--bad)', 'warning' => 'var(--warn)', 'info' => 'var(--brand)'][$n->severity] ?? 'var(--muted)';
            $label = ['critical' => 'Penting', 'warning' => 'Perhatian', 'info' => 'Info'][$n->severity] ?? $n->severity;
        @endphp
        <div class="card" wire:key="n{{ $n->id }}" style="border-left:4px solid {{ $color }}; {{ $n->isRead() ? 'opacity:.7' : '' }}">
            <p style="margin:0 0 4px">
                <span class="badge" style="color:{{ $color }}">{{ $label }}</span>
                <strong>{{ $n->title }}</strong>
                @if ($n->occurrences > 1)<span class="muted">({{ $n->occurrences }} kali)</span>@endif
                @unless ($n->isRead())<span class="badge ok">Baru</span>@endunless
            </p>
            @if ($n->body)<p style="margin:0 0 8px">{{ $n->body }}</p>@endif
            <p class="muted" style="margin:0">
                {{ ($n->last_occurred_at ?? $n->created_at)->diffForHumans() }}
                @if ($n->action_route && \Illuminate\Support\Facades\Route::has($n->action_route))
                    · <a href="{{ route($n->action_route) }}">Buka</a>
                @endif
                @if ($canManage && ! $n->isRead())
                    · <a href="#" wire:click.prevent="markRead({{ $n->id }})">Tandai dibaca</a>
                @endif
            </p>
        </div>
    @empty
        <div class="card"><p class="muted" style="margin:0">Tidak ada notifikasi{{ $onlyUnread ? ' yang belum dibaca' : '' }}. Semua berjalan normal.</p></div>
    @endforelse
</div>
