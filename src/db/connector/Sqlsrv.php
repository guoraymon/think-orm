<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2012 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
namespace think\db\connector;

use PDO;
use think\db\PDOConnection;
/**
 * Sqlsrv数据库驱动
 */
class Sqlsrv extends PDOConnection
{
    /**
     * 默认PDO连接参数
     * @var array
     */
    protected $params = [PDO::ATTR_CASE => PDO::CASE_NATURAL, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL, PDO::ATTR_STRINGIFY_FETCHES => false];
    /**
     * 解析pdo连接的dsn信息
     * @access protected
     * @param  array $config 连接信息
     * @return string
     */
    protected function parseDsn(array $config)
    {
        $dsn = 'sqlsrv:Database=' . $config['database'] . ';Server=' . $config['hostname'];
        if (!empty($config['hostport'])) {
            $dsn .= ',' . $config['hostport'];
        }
        return $dsn;
    }
    /**
     * 取得数据表的字段信息
     * @access public
     * @param  string $tableName
     * @return array
     */
    public function getFields($tableName)
    {
        list($tableName) = explode(' ', $tableName);
        $sql = "SELECT   column_name,   data_type,   column_default,   is_nullable\n        FROM    information_schema.tables AS t\n        JOIN    information_schema.columns AS c\n        ON  t.table_catalog = c.table_catalog\n        AND t.table_schema  = c.table_schema\n        AND t.table_name    = c.table_name\n        WHERE   t.table_name = '{$tableName}'";
        $pdo = $this->getPDOStatement($sql);
        $result = $pdo->fetchAll(PDO::FETCH_ASSOC);
        $info = [];
        if (!empty($result)) {
            foreach ($result as $key => $val) {
                $val = array_change_key_case($val);
                $info[$val['column_name']] = [
                    'name' => $val['column_name'],
                    'type' => $val['data_type'],
                    'notnull' => (bool) ('' === $val['is_nullable']),
                    // not null is empty, null is yes
                    'default' => $val['column_default'],
                    'primary' => false,
                    'autoinc' => false,
                ];
            }
        }
        $sql = "SELECT column_name FROM information_schema.key_column_usage WHERE table_name='{$tableName}'";
        // 调试开始
        $this->debug(true);
        $pdo = $this->linkID->query($sql);
        // 调试结束
        $this->debug(false, $sql);
        $result = $pdo->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $info[$result['column_name']]['primary'] = true;
        }
        return $this->fieldCase($info);
    }
    /**
     * 取得数据表的字段信息
     * @access public
     * @param  string $dbName
     * @return array
     */
    public function getTables($dbName = '')
    {
        $sql = "SELECT TABLE_NAME\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_TYPE = 'BASE TABLE'\n            ";
        $pdo = $this->getPDOStatement($sql);
        $result = $pdo->fetchAll(PDO::FETCH_ASSOC);
        $info = [];
        foreach ($result as $key => $val) {
            $info[$key] = current($val);
        }
        return $info;
    }
}