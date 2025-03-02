<?php

namespace Test;

use Sensi\Supportery\DatabaseRepository;
use Monolyth\Disclosure\{ Container, Factory };
use Quibble\Sqlite\Adapter;
use Quibble\Query\Buildable;
use Gentry\Gentry\Wrapper;
use Generator;
use Ornament\Core;

(new Container)->register(function (&$adapter) {
    $adapter = new class(':memory:') extends Adapter {
        use Buildable;
    };
    $adapter->query("
        CREATE TABLE test (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            foo TEXT NOT NULL
        )");
    $adapter->query("INSERT INTO test (foo) VALUES ('bar'), ('buzz')");
});

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

/** Tests for database repository */
return function () : Generator {
    $this->beforeEach(function () use (&$repository) {
       $repository = new Wrapper(Factory::build(Repository::class));
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
        $model = $repository->findByIdentifier(2);
        assert($model->id === 2);
        $all = $repository->all();
        assert(count($all) === 2);
    };

    /** We can delete an existing model */
    yield function () use (&$repository) {
        $model = $repository->findByIdentifier(1);
        assert($model instanceof Model);
        $repository->delete($model);
        $model = $repository->findByIdentifier(1);
        assert($model === null);
    };
};

