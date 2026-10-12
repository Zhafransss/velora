<div>
    <h1>Profil Bisnis</h1>
    <p class="muted">Informasi ini dipakai chatbot untuk menjawab pelanggan Anda.</p>

    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif

    @unless ($canEdit)
        <div class="lock">Anda hanya memiliki akses lihat. Hanya owner/admin yang dapat mengubah profil.</div>
    @endunless

    <form class="card" wire:submit="save">
        <label for="workspace_name">Nama bisnis</label>
        <input id="workspace_name" wire:model="workspace_name" @disabled(! $canEdit)>
        @error('workspace_name')<div class="err">{{ $message }}</div>@enderror

        <label for="business_type">Jenis usaha</label>
        <input id="business_type" wire:model="business_type" placeholder="mis. kuliner, fashion, jasa" @disabled(! $canEdit)>
        @error('business_type')<div class="err">{{ $message }}</div>@enderror

        <label for="description">Deskripsi</label>
        <textarea id="description" wire:model="description" rows="4" @disabled(! $canEdit)></textarea>
        @error('description')<div class="err">{{ $message }}</div>@enderror

        <label for="phone">Telepon / WhatsApp</label>
        <input id="phone" wire:model="phone" @disabled(! $canEdit)>
        @error('phone')<div class="err">{{ $message }}</div>@enderror

        <label for="address">Alamat</label>
        <textarea id="address" wire:model="address" rows="2" @disabled(! $canEdit)></textarea>
        @error('address')<div class="err">{{ $message }}</div>@enderror

        <label for="opening_hours">Jam operasional</label>
        <input id="opening_hours" wire:model="opening_hours" placeholder="mis. Senin–Sabtu 09.00–17.00" @disabled(! $canEdit)>
        @error('opening_hours')<div class="err">{{ $message }}</div>@enderror

        <label for="timezone">Zona waktu</label>
        <select id="timezone" wire:model="timezone" @disabled(! $canEdit)>
            @foreach ($timezones as $tz)
                <option value="{{ $tz }}">{{ $tz }}</option>
            @endforeach
        </select>
        @error('timezone')<div class="err">{{ $message }}</div>@enderror

        @if ($canEdit)
            <button class="btn block" type="submit">Simpan</button>
        @endif
    </form>
</div>
