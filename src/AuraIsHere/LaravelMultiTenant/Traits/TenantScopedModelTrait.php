<?php

namespace AuraIsHere\LaravelMultiTenant\Traits;

use AuraIsHere\LaravelMultiTenant\Contracts\LoftyScope;
use AuraIsHere\LaravelMultiTenant\Exceptions\TenantModelNotFoundException;
use AuraIsHere\LaravelMultiTenant\TenantQueryBuilder;
use AuraIsHere\LaravelMultiTenant\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

/**
 * Class TenantScopedModelTrait.
 *
 *
 * @method static void addGlobalScope(\Illuminate\Database\Eloquent\ScopeInterface $scope)
 * @method static void creating(callable $callback)
 */
trait TenantScopedModelTrait
{
    public static function bootTenantScopedModelTrait()
    {
        $tenantScope = App::make('AuraIsHere\LaravelMultiTenant\TenantScope');

        // Add the global scope that will handle all operations except create()
        static::addGlobalScope($tenantScope);

        // Add an observer that will automatically add the tenant id when create()-ing
        static::creating(function (Model $model) use ($tenantScope) {
            $tenantScope->creating($model);
        });
    }

    /**
     * Returns a new builder without the tenant scope applied.
     *
     *     $allUsers = User::allTenants()->get();
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function allTenants()
    {
        return with(new static())->newOriginalQueryWithoutScope(new TenantScope());
    }

    /**
     * Get the name of the "tenant id" column.
     *
     * @return string
     */
    public function getTenantColumns()
    {
        return isset($this->tenantColumns) ? $this->tenantColumns : Config::get('tenant.default_tenant_columns');
    }

    /**
     * Prepare a raw where clause. Do it this way instead of using where()
     * to avoid issues with bindings when removing.
     *
     * @param $tenantColumn
     * @param $tenantId
     *
     * @return string
     */
    public function getTenantWhereClause($tenantColumn, $tenantId)
    {
        return "{$this->getTable()}.{$tenantColumn} = '{$tenantId}'";
    }

    /**
     * Override the default findOrFail method so that we can rethrow a more useful exception.
     * Otherwise it can be very confusing why queries don't work because of tenant scoping issues.
     *
     * @param       $id
     * @param array $columns
     *
     * @throws TenantModelNotFoundException
     */
    public static function findOrFail($id, $columns = ['*'])
    {
        try {
            return parent::query()->findOrFail($id, $columns);
        } catch (ModelNotFoundException $e) {
            throw with(new TenantModelNotFoundException())->setModel(get_called_class());
        }
    }

    /**
     * Get a new query builder with nested where for the model's table. 
     *
     * @return AuraIsHere\LaravelMultiTenant\TenantQueryBuilder
     */
    public function newQuery()
    {
        $tenant_builder = $this->newTenantQueryWithoutScopes();

        //Create a normal query first, allowing the (interfaced)
        // scope to use the whereRaw from the non-overridden
        // Eloquent\Query
        $tenant_builder->setQuery($this->newOriginalQuery()->getQuery());

        return $tenant_builder;
    }

    /**
     * Get a new query builder for the model's table.
     *  without the nesting behaviour.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newOriginalQuery()
    {
        $builder = $this->newQueryWithoutScopes();

        return $this->applyGlobalScopes($builder);
    }

    /**
     * Get a new query instance without a given scope.
     *  and without nesting behaviour.
     *
     * @param \Illuminate\Database\Eloquent\ScopeInterface $scope
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newOriginalQueryWithoutScope($scope)
    {
        $this->getGlobalScope($scope)->remove($builder = $this->newOriginalQuery(), $this);

        return $builder;
    }

    /**
     * Get a new query builder with nested where
     *  without global scopes.
     *
     * @return AuraIsHere\LaravelMultiTenant\TenantQueryBuilder|static
     */
    public function newTenantQueryWithoutScopes()
    {
        $builder = $this->newEloquentTenantBuilder(
            $this->newBaseQueryBuilder()
        );
        // Once we have the query builders, we will set the model instances so the
        // builder can easily access any information it may need from the model
        // while it is constructing and executing various queries against it.
        return $builder->setModel($this)->with($this->with);
    }

    /**
     * Create a new Eloquent Tenant query builder for the model.
     *
     * @param \Illuminate\Database\Query\Builder $query
     *
     * @return AuraIsHere\LaravelMultiTenant\TenantQueryBuilder|static
     */
    public function newEloquentTenantBuilder($query)
    {
        return new TenantQueryBuilder($query);
    }

    /**
     * Apply all of the global scopes to an Eloquent builder
     *  or it's nested.
     *
     * @param \Illuminate\Database\Eloquent\Builder $builder
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function applyGlobalScopes($builder)
    {
        foreach ($this->getGlobalScopes() as $scope) {
            if ($scope instanceof LoftyScope) {
                $scope->apply($builder, $this);
            } else {
                $nestable = $this->newQueryWithoutScopes();
                $scope->apply($nestable, $this);
                $builder->addNestedWhereQuery($nestable->getQuery());
            }
        }

        return $builder;
    }

    /**
     * Handle dynamic method calls into the model.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (in_array($method, ['increment', 'decrement'])) {
            return call_user_func_array([$this, $method], $parameters);
        }

        $query = $this->newQuery();

        return call_user_func_array([$query, $method], $parameters);
    }
}
