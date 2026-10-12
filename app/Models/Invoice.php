<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    /**
     * Metode pembayaran. Nomor rekening menyusul — isi di sini setelah tersedia.
     */
    public const PAYMENT_METHODS = [
        'mandiri' => ['label' => 'Bank Mandiri', 'account_number' => 1360033500049, 'account_name' => 'FACHRULLAH ZHAFRAN L'],
        'bca' => ['label' => 'Bank BCA', 'account_number' => 5835092345, 'account_name' => 'FACHRULLAH ZHAFRAN L'],
    ];

    protected $fillable = [
        'invoice_number',
        'invoice_date',
        'customer_name',
        'business_name',
        'address',
        'whatsapp',
        'items',
        'payment_method',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'items' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            $invoice->invoice_number ??= static::nextNumber();
        });
    }

    /** Format INV-YYYYMM-0001, urut per bulan. */
    public static function nextNumber(): string
    {
        $prefix = 'INV-' . now()->format('Ym') . '-';
        $last = static::where('invoice_number', 'like', $prefix . '%')->max('invoice_number');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public function getTotalAttribute(): float
    {
        return collect($this->items)->sum(fn ($i) => (float) ($i['qty'] ?? 0) * (float) ($i['price'] ?? 0));
    }

    public function getPaymentMethodDetailAttribute(): array
    {
        return self::PAYMENT_METHODS[$this->payment_method] ?? ['label' => $this->payment_method, 'account_number' => null, 'account_name' => null];
    }
}
