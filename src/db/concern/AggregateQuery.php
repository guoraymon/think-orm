<?php

namespace think\db\concern;

use think\db\Raw;
/**
 * 聚合查询
 */
trait AggregateQuery
{
    /**
     * 聚合查询
     * @access protected
     * @param string     $aggregate 聚合方法
     * @param string|Raw $field     字段名
     * @param bool       $force     强制转为数字类型
     * @return mixed
     */
    protected function aggregate($aggregate, $field, $force = false)
    {
        return $this->connection->aggregate($this, $aggregate, $field, $force);
    }
    /**
     * COUNT查询
     * @access public
     * @param string|Raw $field 字段名
     * @return int
     */
    public function count($field = '*')
    {
        if (!empty($this->options['group'])) {
            // 支持GROUP
            $options = $this->getOptions();
            $subSql = $this->options($options)->field('count(' . $field . ') AS think_count')->bind($this->bind)->buildSql();
            $query = $this->newQuery()->table([$subSql => '_group_count_']);
            $count = $query->aggregate('COUNT', '*');
        } else {
            $count = $this->aggregate('COUNT', $field);
        }
        return (int) $count;
    }
    /**
     * SUM查询
     * @access public
     * @param string|Raw $field 字段名
     * @return float
     */
    public function sum($field)
    {
        return $this->aggregate('SUM', $field, true);
    }
    /**
     * MIN查询
     * @access public
     * @param string|Raw $field 字段名
     * @param bool       $force 强制转为数字类型
     * @return mixed
     */
    public function min($field, $force = true)
    {
        return $this->aggregate('MIN', $field, $force);
    }
    /**
     * MAX查询
     * @access public
     * @param string|Raw $field 字段名
     * @param bool       $force 强制转为数字类型
     * @return mixed
     */
    public function max($field, $force = true)
    {
        return $this->aggregate('MAX', $field, $force);
    }
    /**
     * AVG查询
     * @access public
     * @param string|Raw $field 字段名
     * @return float
     */
    public function avg($field)
    {
        return $this->aggregate('AVG', $field, true);
    }
}