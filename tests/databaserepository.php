<?php

namespace Test;

use Sensi\Supportery\DatabaseRepository;
use Monolyth\Disclosure\{ Container, Factory };
use Quibble\Sqlite\Adapter;
use Quibble\Query\Buildable;
use Gentry\Gentry\Wrapper;
use Generator;
use Ornament\Core;
use PDO;

class Repository extends DatabaseRepository
{
    public function all() : array
    {
        return $this->list($this->select());
    }
}

class Model
{
    use Core\Model;
    use Core\Persistable;

    public readonly int $id;

    public string $foo;
}

(new Container)->register(function (&$adapter) {
    $adapter = new class(':memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends Adapter {
        use Buildable;
    };
    $adapter->exec("
        CREATE TABLE test (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            foo TEXT NOT NULL
        )");
});

/** Tests for database repository */
return function () : Generator {
    $this->beforeEach(function () use (&$repository) {
        $adapter = (new Container)->get('adapter');
        $adapter->exec("DELETE FROM test");
        $adapter->exec("INSERT INTO test (id, foo) VALUES (1, 'bar'), (2, 'buzz')");
        $repository = new Wrapper(Factory::build(Repository::class));
       //$repository = Factory::build(Repository::class);
    });

    /** We can find all models */
    yield function () use (&$repository) {
        $all = $repository->all();
        assert(count($all) === 2);
    };

    /** We can find a single model by identifier */
    yield function () use (&$repository) {
        $single = $repository->findByIdentifier(1);
        assert($single instanceof Model);
        assert($single->foo === 'bar');
    };

    /** We can create and save a new model */
    yield function () use (&$repository) {
        $model = new Model;
        $model->foo = 'whatever';
        // Note: working with references in overloaded method calls is buggy,
        // hence we cannot test for isset($model->id) now; but it SHOULD be.
        // Instead, we test afterwards if we can retrieve it (id should be 2).
        $result = $repository->save($model);
        assert($result === null);
        $model = $repository->findByIdentifier(3);
        assert($model->id === 3);
        $all = $repository->all();
        assert(count($all) === 3);
    };

    /** We can delete an existing model */
    yield function () use (&$repository) {
        $model = $repository->findByIdentifier(1);
        assert($model instanceof Model);
        $result = $repository->delete($model);
        $model = $repository->findByIdentifier(1);
        assert($model === null);
    };
};

