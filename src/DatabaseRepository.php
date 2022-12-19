<?php

namespace Sensi\Supportery;

use Monolyth\Disclosure\Depends;
use Quibble\Dabble\Adapter;
use Quibble\Query\{ Select, SelectException, InsertException, UpdateException, DeleteException };
use ReflectionObject, ReflectionProperty;
use PDO;

abstract class DatabaseRepository
{
    protected string $table;

    protected string $model;

    protected string $identifier = 'id';

    /**
     * @return void
     */
    public function __construct(
        #[Depends]
        protected Adapter $adapter
    )
    {
        if (!isset($this->table)) {
            $this->table = preg_replace('@_repository$@', '', str_replace('\\', '_', strtolower(get_class($this))));
        }
        if (!isset($this->model)) {
            $this->model = preg_replace('@Repository$@', 'Model', get_class($this));
        }
    }

    /**
     * @param mixed $identifier
     * @return object|null
     */
    public function findByIdentifier(mixed $identifier) :? object
    {
        return $this->single($this->select()->where("{$this->identifier} = ?", $identifier));
    }

    /**
     * @param object &$model
     * @return null|string
     * @throws Sensi\Supportery\ModelMismatchException
     */
    public function save(object &$model) :? string
    {
        if (!($model instanceof $this->model)) {
            throw new ModelMismatchException($model, $this->model);
        }
        $reflection = new ReflectionObject($model);
        $data = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC & ~ReflectionProperty::IS_STATIC) as $property) {
            $data[$property->name] = $model->{$property->name} ?? null;
            if (!isset($model->{$this->identifier}) && !isset($data[$property->name])) {
                unset($data[$property->name]);
            }
        }
        try {
            if (isset($model->{$this->identifier})) {
                $query = $this->adapter->update($this->table)
                    ->where("{$this->identifier} = ?", $model->{$this->identifier});
            } else {
                $query = $this->adapter->insert($this->table);
            }
            $query->execute($data);
            $newmodel = $this->findByIdentifier(isset($model->{$this->identifier})
                ? $model->{$this->identifier}
                : $this->adapter->lastInsertId($this->table));
            $model->copyIdentity($newmodel);
            return null;
        } catch (InsertException $e) {
            return 'insert';
        } catch (UpdateException $e) {
            return 'update';
        }
    }

    /**
     * @param object $model
     * @return string|null
     * @throws Sensi\Supportery\ModelMismatchException
     */
    public function delete(object $model) :? string
    {
        if (!($model instanceof $this->model)) {
            throw new ModelMismatchException($model, $this->model);
        }
        try {
            $this->adapter->delete($this->table)
                ->where("{$this->identifier} = ?", $model->id)
                ->execute();
            return null;
        } catch (DeleteException $e) {
            return 'database';
        }
    }

    /**
     * @return Quibble\Query\Select
     */
    protected function select() : Select
    {
        return $this->adapter->select($this->table);
    }

    /**
     * @param Quibble\Query\Select $query
     * @return array
     */
    protected function list(Select $query) : array
    {
        try {
            $model = $this->model;
            return $model::fromIterableCollection($query->fetchAll(PDO::FETCH_ASSOC));
        } catch (SelectException $e) {
            return [];
        }
    }

    /**
     * @param Quibble\Query\Select $query
     * @return object|null
     */
    protected function single(Select $query) :? object
    {
        try {
            $model = $this->model;
            return $model::fromIterable($query->fetch(PDO::FETCH_ASSOC));
        } catch (SelectException $e) {
            return null;
        }
    }
}

