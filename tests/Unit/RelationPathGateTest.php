<?php

namespace PatrykSawicki\Helper\Tests\Unit;

use PatrykSawicki\Helper\Tests\Fixtures\Gate;
use PatrykSawicki\Helper\Tests\Fixtures\Owner;
use PatrykSawicki\Helper\Tests\Fixtures\OwnerWithUninitializedSortable;
use PatrykSawicki\Helper\Tests\Fixtures\User;
use PatrykSawicki\Helper\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The relation allow-list from 0.7.14, 0.7.15 and 0.7.16.
 *
 * The column name is attacker-controlled, and resolving a name as a relation calls the method it
 * names. Only a method whose declared return type proves it is a relation, or one the model opts
 * in through $sortableRelations, may be invoked.
 */
class RelationPathGateTest extends TestCase
{
    private Gate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new Gate();
    }

    #[Test]
    public function a_declared_relation_passes(): void
    {
        $this->assertTrue($this->gate->call('isSortableRelation', new Owner(), 'files'));
        $this->assertTrue($this->gate->call('isAllowedRelationPath', new Owner(), 'files'));
    }

    #[Test]
    public function a_method_that_is_no_relation_is_refused_and_never_called(): void
    {
        $this->assertFalse($this->gate->call('isSortableRelation', new Owner(), 'dangerousMethod'));
        $this->assertFalse($this->gate->call('isAllowedRelationPath', new Owner(), 'dangerousMethod'));
        $this->assertFalse(Owner::$dangerousMethodRan);
    }

    #[Test]
    public function methods_contributed_by_the_framework_are_refused(): void
    {
        // The framework's own guard only covers methods declared on Model itself, which is why
        // 0.7.15 had to widen this: save() and friends are reachable by name otherwise.
        foreach (['save', 'push', 'delete', 'refresh', 'toArray'] as $method) {
            $this->assertFalse(
                $this->gate->call('isSortableRelation', new Owner(), $method),
                "{$method}() must not be invokable through a column name"
            );
        }
    }

    #[Test]
    public function a_name_that_exists_on_no_model_is_refused(): void
    {
        $this->assertFalse($this->gate->call('isAllowedRelationPath', new Owner(), 'nothingHere'));
        $this->assertFalse($this->gate->call('isAllowedRelationPath', null, 'files'));
        $this->assertFalse($this->gate->call('isAllowedRelationPath', new Owner(), ''));
    }

    #[Test]
    public function a_data_get_wildcard_names_no_column(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'hash']);

        foreach (['*', '{first}', '{last}', 'name.*'] as $path) {
            $this->assertFalse(
                $this->gate->call('isReadableColumnPath', $user, $path),
                "{$path} must not be treated as a column"
            );
        }
    }

    #[Test]
    public function filter_relation_paths_drops_what_the_gate_refuses(): void
    {
        $paths = ['files', 'dangerousMethod', 'nothingHere', 'files.model'];

        $this->assertSame(
            ['files', 'files.model'],
            $this->gate->call('filterRelationPaths', new Owner(), $paths)
        );
    }

    #[Test]
    public function an_uninitialized_opt_in_list_does_not_kill_the_request(): void
    {
        // The same guard declaredSearchableColumns() got in 0.7.17 - the older sibling had been
        // left without it, so a model declaring `public array $sortableRelations;` answered every
        // table request with an uncaught Error.
        $model = new OwnerWithUninitializedSortable();

        $this->assertSame([], $this->gate->call('declaredSortableRelations', $model));
        $this->assertTrue($this->gate->call('isAllowedRelationPath', $model, 'files'));
    }
}
