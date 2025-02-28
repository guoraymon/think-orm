<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
namespace think\model\relation;

use Closure;
use think\db\BaseQuery as Query;
use think\db\exception\DbException as Exception;
use think\helper\Str;
use think\Model;
use think\model\Relation;
/**
 * 一对一关联基础类
 * @package think\model\relation
 */
abstract class OneToOne extends Relation
{
    /**
     * JOIN类型
     * @var string
     */
    protected $joinType = 'INNER';
    /**
     * 绑定的关联属性
     * @var array
     */
    protected $bindAttr = [];
    /**
     * 关联名
     * @var string
     */
    protected $relation;
    /**
     * 设置join类型
     * @access public
     * @param  string $type JOIN类型
     * @return $this
     */
    public function joinType($type)
    {
        $this->joinType = $type;
        return $this;
    }
    /**
     * 预载入关联查询（JOIN方式）
     * @access public
     * @param  Query   $query       查询对象
     * @param  string  $relation    关联名
     * @param  mixed   $field       关联字段
     * @param  string  $joinType    JOIN方式
     * @param  Closure $closure     闭包条件
     * @param  bool    $first
     * @return void
     */
    public function eagerly(Query $query, $relation, $field = true, $joinType = '', Closure $closure = null, $first = false)
    {
        $name = Str::snake(class_basename($this->parent));
        if ($first) {
            $table = $query->getTable();
            $query->table([$table => $name]);
            if ($query->getOptions('field')) {
                $masterField = $query->getOptions('field');
                $query->removeOption('field');
            } else {
                $masterField = true;
            }
            $query->tableField($masterField, $table, $name);
        }
        // 预载入封装
        $joinTable = $this->query->getTable();
        $joinAlias = $relation;
        $joinType = $joinType ?: $this->joinType;
        $query->via($joinAlias);
        if ($this instanceof BelongsTo) {
            $joinOn = $name . '.' . $this->foreignKey . '=' . $joinAlias . '.' . $this->localKey;
        } else {
            $joinOn = $name . '.' . $this->localKey . '=' . $joinAlias . '.' . $this->foreignKey;
        }
        if ($closure) {
            // 执行闭包查询
            $closure($query);
            // 使用withField指定获取关联的字段
            if ($this->withField) {
                $field = $this->withField;
            }
        }
        $query->join([$joinTable => $joinAlias], $joinOn, $joinType)->tableField($field, $joinTable, $joinAlias, $relation . '__');
    }
    /**
     *  预载入关联查询（数据集）
     * @access protected
     * @param  array   $resultSet
     * @param  string  $relation
     * @param  array   $subRelation
     * @param  Closure $closure
     * @return mixed
     */
    protected abstract function eagerlySet(array &$resultSet, $relation, array $subRelation = [], Closure $closure = null);
    /**
     * 预载入关联查询（数据）
     * @access protected
     * @param  Model   $result
     * @param  string  $relation
     * @param  array   $subRelation
     * @param  Closure $closure
     * @return mixed
     */
    protected abstract function eagerlyOne(Model $result, $relation, array $subRelation = [], Closure $closure = null);
    /**
     * 预载入关联查询（数据集）
     * @access public
     * @param  array   $resultSet   数据集
     * @param  string  $relation    当前关联名
     * @param  array   $subRelation 子关联名
     * @param  Closure $closure     闭包
     * @param  array   $cache       关联缓存
     * @param  bool    $join        是否为JOIN方式
     * @return void
     */
    public function eagerlyResultSet(array &$resultSet, $relation, array $subRelation = [], Closure $closure = null, array $cache = [], $join = false)
    {
        if ($join) {
            // 模型JOIN关联组装
            foreach ($resultSet as $result) {
                $this->match($this->model, $relation, $result);
            }
        } else {
            // IN查询
            $this->eagerlySet($resultSet, $relation, $subRelation, $closure, $cache);
        }
    }
    /**
     * 预载入关联查询（数据）
     * @access public
     * @param  Model   $result      数据对象
     * @param  string  $relation    当前关联名
     * @param  array   $subRelation 子关联名
     * @param  Closure $closure     闭包
     * @param  array   $cache       关联缓存
     * @param  bool    $join        是否为JOIN方式
     * @return void
     */
    public function eagerlyResult(Model $result, $relation, array $subRelation = [], Closure $closure = null, array $cache = [], $join = false)
    {
        if ($join) {
            // 模型JOIN关联组装
            $this->match($this->model, $relation, $result);
        } else {
            // IN查询
            $this->eagerlyOne($result, $relation, $subRelation, $closure, $cache);
        }
    }
    /**
     * 保存（新增）当前关联数据对象
     * @access public
     * @param  mixed   $data 数据 可以使用数组 关联模型对象
     * @param  boolean $replace 是否自动识别更新和写入
     * @return Model|false
     */
    public function save($data, $replace = true)
    {
        if ($data instanceof Model) {
            $data = $data->getData();
        }
        $model = new $this->model();
        // 保存关联表数据
        $data[$this->foreignKey] = $this->parent->{$this->localKey};
        return $model->replace($replace)->save($data) ? $model : false;
    }
    /**
     * 绑定关联表的属性到父模型属性
     * @access public
     * @param  array $attr 要绑定的属性列表
     * @return $this
     */
    public function bind(array $attr)
    {
        $this->bindAttr = $attr;
        return $this;
    }
    /**
     * 获取绑定属性
     * @access public
     * @return array
     */
    public function getBindAttr()
    {
        return $this->bindAttr;
    }
    /**
     * 一对一 关联模型预查询拼装
     * @access public
     * @param  string $model    模型名称
     * @param  string $relation 关联名
     * @param  Model  $result   模型对象实例
     * @return void
     */
    protected function match($model, $relation, Model $result)
    {
        // 重新组装模型数据
        foreach ($result->getData() as $key => $val) {
            if (strpos($key, '__')) {
                list($name, $attr) = explode('__', $key, 2);
                if ($name == $relation) {
                    $list[$name][$attr] = $val;
                    unset($result->{$key});
                }
            }
        }
        if (isset($list[$relation])) {
            $array = array_unique($list[$relation]);
            if (count($array) == 1 && null === current($array)) {
                $relationModel = null;
            } else {
                $relationModel = new $model($list[$relation]);
                $relationModel->setParent(clone $result);
                $relationModel->exists(true);
            }
            if ($relationModel && !empty($this->bindAttr)) {
                $this->bindAttr($relationModel, $result);
            }
        } else {
            $relationModel = null;
        }
        $result->setRelation(Str::snake($relation), $relationModel);
    }
    /**
     * 绑定关联属性到父模型
     * @access protected
     * @param  Model $model  关联模型对象
     * @param  Model $result 父模型对象
     * @return void
     * @throws Exception
     */
    protected function bindAttr(Model $model, Model $result)
    {
        foreach ($this->bindAttr as $key => $attr) {
            $key = is_numeric($key) ? $attr : $key;
            $value = $result->getOrigin($key);
            if (!is_null($value)) {
                throw new Exception('bind attr has exists:' . $key);
            }
            $result->setAttr($key, $model ? $model->{$attr} : null);
        }
    }
    /**
     * 一对一 关联模型预查询（IN方式）
     * @access public
     * @param  array   $where       关联预查询条件
     * @param  string  $key         关联键名
     * @param  string  $relation    关联名
     * @param  array   $subRelation 子关联
     * @param  Closure $closure
     * @param  array   $cache       关联缓存
     * @return array
     */
    protected function eagerlyWhere(array $where, $key, $relation, array $subRelation = [], Closure $closure = null, array $cache = [])
    {
        // 预载入关联查询 支持嵌套预载入
        if ($closure) {
            $this->baseQuery = true;
            $closure($this);
        }
        if ($this->withField) {
            $this->query->field($this->withField);
        }
        if ($this->query->getOptions('order')) {
            $this->query->group($key);
        }
        $list = $this->query->where($where)->with($subRelation)->cache(isset($cache[0]) ? $cache[0] : false, isset($cache[1]) ? $cache[1] : null, isset($cache[2]) ? $cache[2] : null)->select();
        // 组装模型数据
        $data = [];
        foreach ($list as $set) {
            if (!isset($data[$set->{$key}])) {
                $data[$set->{$key}] = $set;
            }
        }
        return $data;
    }
}