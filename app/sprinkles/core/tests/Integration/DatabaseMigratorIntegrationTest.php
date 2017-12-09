<?php
/**
 * UserFrosting (http://www.userfrosting.com)
 *
 * @link      https://github.com/userfrosting/UserFrosting
 * @license   https://github.com/userfrosting/UserFrosting/blob/master/licenses/UserFrosting.md (MIT License)
 */
namespace UserFrosting\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use UserFrosting\Sprinkle\Core\Database\DatabaseMigrationRepository;
use UserFrosting\Sprinkle\Core\Database\MigrationLocator;
use UserFrosting\Sprinkle\Core\Database\Migrator;
use UserFrosting\Sprinkle\Core\Util\BadClassNameException;
use UserFrosting\Tests\TestCase;

class DatabaseMigratorIntegrationTest extends TestCase
{
    protected $schemaName = 'test_integration';

    protected $migrationTable = 'migrations';

    protected $schema;

    protected $migrator;

    protected $locator;

    protected $repository;

    /**
     * Bootstrap Eloquent.
     *
     * @return void
     */
    public function setUp()
    {
        // Boot parent TestCase, which will set up the database and connections for us.
        parent::setUp();

        // Boot database
        $db = $this->ci->db;

        // Get the schema instance
        $this->schema = $db->connection($this->schemaName)->getSchemaBuilder();

        // Get the repository instance. Set the correct database
        $this->repository = new DatabaseMigrationRepository($this->schema, $this->migrationTable);

        // Get the Locator
        $this->locator = new MigrationLocatorStub($this->ci->sprinkleManager, new Filesystem);

        // Get the migrator instance
        $this->migrator = new Migrator($this->repository, $this->schema, $this->locator);

        if (!$this->repository->repositoryExists()) {
            $this->repository->createRepository();
        }
    }

    /*public function tearDown()
    {
        m::close();
    }*/

    public function testMigrationRepositoryCreated()
    {
        $this->assertTrue($this->schema->hasTable($this->migrationTable));
    }

    public function testBasicMigration()
    {
        $ran = $this->migrator->run();

        $this->assertTrue($this->schema->hasTable('users'));
        $this->assertTrue($this->schema->hasTable('password_resets'));

        $this->assertEquals($this->locator->getMigrations(), $ran);
    }

    public function testRepository()
    {
        $ran = $this->migrator->run();

        // Theses assertions makes sure the repository and the migration returns the same format
        // N.B.: getLast return the migrations in reverse order (last ran first)
        $this->assertEquals($this->locator->getMigrations(), $ran);
        $this->assertEquals(array_reverse($this->locator->getMigrations()), $this->repository->getLast());
        $this->assertEquals($this->locator->getMigrations(), $this->repository->getRan());
    }

    public function testMigrationsCanBeRolledBack()
    {
        // Run up
        $this->migrator->run();
        $this->assertTrue($this->schema->hasTable('users'));
        $this->assertTrue($this->schema->hasTable('password_resets'));

        $rolledBack = $this->migrator->rollback();
        $this->assertFalse($this->schema->hasTable('users'));
        $this->assertFalse($this->schema->hasTable('password_resets'));

        // Make sure the data returned from migrator is accurate.
        // N.B.: The order returned by the rollback method is ordered by which
        // migration was rollbacked first (reversed from the order they where ran up)
        $this->assertEquals(array_reverse($this->locator->getMigrations()), $rolledBack);
    }

    public function testMigrationsCanBeReset()
    {
        // Run up
        $this->migrator->run();
        $this->assertTrue($this->schema->hasTable('users'));
        $this->assertTrue($this->schema->hasTable('password_resets'));

        $rolledBack = $this->migrator->reset();
        $this->assertFalse($this->schema->hasTable('users'));
        $this->assertFalse($this->schema->hasTable('password_resets'));

        // Make sure the data returned from migrator is accurate.
        $this->assertEquals(array_reverse($this->locator->getMigrations()), $rolledBack);
    }

    public function testNoErrorIsThrownWhenNoOutstandingMigrationsExist()
    {
        $this->migrator->run();
        $this->assertTrue($this->schema->hasTable('users'));
        $this->assertTrue($this->schema->hasTable('password_resets'));
        $this->migrator->run();
    }

    public function testNoErrorIsThrownWhenNothingToRollback()
    {
        $this->migrator->run();
        $this->assertTrue($this->schema->hasTable('users'));
        $this->assertTrue($this->schema->hasTable('password_resets'));
        $this->migrator->rollback();
        $this->assertFalse($this->schema->hasTable('users'));
        $this->assertFalse($this->schema->hasTable('password_resets'));
        $this->migrator->rollback();
    }

    public function testPretendUp()
    {
        $result = $this->migrator->run(['pretend' => true]);
        $notes = $this->migrator->getNotes();
        $this->assertFalse($this->schema->hasTable('users'));
        $this->assertFalse($this->schema->hasTable('password_resets'));
        $this->assertNotEquals([], $notes);
    }

    public function testPretendRollback()
    {
        // Run up as usual
        $result = $this->migrator->run();
        $this->assertTrue($this->schema->hasTable('users'));
        $this->assertTrue($this->schema->hasTable('password_resets'));

        $rolledBack = $this->migrator->rollback(['pretend' => true]);
        $this->assertTrue($this->schema->hasTable('users'));
        $this->assertTrue($this->schema->hasTable('password_resets'));
        $this->assertEquals(array_reverse($this->locator->getMigrations()), $rolledBack);
    }

    public function testChangeRepositoryAndDeprecatedClass()
    {
        // Change the repository so we can test with the DeprecatedStub
        $locator = new DeprecatedMigrationLocatorStub($this->ci->sprinkleManager, new Filesystem);
        $this->migrator->setLocator($locator);

        // Run up
        $this->migrator->run();
        $this->assertTrue($this->schema->hasTable('deprecated_table'));

        // Rollback
        $this->migrator->rollback();
        $this->assertFalse($this->schema->hasTable('deprecated_table'));
    }

    public function testWithInvalidClass()
    {
        // Change the repository so we can test with the InvalidMigrationLocatorStub
        $locator = new InvalidMigrationLocatorStub($this->ci->sprinkleManager, new Filesystem);
        $this->migrator->setLocator($locator);

        // Expect a `BadClassNameException` exception
        $this->expectException(BadClassNameException::class);

        // Run up
        $this->migrator->run();
    }

    /**
     *    !TODO :
     *    - Test unfulfillable migrations
     */
}

class MigrationLocatorStub extends MigrationLocator {
    public function getMigrations()
    {
        return [
            '\\UserFrosting\\Tests\\Integration\\Migrations\\one\\CreateUsersTable',
            '\\UserFrosting\\Tests\\Integration\\Migrations\\one\\CreatePasswordResetsTable'
        ];
    }
}

/**
 *    This stub contain migration which file doesn't exists
 */
class InvalidMigrationLocatorStub extends MigrationLocator {
    public function getMigrations()
    {
        return [
            '\\UserFrosting\\Tests\\Integration\\Migrations\\Foo'
        ];
    }
}

/**
 *    This stub contain migration which order they need to be run is different
 *    than the order the file are returned because of dependencies management
 */
class DeprecatedMigrationLocatorStub extends MigrationLocator {
    public function getMigrations()
    {
        return [
            '\\UserFrosting\\Tests\\Integration\\Migrations\\DeprecatedClassTable'
        ];
    }
}
