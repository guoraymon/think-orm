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
use think\helper\Str;
use think\Model;
/**
 * 远程一对一关联类
 */
class HasOneThrough extends HasManyThrough
{
    /**
     * 延迟获取关联数据
     * @access public
     * @param  array   $subRelation 子关联名
     * @param  Closure $closure     闭包查询条件
     * @return Model
     */
    public function getRelation(array $subRelation = [], Closure $closure = null)
    {
        if ($closure) {
            $closure($this);
        }
        $this->baseQuery();
        $relationModel = $this->query->relation($subRelation)->find();
        if ($relationModel) {
            $relationModel->setParent(clone $this->parent);
        }
        return $relationModel;
    }
    /**
     * 预载入关联查询（数据集）
     * @access protected
     * @param  array   $resultSet   数据集
     * @param  string  $relation    当前关联名
     * @param  array   $subRelation 子关联名
     * @param  Closure $closure     闭包
     * @param  array   $cache       关联缓存
     * @return void
     */
    public function eagerlyResultSet(array &$resultSet, $relation, array $subRelation = [], Closure $closure = null, array $cache = [])
    {
        $localKey = $this->localKey;
        $foreignKey = $this->foreignKey;
        $range = [];
        foreach ($resultSet as $result) {
            // 获取关联外键列表
            if (isset($result->{$localKey})) {
                $range[] = $result->{$localKey};
            }
        }
        if (!empty($range)) {
            $this->query->removeWhereField($foreignKey);
            $data = $this->eagerlyWhere([[$this->foreignKey, 'in', $range]], $foreignKey, $relation, $subRelation, $closure, $cache);
            // 关联属性名
            $attr = Str::snake($relation);
            // 关联数据封装
            foreach ($resultSet as $result) {
                // 关联模型
                if (!isset($data[$result->{$localKey}])) {
                    $relationModel = null;
                } else {
                    $relationModel = $data[$result->{$localKey}];
                    $relationModel->setParent(clone $result);
                    $relationModel->exists(true);
                }
                // 设置关联属性
                $result->setRelation($attr, $relationModel);
            }
        }
    }
    /**
     * 预载入关联查询（数据）
     * @access protected
     * @param  Model   $result      数据对象
     * @param  string  $relation    当前关联名
     * @param  array   $subRelation 子关联名
     * @param  Closure $closure     闭包
     * @param  array   $cache       关联缓存
     * @return void
     */
    public function eagerlyResult(Model $result, $relation, array $subRelation = [], Closure $closure = null, array $cache = [])
    {
        $localKey = $this->localKey;
        $foreignKey = $this->foreignKey;
        $this->query->removeWhereField($foreignKey);
        $data = $this->eagerlyWhere([[$foreignKey, '=', $result->{$localKey}]], $foreignKey, $relation, $subRelation, $closure, $cache);
        // 关联模型
        if (!isset($data[$result->{$localKey}])) {
            $relationModel = null;
        } else {
            $relationModel = $data[$result->{$localKey}];
            $relationModel->setParent(clone $result);
            $relationModel->exists(true);
        }
        $result->setRelation(Str::snake($relation), $relationModel);
    }
    /**
     * 关联模型预查询
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
        $keys = $this->through->where($where)->column($this->throughPk, $this->foreignKey);
        if ($closure) {
            $closure($this);
        }
        $list = $this->query->where($this->throughKey, 'in', $keys)->cache(isset($cache[0]) ? $cache[0] : false, isset($cache[1]) ? $cache[1] : null, isset($cache[2]) ? $cache[2] : null)->select();
        // 组装模型数据
        $data = [];
        $keys = array_flip($keys);
        foreach ($list as $set) {
            $data[$keys[$set->{$this->throughKey}]] = $set;
        }
        return $data;
    }
}