<?php

namespace Sensi;

use Monolyth\Disclosure\Depends;
use Quibble\Dabble\Adapter;
use Quibble\Query\{ SelectException, InsertException, UpdateException, DeleteException };
use ReflectionClass, ReflectionProperty;
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
            $this->model = preg_replace('@\Repository$@', '\Model', get_class($this));
        }
    }

    /**
     * @return array
     */
    public function all() : array
    {
        try {
            $model = $this->model;
            return $model::fromIterableCollection($this->adapter->select($this->table)
                ->fetchAll(PDO::FETCH_ASSOC));
        } catch (SelectException $e) {
            return [];
        }
    }

    /**
     * @param int $id
     * @return object|null
     */
    public function find(int $id) :? object
    {
        try {
            $model = $this->model;
            return $model::fromIterable($this->adapter->select($this->table)
                ->where("{$this->identifier} = ?", $id)
                ->fetch(PDO::FETCH_ASSOC));
        } catch (SelectException $e) {
            return null;
        }
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
            if (!isset($model->{$this->identifier}, $data[$property->name])) {
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
            $model = $this->find(isset($model->{$this->identifier}) ? $model->{$this->identifier} : $this->adapter->lastInsertId($this->table));
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
}

