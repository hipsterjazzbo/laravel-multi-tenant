<?php

use Mockery as m;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use AuraIsHere\LaravelMultiTenant\TenantScope;
use AuraIsHere\LaravelMultiTenant\Traits\tenantScopedModelTrait;

class TenantSeperationTest extends PHPUnit_Framework_TestCase
{
	protected $model;
	protected $tenantScope;

	public function setUp()
    {
		$this->tenantScope = new TenantScope;

		//Mock facades
		App::shouldReceive('make')->once()->andReturn($this->tenantScope);
		Config::shouldReceive('get')->with('laravel-multi-tenant::default_tenant_columns')->andReturn(['tenant_id']);

		$this->model = new EloquentBuilderTestSeperationStub;
		$this->mockConnectionForModel($this->model, 'SQLite');

		//Set tenant
		$this->tenantScope->addTenant('tenant_id', 1);
	}

	public function tearDown()
    {
        m::close();
    }

	/** 
	 * Test a simple query in which no nesting should occur
	 */
	public function testSimpleQuery()
	{
		//Reference query
		$nestedQuery = $this->model->allTenants()->whereRaw("table.tenant_id = '1'");

		//Query to be tested
		$tenantQuery = $this->model->getQuery();

 		$this->assertEquals($nestedQuery->getBindings(), $tenantQuery->getBindings());
		$this->assertEquals($nestedQuery->toSql(), $tenantQuery->toSql());	
	}

	/** 
	 * Test shows the original issue in which 'or where' clauses are not nested
	 * and tests whether it is resolved (meaning: output sql matches a reference query)
	 */
	public function testSeperationQuery()
	{
		//Reference query
		$nestedQuery = $this->model->allTenants()->whereRaw("table.tenant_id = '1'");
		$nestedQuery = $this->getTestOuterQuery($nestedQuery);
		$nestedQuery->where(function($subq) { 
			$this->getTestSubQuery($subq);
		});

		//Query to be tested
		$tenantQuery = $this->getTestOuterQuery($this->model->newQuery());
		$tenantQuery = $this->getTestSubQuery($tenantQuery);

 		$this->assertEquals($nestedQuery->getBindings(), $tenantQuery->getBindings());
		$this->assertEquals($nestedQuery->toSql(), $tenantQuery->toSql());
	}

	/** 
	 * Test whether builder with multiple global scopes produces
	 *  correctly nested queries
	 */
	public function testGlobalScopeSeperationQuery()
	{
		$globalScopeModel = new EloquentBuilderTestGlobalScopeStub;
		$globalScopeModel::addGlobalScope(new GlobalScopeStub);
		$this->mockConnectionForModel($globalScopeModel, 'SQLite');

		//Reference query
		$nestedQuery = $this->model
							->allTenants()
							->whereRaw("table.tenant_id = '1'")
							->where(function($iq1) {
								$iq1->whereRaw('"table"."deleted_at" is null');
							})
							->where(function($iq2) {
								$iq2->where('baz', '<>', 1)
									->orWhere('foo', '=', 2);
							});

		//Query to be tested
		$tenantQuery = $globalScopeModel->getQuery();

 		$this->assertEquals($nestedQuery->getBindings(), $tenantQuery->getBindings());
		$this->assertEquals($nestedQuery->toSql(), $tenantQuery->toSql());	
	}

	/** 
	 * A query showcasing many clauses 
	 */
	protected function getTestSubQuery($base)
	{
		return $base->where('foo', '=', 2)
					->orWhere('bar', '=', 3)
					->orWhereBetween('baz', [4, 5])
					->orWhereNotNull('quux')
					->whereBazOrBar(6, 7)	//dynamic where
					->orWhere(function($query)
            		{
                		$query->where('wibble', '=', 11)
                      		  ->where('wobble', '<>', 12);
            		})
            		->orWhereExists(function($query)
		            {
		                $query->select('id')
		                      ->from('wobbles')
		                      ->whereRaw('wobbles.wibble_id = wibbles.id');
		            });
	}

	/** 
	 * A query showcasing many clauses 
	 */
	protected function getTestOuterQuery($base)
	{
		return $base->join('baztable', 'footable.id', '=', 'baztable.foo_id')
					->leftJoin('quuxtable', 'baz.id', '=', 'quuxtable.baz_id')
					->orderBy('quux', 'desc')
                    ->groupBy('flbr')
                    ->having('fblr', '>', 8)
					->distinct()
					->select('bar')
					->addSelect('foo')
					->skip(9)
					->take(10);
	}

	protected function mockConnectionForModel($model, $database)
	{
		$grammarClass = 'Illuminate\Database\Query\Grammars\\'.$database.'Grammar';
		$processorClass = 'Illuminate\Database\Query\Processors\\'.$database.'Processor';
		$grammar = new $grammarClass;
		$processor = new $processorClass;

		$connection = m::mock('Illuminate\Database\ConnectionInterface', array('getQueryGrammar' => $grammar, 'getPostProcessor' => $processor));
		$resolver = m::mock('Illuminate\Database\ConnectionResolverInterface', array('connection' => $connection));
		
		$class = get_class($model);
		$class::setConnectionResolver($resolver);
	}

}

/** Stub for a tenant scoped model */
class EloquentBuilderTestSeperationStub extends Illuminate\Database\Eloquent\Model 
{
	protected $table = 'table';
	
	use tenantScopedModelTrait;
}

class GlobalScopeStub implements Illuminate\Database\Eloquent\ScopeInterface
{
	public function apply(Illuminate\Database\Eloquent\Builder $builder) 
	{
		$model = $builder->getModel();
		$builder->where('baz', '<>', 1)->orWhere('foo', '=', 2);
	}

	public function remove(Illuminate\Database\Eloquent\Builder $builder) {}
}

/** Stub for a model with multiple global scopes*/
class EloquentBuilderTestGlobalScopeStub extends Illuminate\Database\Eloquent\Model 
{
	protected $table = 'table';
	
	use tenantScopedModelTrait;
	use Illuminate\Database\Eloquent\SoftDeletingTrait;
}