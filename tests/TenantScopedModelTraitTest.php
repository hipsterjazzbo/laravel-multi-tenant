<?php

use AuraIsHere\LaravelMultiTenant\Traits\TenantScopedModelTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mockery as m;

class TenantScopedModelTraitTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        m::close();
    }

    public function testAllTenants()
    {
        // Not sure how to write this test
    }

    public function testGetTenantColumns()
    {
        // This one either
    }

    public function testGetTenantWhereClause()
    {
        $model = m::mock('TenantScopedModelStub');
        $model->shouldDeferMissing();

        $whereClause = $model->getTenantWhereClause('column', 1);

        $this->assertEquals("table.column = '1'", $whereClause);
    }

    /**
     * @expectedException \AuraIsHere\LaravelMultiTenant\Exceptions\TenantModelNotFoundException
     */
    public function testFindOrFailThrowsTenantException()
    {
        TenantScopedModelStub::findOrFail(1, []);
    }

    public function testNewQueryReturnsTenantQueryBuilder()
    {
        $conn = m::mock('Illuminate\Database\Connection');
        $grammar = m::mock('Illuminate\Database\Query\Grammars\Grammar');
        $processor = m::mock('Illuminate\Database\Query\Processors\Processor');

        $conn->shouldReceive('getQueryGrammar')->twice()->andReturn($grammar);
        $conn->shouldReceive('getPostProcessor')->twice()->andReturn($processor);
        TenantScopedModelStub::setConnectionResolver($resolver = m::mock('Illuminate\Database\ConnectionResolverInterface'));
        $resolver->shouldReceive('connection')->andReturn($conn);

        $model = new TenantScopedModelStub();
        $builder = $model->newQuery();

        $this->assertInstanceOf('AuraIsHere\LaravelMultiTenant\TenantQueryBuilder', $builder);
    }
}

class TenantScopedModelStub extends ParentModel
{
    use TenantScopedModelTrait;

    public function getTable()
    {
        return 'table';
    }
}

class ParentModel extends Model
{
    public static function findOrFail($id, $columns)
    {
        throw new ModelNotFoundException();
    }

    public static function query()
    {
        throw new ModelNotFoundException();
    }
}
