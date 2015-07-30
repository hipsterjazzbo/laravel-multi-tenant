<?php namespace AuraIsHere\LaravelMultiTenant;

use Illuminate\Database\Eloquent\Builder;

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
	 * Anything with 'has' is already passed into 
	 *  the model's 'where' method
	 *
	 * @var string[]
	 */
	protected $shouldBeNested = ["whereraw", "orwhereraw", 
								 "wherebetween", "orwherebetween", "wherenotbeteen", "orwherenotbetween", 
								 "wherenested", "wheresub", "addnestedsubquery",
								 "whereexists", "orwhereexists", "wherenotexists", "orwherenotexists", 
								 "wherein", "orwherein", "wherenotin", "orwherenotin",
								 "whereinsub", "wherenull", "orwherenull", "wherenotnull", "orwherenotnull",
								 "wheredate", "whereday", "wheremonth", "whereyear", 
								 "dynamicwhere"
								];
	
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
		if(is_null($this->nestedWhere)) 
		{
			//Create a new query
			// and add it as a nested where
			$query = $this->model->newQueryWithoutScopes();

			call_user_func_array(array($query, 'where'), func_get_args());
			
			$this->query->addNestedWhereQuery($query->getQuery(), $boolean);
			$this->nestedWhere = $query;
		}
		else if ($column instanceof Closure)
		{
			$query = $this->model->newQueryWithoutScopes();
			
			call_user_func($column, $query);
			$this->nestedWhere->addNestedWhereQuery($query->getQuery(), $boolean);;
		}
		else
		{
			$query = $this->nestedWhere;
			call_user_func_array(array($query, 'where'), func_get_args());	
		}

		$this->query->setBindings($query->getQuery()->getBindings());

		return $this;
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
    		//Create a new query
			// and add it as a nested where
    		$query = $this->model->newQueryWithoutScopes();

			call_user_func_array(array($query, $method), $parameters); 

			$this->query->addNestedWhereQuery($query->getQuery());
			$this->nestedWhere = $query;
		}
		else call_user_func_array(array($this->nestedWhere, $method), $parameters); 

		$this->query->setBindings($this->nestedWhere->getQuery()->getBindings());

		return $this;
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
        elseif( in_array(strtolower($method), $this->shouldBeNested, true) ) 
        {
        	//Catch any where methods and pass them to the nested query
        	return $this->addToNestedQuery($method, $parameters);
		}

        $result = call_user_func_array([$this->query, $method], $parameters);

        return in_array($method, $this->passthru) ? $result : $this;
    }
}