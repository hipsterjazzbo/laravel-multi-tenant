<?php namespace AuraIsHere\LaravelMultiTenant\Traits;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\App;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use AuraIsHere\LaravelMultiTenant\TenantScope;
use AuraIsHere\LaravelMultiTenant\TenantQueryBuilder;
use AuraIsHere\LaravelMultiTenant\Facades\TenantScopeFacade;
use AuraIsHere\LaravelMultiTenant\Exceptions\TenantModelNotFoundException;

/**
 * Class TenantScopedModelTrait
 *
 * @package AuraIsHere\LaravelMultiTenant
 *
 * @method static void addGlobalScope(\Illuminate\Database\Eloquent\ScopeInterface $scope)
 * @method static void creating(callable $callback)
 */
trait TenantScopedModelTrait {

	public static function bootTenantScopedModelTrait()
	{
		$tenantScope = App::make("AuraIsHere\LaravelMultiTenant\TenantScope");

		// Add the global scope that will handle all operations except create()
		static::addGlobalScope($tenantScope);

		// Add an observer that will automatically add the tenant id when create()-ing
		static::creating(function (Model $model) use ($tenantScope)
		{
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
		return with(new static)->newQueryWithoutScope(new TenantScope);
	}

	/**
	 * Get the name of the "tenant id" column.
	 *
	 * @return string
	 */
	public function getTenantColumns()
	{
		return isset($this->tenantColumns) ? $this->tenantColumns : Config::get('laravel-multi-tenant::default_tenant_columns');
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
	public static function findOrFail($id, $columns = array('*'))
	{
		try
		{
			return parent::findOrFail($id, $columns);
		}

		catch (ModelNotFoundException $e)
		{
			throw with(new TenantModelNotFoundException)->setModel(get_called_class());
		}
	}

	/**
     * Get a new query builder with nested where for the model's table. 
     *
     * @return AuraIsHere\LaravelMultiTenant\TenantQueryBuilder
     */
    public function newTenantQuery()
    {
        $tenant_builder = $this->newTenantQueryWithoutScopes();

        //Create a normal query first, allowing the (interfaced) 
        // scope to use the whereRaw from the non-overridden
        // Eloquent\Query
        $tenant_builder->setQuery($this->newQuery()->getQuery());

        return $tenant_builder;
    }

    /**
     * Get a new query builder with nested where
     * that doesn't have any global scopes.
     *
     * @return \Illuminate\Database\Eloquent\Builder|static
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
     * @param  \Illuminate\Database\Query\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder|static
     */
    public function newEloquentTenantBuilder($query)
    {
        return new TenantQueryBuilder($query);
    }

 	/**
     * Handle dynamic method calls into the model.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (in_array($method, ['increment', 'decrement'])) {
            return call_user_func_array([$this, $method], $parameters);
        }
        
        $query = $this->newTenantQuery();
        return call_user_func_array([$query, $method], $parameters);
    }
} 
