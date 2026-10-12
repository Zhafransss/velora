<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Pencatatan langganan manual setelah pembayaran diterima (DEC-07).
 * Sementara pengganti UI admin Filament (Tahap 4).
 */
class ActivateSubscription extends Command
{
    protected $signature = 'subscription:activate
        {tenant : Slug atau ID workspace}
        {plan=small : small|medium|enterprise}
        {--days=30 : Lama periode dalam hari}
        {--notes= : Catatan pembayaran}';

    protected $description = 'Aktifkan langganan sebuah workspace (penagihan manual).';

    public function handle(): int
    {
        $key = $this->argument('tenant');
        $tenant = Tenant::where('slug', $key)->orWhere('id', is_numeric($key) ? $key : 0)->first();

        if (! $tenant) {
            $this->error("Workspace '{$key}' tidak ditemukan.");

            return self::FAILURE;
        }

        if (! in_array($plan = $this->argument('plan'), ['small', 'medium', 'enterprise'], true)) {
            $this->error('Plan harus small, medium, atau enterprise.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));

        $subscription = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_code' => $plan,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays($days),
            'notes' => $this->option('notes') ?: null,
        ]);

        $this->info("Langganan {$plan} aktif untuk '{$tenant->name}' sampai {$subscription->current_period_end->format('d M Y')}.");

        return self::SUCCESS;
    }
}
