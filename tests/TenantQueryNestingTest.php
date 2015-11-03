<?php

use AuraIsHere\LaravelMultiTenant\TenantScope;
use AuraIsHere\LaravelMultiTenant\Traits\TenantScopedModelTrait;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Mockery as m;

class TenantQueryNestingTest extends PHPUnit_Framework_TestCase
{
    protected $model;
    protected $tenantScope;

    public function setUp()
    {
        $this->tenantScope = new TenantScope();

        //Mock facades
        App::shouldReceive('make')->once()->andReturn($this->tenantScope);
        Config::shouldReceive('get')->with('tenant.default_tenant_columns')->andReturn(['tenant_id']);

        $this->model = new EloquentBuilderTestNestingStub();
        $this->mockConnectionForModel($this->model, 'SQLite');

        //Set tenant
        $this->tenantScope->addTenant('tenant_id', 1);
    }

    public function tearDown()
    {
        m::close();
    }

    /** 
     * Test a simple query in which no nesting should occur.
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
     * and tests whether it is resolved (meaning: output sql matches a reference query).
     */
    public function testNestingQuery()
    {
        //Reference query
        $nestedQuery = $this->model->allTenants()->whereRaw("table.tenant_id = '1'");
        $nestedQuery = $this->getTestOuterQuery($nestedQuery);
        $nestedQuery->where(function ($subq) {
            $this->getTestSubQuery($subq);
        });

        //Query to be tested
        $tenantQuery = $this->getTestOuterQuery($this->model->newQuery());
        $tenantQuery = $this->getTestSubQuery($tenantQuery);

        $this->assertEquals($nestedQuery->getQuery()->getRawBindings(), $tenantQuery->getQuery()->getRawBindings());
        $this->assertEquals($nestedQuery->toSql(), $tenantQuery->toSql());
    }

    /** 
     * Tested seperately due to relation queries now using hash
     * as temporary table names, so can only verify string lengths
     * and bindings match.
     */
    public function testWhereHasNestingQuery()
    {
        //Reference query
        $nestedQuery = $this->model->allTenants()->whereRaw("table.tenant_id = '1'");
        $nestedQuery = $this->getTestOuterQuery($nestedQuery);
        $nestedQuery->where(function ($subq) {
            $this->getTestHasQuery($subq);
        });

        //Query to be tested
        $tenantQuery = $this->getTestOuterQuery($this->model->newQuery());
        $tenantQuery = $this->getTestHasQuery($tenantQuery);

        $this->assertEquals($nestedQuery->getQuery()->getRawBindings(), $tenantQuery->getQuery()->getRawBindings());
        $this->assertEquals(strlen($nestedQuery->toSql()), strlen($tenantQuery->toSql()));
    }

    /** 
     * Test whether builder with multiple global scopes produces
     *  correctly nested queries.
     */
    public function testGlobalScopeNestingQuery()
    {
        $globalScopeModel = new EloquentBuilderTestGlobalScopeStub();
        $globalScopeModel::addGlobalScope(new GlobalScopeStub());
        $this->mockConnectionForModel($globalScopeModel, 'SQLite');

        //Reference query
        $nestedQuery = $this->model
                            ->allTenants()
                            ->whereRaw("table.tenant_id = '1'")
                            ->where(function ($iq1) {
                                $iq1->whereRaw('"table"."deleted_at" is null');
                            })
                            ->where(function ($iq2) {
                                $iq2->where('baz', '<>', 1)
                                    ->orWhere('foo', '=', 2);
                            });

        //Query to be tested
        $tenantQuery = $globalScopeModel->newQuery();

        $this->assertEquals($nestedQuery->getQuery()->getRawBindings(), $tenantQuery->getQuery()->getRawBindings());
        $this->assertEquals($nestedQuery->toSql(), $tenantQuery->toSql());
    }

    public function testSoftDeletingMacrosAreSet()
    {
        $globalScopeModel = new EloquentBuilderTestGlobalScopeStub();
        $tenantQuery = $globalScopeModel->newQuery();

        $this->assertEquals($tenantQuery, $tenantQuery->withTrashed());
    }

    /** 
     * A query showcasing many clauses.
     */
    protected function getTestSubQuery($base)
    {
        return $base->where('foo', '=', 2)
                    ->orWhere('bar', '=', 3)
                    ->orWhereBetween('baz', [4, 5])
                    ->orWhereNotNull('quux')
                    ->whereBazOrBar(6, 7)    //dynamic where
                    ->orWhere(function ($query) {
                        $query->where('wibble', '=', 11)
                                ->where('wobble', '<>', 12);
                    })
                    ->orWhereExists(function ($query) {
                        $query->select('id')
                              ->from('wobbles')
                              ->whereRaw('wobbles.wibble_id = wibbles.id');
                    });
    }

    /** 
     * A query showcasing whereHas clauses.
     */
    protected function getTestHasQuery($base)
    {
        return $base->whereHas('selfRelation', function ($query) {
                        $query->whereFlubOrFlob(13, 14)
                              ->orWhereNull('plugh');
                    });
    }

    /** 
     * A query showcasing many clauses.
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
        $grammar = new $grammarClass();
        $processor = new $processorClass();

        $connection = m::mock('Illuminate\Database\ConnectionInterface', ['getQueryGrammar' => $grammar, 'getPostProcessor' => $processor]);
        $resolver = m::mock('Illuminate\Database\ConnectionResolverInterface', ['connection' => $connection]);

        $class = get_class($model);
        $class::setConnectionResolver($resolver);
    }
}

/** Stub for a tenant scoped model */
class EloquentBuilderTestNestingStub extends Illuminate\Database\Eloquent\Model
{
    protected $table = 'table';

    public function selfRelation()
    {
        return $this->hasMany('EloquentBuilderTestNestingStub');
    }

    use TenantScopedModelTrait;
}

class GlobalScopeStub implements Illuminate\Database\Eloquent\ScopeInterface
{
    public function apply(Illuminate\Database\Eloquent\Builder $builder, Illuminate\Database\Eloquent\Model $model)
    {
        $model = $builder->getModel();
        $builder->where('baz', '<>', 1)->orWhere('foo', '=', 2);
    }

    public function remove(Illuminate\Database\Eloquent\Builder $builder, Illuminate\Database\Eloquent\Model $model)
    {
    }
}

/** Stub for a model with multiple global scopes*/
class EloquentBuilderTestGlobalScopeStub extends Illuminate\Database\Eloquent\Model
{
    protected $table = 'table';

    use tenantScopedModelTrait;
    use Illuminate\Database\Eloquent\SoftDeletes;
}
