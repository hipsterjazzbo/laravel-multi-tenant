<?php

use Mockery as m;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use AuraIsHere\LaravelMultiTenant\TenantScope;
use AuraIsHere\LaravelMultiTenant\Traits\TenantScopedModelTrait;

class TenantSeperationTest extends PHPUnit_Framework_TestCase
{
	/** 
	 * Test shows the original issue where 'or where' clauses are not nested
	 * and test whether it is resolved (output sql matches reference query)
	 */
	public function testRealSeperationQuery()
	{
		$tenantScope = new TenantScope;

		//Mock facades
		App::shouldReceive('make')->once()->andReturn($tenantScope);
		Config::shouldReceive('get')->with('laravel-multi-tenant::default_tenant_columns')->andReturn(['tenant_id']);

		$model = new EloquentBuilderTestSeperationStub;
		$this->mockConnectionForModel($model, 'SQLite');

		//Set tenant
		$tenantScope->addTenant('tenant_id', 1);

		//Reference query
		$nestedQuery = $model->allTenants()
							 ->whereRaw("table.tenant_id = '1'")
							 ->where(function($subq) {
							 	$subq->where('foo', '=', 2)
							 		 ->orWhere('bar', '=', 3);
							 });

		//Query to be tested
		$tenantQuery = $model->where('foo', '=', 2)->orWhere('bar', '=', 3);

		$this->assertEquals([2, 3], $tenantQuery->getBindings());
		$this->assertEquals($nestedQuery->toSql(), $tenantQuery->toSql());
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
	
	use TenantScopedModelTrait;
}