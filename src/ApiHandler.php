<?php

use Monolyth\Disclosure\Container;
use Monomelodies\Monki\Handler\Crud;
use Quibble\Transformer\Transformer;
use Psr\Http\Message\ResponseInterface;
use Quibble\Dabble\Adapter;
use Quibble\Query\{ SelectException, UpdateException, DeleteException };
use Quibble\Query\Builder;
use Sensi\Pgdecorators\{ PgArray, JsonbArray };

class ApiHandler extends Crud
{
    private Adapter $adapter;

    private Transformer $transform;

    private string $table;

    /**
     * @return void
     */
    public function __construct(string $table)
    {
        $this->adapter = (new Container)->get('adapter');
        $this->transform = new Transformer;
        $this->table = $table;
    }

    /**
     * @Url /count/
     * @return Psr\Http\Message\ResponseInterface
     */
    public function count() : ResponseInterface
    {
        $query = $this->adapter->select($this->table);
        if (isset($_GET['filter'])) {
            $filter = json_decode($_GET['filter'], true);
            $query = $this->applyFilter($query, $filter);
        }
        $count = $query->count();
        return $this->jsonResponse(compact('count'));
    }

    /**
     * @return Psr\Http\Message\ResponseInterface
     */
    public function browse() : ResponseInterface
    {
        try {
            $query = $this->adapter->select($this->table);
            if (isset($_GET['filter'])) {
                $filter = json_decode($_GET['filter'], true);
                $query = $this->applyFilter($query, $filter);
            }
            if (isset($_GET['options'])) {
                $options = json_decode($_GET['options']);
                if (isset($options->order)) {
                    $query = $query->orderBy($options->order);
                }
            }
            if (isset($_GET['limit'])) {
                $query = $query->limit($_GET['limit'], $_GET['offset'] ?? 0);
            }
            $result = $query->fetchAll(PDO::FETCH_ASSOC);
            if ($model = $this->getModel()) {
                $result = $model::fromIterableCollection($result);
            } else {
                $result = $this->transform->collection($result, $this->getTransformer());
            }
        } catch (SelectException $e) {
            $result = [];
        }
        return $this->jsonResponse($result);
    }

    /**
     * @return Psr\Http\Message\ResponseInterface
     */
    public function create() : ResponseInterface
    {
        $data = $this->transform->resource($_POST, [Schema::class, 'post']);
        $model = $this->getModel();
        $model = new ReflectionClass($model);
        $data = $this->removeVirtuals($model, $data);
        if (isset($data['pass'])) {
            $data['pass'] = password_hash($data['pass'], PASSWORD_DEFAULT);
        }
        if ($this->table === 'page' && !array_key_exists('position', $data)) {
            $data['position'] = 0;
        }
        $this->normalize($data);
        $this->adapter->insert($this->table)
            ->execute($data);
        return $this->retrieve($this->adapter->lastInsertId($this->table));
    }

    /**
     * @param string $id
     * @return Psr\Http\Message\ResponseInterface
     */
    public function retrieve(string $id) : ResponseInterface
    {
        $query = $this->adapter->select($this->table)
            ->where('id = ?', $id);
        if (isset($_GET['filter'])) {
            $filter = json_decode($_GET['filter'], true);
            $query = $this->applyFilter($query, $filter);
        }
        if (isset($_GET['options'])) {
            $options = json_decode($_GET['options']);
            if (isset($options->order)) {
                $query->orderBy($options->order);
            }
            if (isset($options->limit, $options->offset)) {
                $query->limit($options->limit, $options->offset);
            }
        }
        try {
            $result = $query->fetch(PDO::FETCH_ASSOC);
            if ($model = $this->getModel()) {
                $result = $model::fromIterable($result);
            } else {
                $result = $this->transform->resource($result, $this->getTransformer());
            }
            return $this->jsonResponse($result);
        } catch (SelectException $e) {
            return $this->emptyResponse(404);
        }
    }
    
    /**
     * @param string $id
     * @return Psr\Http\Message\ResponseInterface
     */
    public function update(string $id) : ResponseInterface
    {
        if ($model = $this->getModel()) {
            $model = new ReflectionClass($model);
        }
        $data = $this->transform->resource($_POST, [Schema::class, 'post']);
        if (isset($model)) {
            $data = $this->removeVirtuals($model, $data);
        }
        $this->normalize($data);
        try {
            $this->adapter->update($this->table)
                ->where('id = ?', $id)
                ->execute($data);
            return $this->retrieve($id);
        } catch (UpdateException $e) {
            echo $e->getMEssage();die($id);
            return $this->emptyResponse(500);
        }
    }

    /**
     * @param string $id
     * @return Psr\Http\Message\ResponseInterface
     */
    public function delete(string $id) : ResponseInterface
    {
        try {
            $result = $this->adapter->delete($this->table)
                ->where('id = ?', $id)
                ->execute();
            return $this->emptyResponse(200);
        } catch (DeleteException $e) {
            return $this->emptyResponse(500);
        }
    }

    /**
     * @param Quibble\Query\Builder $query
     * @param array $filter
     * @param string $type
     * @return Quibble\Query\Builder
     */
    protected function applyFilter(Builder $query, array $filter, string $type = 'AND') : Builder
    {
        $where = $type == 'OR' ? 'orWhere' : 'where';
        foreach ($filter as $key => $data) {
            if (is_array($data)) {
                $query->$where([$this, 'applyFilter'], $data, $type == 'AND' ? 'OR' : 'AND');
            } else {
                $query->$where("$key = ?", $data);
            }
        }
        return $query;
    }

    protected function getTransformer()
    {
        if (method_exists(Schema::class, $this->table)) {
            return [Schema::class, $this->table];
        } else {
            return function () {};
        }
    }

    /**
     * @param ReflectionClass $model
     * @param array $data
     * @return array
     */
    protected function removeVirtuals(ReflectionClass $model, array $data) : array
    {
        $return = [];
        $properties = $model->getProperties(ReflectionProperty::IS_PUBLIC & ~ReflectionProperty::IS_STATIC);
        $hasProperty = function ($key) use ($properties) : bool {
            foreach ($properties as $prop) {
                if ($prop->getName() == $key) {
                    return true;
                }
            }
            return false;
        };
        foreach ($data as $key => $value) {
            if (!$hasProperty($key)) {
                continue;
            }
            if (is_array($value)) {
                $return[$key] = $value;
            } elseif (strlen("$value")) {
                $return[$key] = "$value";
            } else {
                $return[$key] = null;
            }
        }
        return $return;
    }

    protected function normalize(array &$data) : void
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                foreach ($value as $sub) {
                    if (is_array($sub)) {
                        $value = new JsonbArray($value);
                    } else {
                        $value = new PgArray($value);
                    }
                    break;
                }
            }
        }
    }

    private function getModel() :? string
    {
        $name = ucfirst(preg_replace_callback(
            '@_([a-z])@',
            function ($match) {
                return '\\'.strtoupper($match[1]);
            },
            $this->table
        ));
        if ($name == 'Auth') {
            $name = 'User';
        }
        if (class_exists("$name\\Model")) {
            return "$name\\Model";
        }
        return null;
    }
}

