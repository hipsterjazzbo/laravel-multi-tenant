<?php namespace AuraIsHere\LaravelMultiTenant;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Relations\Relation;

class TenantQueryBuilder extends Builder
{
	/**
	 * Nested query for all wheres except the global scopes
	 * @var \Illuminate\Database\Eloquent\Builder
	 */
	protected $nestedWhere = null;

	/**
	 * These methods should be passed into the nested Where query
	 * 
	 * Anything starting with 'where' is already passed to handle dynamicWhere
	 * Anything with 'has' is already passed into the model's 'where' method
	 *
	 * @var string[]
	 */
	protected $shouldBeNested = ["orwhereraw", "orwherebetween", "orwherenotbetween", "addnestedsubquery", 
								 "orwhereexists", "orwherenotexists", "orwherein", "orwherenotin",
								 "orwherenull","orwherenotnull", "dynamicwhere"
								];							

	/**
	 * Set a model instance for the model being queried.
	 *
	 * @param  \Illuminate\Database\Eloquent\Model  $model
	 * @return $this
	 */
	public function setModel(Model $model)
	{
		$this->model = $model;
		$this->query->from($model->getTable());

		//Add soft deleting macro's to this builder
		if(method_exists($this->model, 'bootSoftDeletes')) {
			$scope = new SoftDeletingScope;
			$scope->extend($this);
		}

		return $this;
	}

	/**
	 * Add a basic where clause to the nested query.
	 *
	 * @param  string  $column
	 * @param  string  $operator
	 * @param  mixed   $value
	 * @param  string  $boolean
	 * @return $this
	 */
	public function where($column, $operator = null, $value = null, $boolean = 'and')
	{
		return $this->addToNestedQuery('where', [$column, $operator, $value, $boolean]);
	}

	/**
     * Handle dynamic calls which should go into the nested where query
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return $this
     */
    protected function addToNestedQuery($method, $parameters)
    {
    	if(is_null($this->nestedWhere)) 
    	{
    		//Create a new (non-tenant) query builder
    		$query = $this->model->newQueryWithoutScopes();

    		//Add any existing where bindings
    		$query->getQuery()->setBindings(['where' => $this->query->getRawBindings()['where']] );

			call_user_func_array(array($query, $method), $parameters); 

			//Add this new query as a nested where
			$this->query->addNestedWhereQuery($query->getQuery());
			$this->nestedWhere = $query;
			
		}
		else call_user_func_array(array($this->nestedWhere, $method), $parameters); 

		$this->query->setBindings($this->nestedWhere->getQuery()->getBindings());

		return $this;
	}

	/**
     * Merge the "wheres" from a relation query to a has query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $hasQuery
     * @param  \Illuminate\Database\Eloquent\Relations\Relation  $relation
     * @return void
     */
    protected function mergeWheresToHas(Builder $hasQuery, Relation $relation)
    {
        // Here we have the "has" query and the original relation. We need to copy over any
        // where clauses the developer may have put in the relationship function over to
        // the has query, and then copy the bindings from the "has" query to the main.
        $relationQuery = $relation->getBaseQuery();
        $hasQuery = $hasQuery->getModel()->removeGlobalScopes($hasQuery);
        $hasQuery->mergeWheres(
            $relationQuery->wheres, $relationQuery->getBindings()
        );

        if(!is_null($this->nestedWhere)) {
            $this->nestedWhere->mergeBindings($hasQuery->getQuery());
        }
        else {
            $this->query->mergeBindings($hasQuery->getQuery());
        }
    }

	 /**
     * Dynamically handle calls into the query instance.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (isset($this->macros[$method])) {
            array_unshift($parameters, $this);

            return call_user_func_array($this->macros[$method], $parameters);
        } 
        elseif (method_exists($this->model, $scope = 'scope'.ucfirst($method))) 
        {
            return $this->callScope($scope, $parameters);
        } 
        elseif( in_array(strtolower($method), $this->shouldBeNested, true) ||
        		Str::startsWith($method, 'where')) 
        {
        	//Catch any nestable methods and pass them to the nested query
        	return $this->addToNestedQuery($method, $parameters);
		}

        $result = call_user_func_array([$this->query, $method], $parameters);

        return in_array($method, $this->passthru) ? $result : $this;
    }
}