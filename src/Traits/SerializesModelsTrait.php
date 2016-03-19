<?php
/**
 * Created by Asaduzzaman Pavel.
 * Date: 3/20/2016
 * Time: 12:41 AM
 */

namespace AuraIsHere\LaravelMultiTenant\Traits;


use AuraIsHere\LaravelMultiTenant\TenantScope;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Queue\SerializesModels;

trait SerializesModelsTrait
{
    use SerializesModels;

    protected function getRestoredPropertyValue($value)
    {
        if ($value instanceof ModelIdentifier) {
            /** @var TenantScope $tenantScope */
            $tenantScope = app(TenantScope::class);

            // Disable Tenant Scope
            $tenantScope->disable();

            $result = (new $value->class)->findOrFail($value->id);

            // Re-enable Tenant Scope
            $tenantScope->enable();

            return $result;
        } else {
            return $value;
        }
    }
}
