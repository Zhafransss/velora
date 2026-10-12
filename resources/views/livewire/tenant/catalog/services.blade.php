<div>
    <h1>Layanan</h1>
    <p class="muted">Layanan yang Anda tawarkan. Isi rentang harga bila harga bervariasi.</p>

    @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
    @unless ($canWrite)<div class="lock">Anda hanya memiliki akses lihat.</div>@endunless

    @if ($showForm)
        <form class="card" wire:submit="save">
            <h2>{{ $editingId ? 'Ubah layanan' : 'Layanan baru' }}</h2>

            <label for="name">Nama layanan</label>
            <input id="name" wire:model="form.name">
            @error('form.name')<div class="err">{{ $message }}</div>@enderror

            <label for="description">Deskripsi</label>
            <textarea id="description" wire:model="form.description" rows="3"></textarea>
            @error('form.description')<div class="err">{{ $message }}</div>@enderror

            <div class="row">
                <div>
                    <label for="price_from">Harga mulai dari (Rp)</label>
                    <input id="price_from" wire:model="form.price_from" inputmode="decimal">
                    @error('form.price_from')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label for="price_to">Sampai (Rp)</label>
                    <input id="price_to" wire:model="form.price_to" inputmode="decimal">
                    @error('form.price_to')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label for="duration_minutes">Durasi (menit)</label>
                    <input id="duration_minutes" wire:model="form.duration_minutes" inputmode="numeric">
                    @error('form.duration_minutes')<div class="err">{{ $message }}</div>@enderror
                </div>
            </div>

            <label style="font-weight:400"><input type="checkbox" wire:model="form.is_active" style="width:auto"> Aktif (boleh dijawab chatbot)</label>

            <p style="margin-top:18px">
                <button class="btn" type="submit">Simpan</button>
                <button class="btn ghost" type="button" wire:click="cancel">Batal</button>
            </p>
        </form>
    @else
        <p style="display:flex; gap:8px; flex-wrap:wrap">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari layanan…" style="max-width:280px">
            @if ($canWrite)<button class="btn" type="button" wire:click="create">Tambah layanan</button>@endif
        </p>
    @endif

    <div class="card" style="overflow-x:auto">
        <table style="width:100%; border-collapse:collapse">
            <thead><tr style="text-align:left"><th>Nama</th><th>Harga</th><th>Durasi</th><th>Status</th>@if ($canWrite)<th></th>@endif</tr></thead>
            <tbody>
            @forelse ($records as $s)
                @php $fmt = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
                <tr wire:key="s{{ $s->id }}" style="border-top:1px solid var(--line)">
                    <td>{{ $s->name }}</td>
                    <td>
                        @if ($s->price_from !== null && $s->price_to !== null && $s->price_from !== $s->price_to)
                            {{ $fmt($s->price_from) }} – {{ $fmt($s->price_to) }}
                        @elseif ($s->price_from !== null)
                            {{ $fmt($s->price_from) }}
                        @elseif ($s->price_to !== null)
                            hingga {{ $fmt($s->price_to) }}
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $s->duration_minutes ? $s->duration_minutes . ' mnt' : '—' }}</td>
                    <td><span class="badge {{ $s->is_active ? 'ok' : '' }}">{{ $s->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    @if ($canWrite)
                        <td style="white-space:nowrap">
                            <button class="btn ghost" type="button" wire:click="edit({{ $s->id }})">Ubah</button>
                            <button class="btn ghost" type="button" wire:click="toggleActive({{ $s->id }})">{{ $s->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                            <button class="btn ghost" type="button" wire:click="delete({{ $s->id }})" wire:confirm="Hapus layanan ini?">Hapus</button>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Belum ada layanan.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
