<?php

namespace App\Console\Commands;

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

        if (! in_array($plan = $this->argument('plan'), \App\Services\Admin\SubscriptionService::planCodes(), true)) {
            $this->error('Plan harus salah satu dari: ' . implode(', ', \App\Services\Admin\SubscriptionService::planCodes()) . '.');

            return self::FAILURE;
        }

        try {
            $subscription = app(\App\Services\Admin\SubscriptionService::class)->activate(
                $tenant, $plan, max(1, (int) $this->option('days')), $this->option('notes') ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Langganan {$plan} aktif untuk '{$tenant->name}' sampai {$subscription->current_period_end->format('d M Y')}.");

        return self::SUCCESS;
    }
}
