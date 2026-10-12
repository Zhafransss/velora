<div>
    <h1>Pengaturan Bot</h1>
    <p class="muted">Atur kepribadian dan cara chatbot menjawab pelanggan Anda.</p>

    @if (session('status'))<div class="flash">{{ session('status') }}</div>@endif
    @unless ($canEdit)<div class="lock">Anda hanya memiliki akses lihat.</div>@endunless

    <form class="card" wire:submit="save">
        <label for="name">Nama bot</label>
        <input id="name" wire:model="name" @disabled(! $canEdit)>
        @error('name')<div class="err">{{ $message }}</div>@enderror

        <label for="persona">Persona (siapa bot ini)</label>
        <textarea id="persona" wire:model="persona" rows="3" placeholder="mis. Admin toko yang ramah dan sabar membantu pelanggan" @disabled(! $canEdit)></textarea>
        @error('persona')<div class="err">{{ $message }}</div>@enderror

        <div class="row">
            <div>
                <label for="tone">Gaya bicara</label>
                <select id="tone" wire:model="tone" @disabled(! $canEdit)>
                    @foreach ($tones as $t)<option value="{{ $t }}">{{ ucfirst($t) }}</option>@endforeach
                </select>
                @error('tone')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="language">Bahasa</label>
                <select id="language" wire:model="language" @disabled(! $canEdit)>
                    @foreach ($languages as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                </select>
                @error('language')<div class="err">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" wire:model="status" @disabled(! $canEdit)>
                    <option value="draft">Draft (belum membalas pelanggan)</option>
                    <option value="active">Aktif</option>
                    <option value="paused">Dijeda</option>
                </select>
                @error('status')<div class="err">{{ $message }}</div>@enderror
            </div>
        </div>

        <label for="system_instructions">Instruksi tambahan</label>
        <textarea id="system_instructions" wire:model="system_instructions" rows="5" placeholder="mis. Selalu tawarkan produk terlaris. Jangan membahas politik." @disabled(! $canEdit)></textarea>
        <div class="muted">Instruksi ini tidak dapat mengubah aturan keamanan platform.</div>
        @error('system_instructions')<div class="err">{{ $message }}</div>@enderror

        <label for="welcome_message">Pesan pembuka</label>
        <textarea id="welcome_message" wire:model="welcome_message" rows="2" @disabled(! $canEdit)></textarea>
        @error('welcome_message')<div class="err">{{ $message }}</div>@enderror

        <label for="fallback_message">Pesan jika bot tidak bisa menjawab</label>
        <textarea id="fallback_message" wire:model="fallback_message" rows="2" @disabled(! $canEdit)></textarea>
        @error('fallback_message')<div class="err">{{ $message }}</div>@enderror

        <label for="uncertainty_rule">Aturan saat bot tidak yakin</label>
        <textarea id="uncertainty_rule" wire:model="uncertainty_rule" rows="3" @disabled(! $canEdit)></textarea>
        @error('uncertainty_rule')<div class="err">{{ $message }}</div>@enderror

        @if ($canEdit)<button class="btn block" type="submit">Simpan</button>@endif
    </form>
</div>
