<?php

namespace think\db\builder;

use think\db\Builder;
use think\db\Query;
use think\db\Raw;
/**
 * Pgsql数据库驱动
 */
class Pgsql extends Builder
{
    /**
     * INSERT SQL表达式
     * @var string
     */
    protected $insertSql = 'INSERT INTO %TABLE% (%FIELD%) VALUES (%DATA%) %COMMENT%';
    /**
     * INSERT ALL SQL表达式
     * @var string
     */
    protected $insertAllSql = 'INSERT INTO %TABLE% (%FIELD%) %DATA% %COMMENT%';
    /**
     * limit分析
     * @access protected
     * @param  Query     $query        查询对象
     * @param  mixed     $limit
     * @return string
     */
    public function parseLimit(Query $query, $limit)
    {
        $limitStr = '';
        if (!empty($limit)) {
            $limit = explode(',', $limit);
            if (count($limit) > 1) {
                $limitStr .= ' LIMIT ' . $limit[1] . ' OFFSET ' . $limit[0] . ' ';
            } else {
                $limitStr .= ' LIMIT ' . $limit[0] . ' ';
            }
        }
        return $limitStr;
    }
    /**
     * 字段和表名处理
     * @access public
     * @param  Query     $query     查询对象
     * @param  mixed     $key       字段名
     * @param  bool      $strict   严格检测
     * @return string
     */
    public function parseKey(Query $query, $key, $strict = false)
    {
        if (is_int($key)) {
            return (string) $key;
        } elseif ($key instanceof Raw) {
            return $key->getValue();
        }
        $key = trim($key);
        if (strpos($key, '->') && false === strpos($key, '(')) {
            // JSON字段支持
            list($field, $name) = explode('->', $key);
            $key = '"' . $field . '"' . '->>\'' . $name . '\'';
        } elseif (strpos($key, '.')) {
            list($table, $key) = explode('.', $key, 2);
            $alias = $query->getOptions('alias');
            if ('__TABLE__' == $table) {
                $table = $query->getOptions('table');
                $table = is_array($table) ? array_shift($table) : $table;
            }
            if (isset($alias[$table])) {
                $table = $alias[$table];
            }
            if ('*' != $key && !preg_match('/[,\\"\\*\\(\\).\\s]/', $key)) {
                $key = '"' . $key . '"';
            }
        }
        if (isset($table)) {
            $key = $table . '.' . $key;
        }
        return $key;
    }
    /**
     * 随机排序
     * @access protected
     * @param  Query     $query        查询对象
     * @return string
     */
    protected function parseRand(Query $query)
    {
        return 'RANDOM()';
    }
}