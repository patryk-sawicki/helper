<?php

namespace PatrykSawicki\Helper\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;
use PatrykSawicki\Helper\Tests\Fixtures\OtherOwner;
use PatrykSawicki\Helper\Tests\Fixtures\Owner;

/**
 * Base case for the package's own tests.
 *
 * The tables live here rather than in migrations because every test needs the same three and
 * nothing else: an owner, a file pointing at it through a morph, and a user with a hidden column.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The fixtures record whether their bodies ran, and that state is static - reset here
        // rather than in each test class, so a class added later cannot forget to.
        Owner::$dangerousMethodRan = false;
        OtherOwner::$filesRan = false;

        Schema::create('helper_owners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('user_id')->nullable();
        });

        Schema::create('helper_files', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->nullableMorphs('model');
        });

        Schema::create('helper_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('remember_token')->nullable();
            $table->string('private_note')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('helper_files');
        Schema::dropIfExists('helper_owners');
        Schema::dropIfExists('helper_users');

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
