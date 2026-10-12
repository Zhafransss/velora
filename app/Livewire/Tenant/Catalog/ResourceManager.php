<?php

namespace App\Livewire\Tenant\Catalog;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * Dasar CRUD data knowledge base tenant (FR-005).
 *
 * Keamanan: record SELALU dicari lewat model ber-BelongsToTenant, jadi id milik tenant lain
 * (mis. editingId dimanipulasi klien) menghasilkan 404, bukan akses lintas tenant.
 * Data tenant kecil (UMKM), jadi daftar dibatasi LIMIT tanpa paginasi.
 */
abstract class ResourceManager extends Component
{
    private const LIST_LIMIT = 200;

    public string $search = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public array $form = [];

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** Aturan validasi dengan prefix `form.` */
    abstract protected function formRules(): array;

    abstract protected function defaults(): array;

    /** @return list<string> kolom yang dicari oleh kotak pencarian */
    abstract protected function searchColumns(): array;

    abstract protected function viewName(): string;

    abstract protected function title(): string;

    /** Hook: bersihkan/ubah tipe data hasil validasi sebelum disimpan. */
    protected function normalize(array $data): array
    {
        return $data;
    }

    public function mount(): void
    {
        $this->form = $this->defaults();
    }

    public function create(): void
    {
        $this->authorizeWrite();
        $this->resetValidation();
        $this->editingId = null;
        $this->form = $this->defaults();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeWrite();
        $this->resetValidation();

        $record = $this->modelClass()::findOrFail($id);

        $this->editingId = $record->id;
        $this->form = array_merge($this->defaults(), array_intersect_key($record->attributesToArray(), $this->defaults()));
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizeWrite();

        $data = $this->normalize($this->validate($this->formRules())['form']);

        if ($this->editingId) {
            $this->modelClass()::findOrFail($this->editingId)->update($data);
        } else {
            $this->modelClass()::create($data);
        }

        $this->cancel();
        session()->flash('status', 'Data tersimpan.');
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeWrite();

        $record = $this->modelClass()::findOrFail($id);
        $record->update(['is_active' => ! $record->is_active]);
    }

    public function delete(int $id): void
    {
        $this->authorizeWrite();

        $this->modelClass()::findOrFail($id)->delete(); // soft delete
        session()->flash('status', 'Data dihapus.');
    }

    public function cancel(): void
    {
        $this->resetValidation();
        $this->showForm = false;
        $this->editingId = null;
        $this->form = $this->defaults();
    }

    protected function authorizeWrite(): void
    {
        abort_unless(app(CurrentTenant::class)->userCanWrite(auth()->user()), 403);
    }

    public function render()
    {
        $query = $this->modelClass()::query()->latest('id');

        if (trim($this->search) !== '') {
            $term = '%' . addcslashes(trim($this->search), '%_\\') . '%';
            $query->where(function ($q) use ($term) {
                foreach ($this->searchColumns() as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }

        return view($this->viewName(), [
            'records' => $query->limit(self::LIST_LIMIT)->get(),
            'canWrite' => app(CurrentTenant::class)->userCanWrite(auth()->user()),
        ])->layout('tenant.layouts.app', ['title' => $this->title()]);
    }
}
