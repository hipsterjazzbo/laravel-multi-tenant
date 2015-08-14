<?php

use Mockery as m;
use AuraIsHere\LaravelMultiTenant\TenantQueryBuilder;

class TenantQueryBuilderTest extends PHPUnit_Framework_TestCase
{
	public function tearDown()
    {
        m::close();
    }

    /** 
     * Test whether:
     * - The first 'where' call creates a nested query builder, 
     * - both the first and any subsequent 'where' calls are 
     *		passed into this nested builder
	 * - Any query bindings are also set on the original query
     */
	public function testSimpleWhere()
    {
    	/* Expectation */
		$nestedRawQuery = $this->getMockQueryBuilder();
		$nestedRawQuery->shouldReceive('getBindings')->twice()->andReturn(['type' => 'value']);

		$nestedQuery = m::mock('Illuminate\Database\Eloquent\Builder');
		$nestedQuery->shouldReceive('getQuery')->times(3)->andReturn($nestedRawQuery);
		$nestedQuery->shouldReceive('where')->once()->with('foo', '=', 'bar');
		$nestedQuery->shouldReceive('where')->once()->with('foo', '<>', 'bar');

		$model = $this->getMockModel()->makePartial();
		$model->shouldReceive('newQueryWithoutScopes')->once()->andReturn($nestedQuery);
		
		$builder = $this->getBuilder();
		$builder->getQuery()->shouldReceive('from');
		$builder->setModel($model);

        $builder->getQuery()->shouldReceive('addNestedWhereQuery')->once()->with($nestedRawQuery, 'and');
        $builder->getQuery()->shouldReceive('setBindings')->twice()->with(['type' => 'value']);

        /* Execution */
        $result1 = $builder->where('foo', '=', 'bar');
        $result2 = $builder->where('foo', '<>', 'bar');

        /** Assertion */
        $this->assertEquals($result1, $builder);
        $this->assertEquals($result2, $builder);
    }

    protected function getBuilder()
    {
        return new TenantQueryBuilder($this->getMockQueryBuilder());
    }

    protected function getMockModel()
	{
		$model = m::mock('Illuminate\Database\Eloquent\Model');
		$model->shouldReceive('getKeyName')->andReturn('foo');
		$model->shouldReceive('getTable')->andReturn('foo_table');
		$model->shouldReceive('getQualifiedKeyName')->andReturn('foo_table.foo');
		return $model;
	}

    protected function getMockQueryBuilder()
    {
        $query = m::mock('Illuminate\Database\Query\Builder');
        $query->shouldReceive('from')->with('foo_table');
        return $query;
    }
}