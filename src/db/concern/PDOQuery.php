<?php

namespace think\db\concern;

use PDOStatement;
/**
 * PDO查询支持
 */
trait PDOQuery
{
    use JoinAndViewQuery, ParamsBind, TableFieldInfo;
    /**
     * 执行查询但只返回PDOStatement对象
     * @access public
     * @return PDOStatement
     */
    public function getPdo()
    {
        return $this->connection->pdo($this);
    }
    /**
     * 使用游标查找记录
     * @access public
     * @param mixed $data 数据
     * @return \Generator
     */
    public function cursor($data = null)
    {
        if (!is_null($data)) {
            // 主键条件分析
            $this->parsePkWhere($data);
        }
        $this->options['data'] = $data;
        $connection = clone $this->connection;
        return $connection->cursor($this);
    }
}