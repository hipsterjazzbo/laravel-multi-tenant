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
			$query = $this->model->newQueryWithoutScopes();

			call_user_func_array(array($query, 'where'), func_get_args());
			
			$this->query->addNestedWhereQuery($query->getQuery(), $boolean);
			$this->nestedWhere = $query;
		}
		else if ($column instanceof Closure)
		{
			$query = $this->model->newQueryWithoutScopes();
			
			call_user_func($column, $query);
			$this->nestedWhere->addNestedWhereQuery($query->getQuery(), $boolean);

			$this->query->setBindings($query->getQuery()->getBindings());
		}
		else
		{
			$query = $this->nestedWhere;
			call_user_func_array(array($query, 'where'), func_get_args());
			
			$this->query->setBindings($query->getQuery()->getBindings());
		}
		return $this;
	}
}