<?php

namespace App\Support\Tenancy;

use RuntimeException;

class TenancyException extends RuntimeException
{
    public static function missingContext(string $model): self
    {
        return new self("Cannot create {$model} without a tenant context.");
    }

    public static function crossTenantWrite(string $model): self
    {
        return new self("Refusing to write {$model} for a tenant other than the current tenant.");
    }

    public static function tenantIdImmutable(string $model): self
    {
        return new self("tenant_id of {$model} cannot be changed after creation.");
    }
}
