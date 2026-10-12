<div>
    <h1>FAQ</h1>
    <p class="muted">Pertanyaan yang sering diajukan pelanggan beserta jawaban resmi Anda. Chatbot menjawab berdasarkan ini.</p>

    @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
    @unless ($canWrite)<div class="lock">Anda hanya memiliki akses lihat.</div>@endunless

    @if ($showForm)
        <form class="card" wire:submit="save">
            <h2>{{ $editingId ? 'Ubah FAQ' : 'FAQ baru' }}</h2>

            <label for="question">Pertanyaan</label>
            <textarea id="question" wire:model="form.question" rows="2"></textarea>
            @error('form.question')<div class="err">{{ $message }}</div>@enderror

            <label for="answer">Jawaban</label>
            <textarea id="answer" wire:model="form.answer" rows="4"></textarea>
            @error('form.answer')<div class="err">{{ $message }}</div>@enderror

            <label for="category">Kategori (opsional)</label>
            <input id="category" wire:model="form.category" placeholder="mis. pengiriman, pembayaran">
            @error('form.category')<div class="err">{{ $message }}</div>@enderror

            <label style="font-weight:400"><input type="checkbox" wire:model="form.is_active" style="width:auto"> Aktif (boleh dijawab chatbot)</label>

            <p style="margin-top:18px">
                <button class="btn" type="submit">Simpan</button>
                <button class="btn ghost" type="button" wire:click="cancel">Batal</button>
            </p>
        </form>
    @else
        <p style="display:flex; gap:8px; flex-wrap:wrap">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari FAQ…" style="max-width:280px">
            @if ($canWrite)<button class="btn" type="button" wire:click="create">Tambah FAQ</button>@endif
        </p>
    @endif

    <div class="card" style="overflow-x:auto">
        <table style="width:100%; border-collapse:collapse">
            <thead><tr style="text-align:left"><th>Pertanyaan</th><th>Kategori</th><th>Status</th>@if ($canWrite)<th></th>@endif</tr></thead>
            <tbody>
            @forelse ($records as $f)
                <tr wire:key="f{{ $f->id }}" style="border-top:1px solid var(--line)">
                    <td>{{ \Illuminate\Support\Str::limit($f->question, 90) }}</td>
                    <td>{{ $f->category ?? '—' }}</td>
                    <td><span class="badge {{ $f->is_active ? 'ok' : '' }}">{{ $f->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    @if ($canWrite)
                        <td style="white-space:nowrap">
                            <button class="btn ghost" type="button" wire:click="edit({{ $f->id }})">Ubah</button>
                            <button class="btn ghost" type="button" wire:click="toggleActive({{ $f->id }})">{{ $f->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                            <button class="btn ghost" type="button" wire:click="delete({{ $f->id }})" wire:confirm="Hapus FAQ ini?">Hapus</button>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Belum ada FAQ.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
