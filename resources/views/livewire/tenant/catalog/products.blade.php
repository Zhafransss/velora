<div>
    <h1>Produk</h1>
    <p class="muted">Harga dan stok dibaca chatbot dari sini. Kosongkan stok bila tidak diketahui — bot tidak akan menebak.</p>

    @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
    @unless ($canWrite)<div class="lock">Anda hanya memiliki akses lihat.</div>@endunless

    @if ($showForm)
        <form class="card" wire:submit="save">
            <h2>{{ $editingId ? 'Ubah produk' : 'Produk baru' }}</h2>

            <label for="name">Nama produk</label>
            <input id="name" wire:model="form.name">
            @error('form.name')<div class="err">{{ $message }}</div>@enderror

            <label for="sku">SKU (opsional, unik)</label>
            <input id="sku" wire:model="form.sku">
            @error('form.sku')<div class="err">{{ $message }}</div>@enderror

            <label for="description">Deskripsi</label>
            <textarea id="description" wire:model="form.description" rows="3"></textarea>
            @error('form.description')<div class="err">{{ $message }}</div>@enderror

            <label for="price">Harga (Rp) — kosong = belum ditentukan</label>
            <input id="price" wire:model="form.price" inputmode="decimal">
            @error('form.price')<div class="err">{{ $message }}</div>@enderror

            <div class="row">
                <div>
                    <label for="stock_quantity">Jumlah stok — kosong = tidak diketahui</label>
                    <input id="stock_quantity" wire:model="form.stock_quantity" inputmode="decimal">
                    @error('form.stock_quantity')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label for="stock_status">Status stok</label>
                    <select id="stock_status" wire:model="form.stock_status">
                        <option value="unknown">Tidak diketahui</option>
                        <option value="in_stock">Tersedia</option>
                        <option value="out_of_stock">Habis</option>
                    </select>
                    @error('form.stock_status')<div class="err">{{ $message }}</div>@enderror
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
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari produk…" style="max-width:280px">
            @if ($canWrite)<button class="btn" type="button" wire:click="create">Tambah produk</button>@endif
        </p>
    @endif

    <div class="card" style="overflow-x:auto">
        <table style="width:100%; border-collapse:collapse">
            <thead><tr style="text-align:left"><th>Nama</th><th>SKU</th><th>Harga</th><th>Stok</th><th>Status</th>@if ($canWrite)<th></th>@endif</tr></thead>
            <tbody>
            @forelse ($records as $p)
                <tr wire:key="p{{ $p->id }}" style="border-top:1px solid var(--line)">
                    <td>{{ $p->name }}</td>
                    <td>{{ $p->sku ?? '—' }}</td>
                    <td>{{ $p->price === null ? '—' : 'Rp ' . number_format((float) $p->price, 0, ',', '.') }}</td>
                    <td>
                        @if ($p->stock_quantity !== null){{ rtrim(rtrim($p->stock_quantity, '0'), '.') }}@endif
                        {{ ['in_stock' => 'Tersedia', 'out_of_stock' => 'Habis', 'unknown' => 'Tidak diketahui'][$p->stock_status] }}
                    </td>
                    <td><span class="badge {{ $p->is_active ? 'ok' : '' }}">{{ $p->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                    @if ($canWrite)
                        <td style="white-space:nowrap">
                            <button class="btn ghost" type="button" wire:click="edit({{ $p->id }})">Ubah</button>
                            <button class="btn ghost" type="button" wire:click="toggleActive({{ $p->id }})">{{ $p->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                            <button class="btn ghost" type="button" wire:click="delete({{ $p->id }})" wire:confirm="Hapus produk ini?">Hapus</button>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Belum ada produk.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
