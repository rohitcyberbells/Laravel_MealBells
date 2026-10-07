<?php

namespace App\Services\Hrms;

use App\Models\Company;
use App\Models\CompanyHrmsConnection;

/**
 * Single source of truth for a company's webhook credential.
 *
 * The database wins over config/hrms.php. Config remains as a fallback so a
 * local or demo setup can work from .env without a row, but production secrets
 * belong in the encrypted column, where adding a tenant or rotating a secret
 * needs no deploy.
 */
class HrmsConnectionResolver
{
    /**
     * Merged webhook settings, or null when the company has no usable secret.
     *
     * @return array<string, mixed>|null
     */
    public function webhookFor(Company $company): ?array
    {
        // The per-company config block is a convenience for a developer with no
        // database row - a secret in a deployed config file or env var is not
        // where a tenant's credential belongs, and silently falling back to one
        // in production would hide a missing row rather than surface it.
        $fromConfig = app()->environment(['local', 'testing'])
            ? config("hrms.companies.{$company->id}.webhook") ?? []
            : [];

        $webhook = array_merge(config('hrms.webhook_defaults', []), $fromConfig);

        $connection = CompanyHrmsConnection::where('company_id', $company->id)->first();

        if ($connection?->webhook_secret) {
            $webhook['secret'] = $connection->webhook_secret;
        }

        if ($connection?->auth) {
            $webhook['auth'] = $connection->auth;
        }

        return empty($webhook['secret']) ? null : $webhook;
    }

    public function hasSecret(Company $company): bool
    {
        return $this->webhookFor($company) !== null;
    }
}
