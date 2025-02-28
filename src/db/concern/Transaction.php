<?php

namespace think\db\concern;

use think\db\BaseQuery;

/**
 * 事务支持
 */
trait Transaction
{
    /**
     * 执行数据库Xa事务
     * @access public
     * @param callable $callback 数据操作方法回调
     * @param array $dbs 多个查询对象或者连接对象
     * @return mixed
     * @throws PDOException
     * @throws \Exception
     * @throws \Throwable
     */
    public function transactionXa($callback, array $dbs = [])
    {
        $xid = uniqid('xa');
        if (empty($dbs)) {
            $dbs[] = $this->getConnection();
        }
        foreach ($dbs as $key => $db) {
            if ($db instanceof BaseQuery) {
                $db = $db->getConnection();
                $dbs[$key] = $db;
            }
            $db->startTransXa($xid);
        }
        try {
            $result = null;
            if (is_callable($callback)) {
                $result = call_user_func_array($callback, [$this]);
            }
            foreach ($dbs as $db) {
                $db->prepareXa($xid);
            }
            foreach ($dbs as $db) {
                $db->commitXa($xid);
            }
            return $result;
        } catch (\Exception $e) {
            foreach ($dbs as $db) {
                $db->rollbackXa($xid);
            }
            throw $e;
        } catch (\Throwable $e) {
            foreach ($dbs as $db) {
                $db->rollbackXa($xid);
            }
            throw $e;
        }
    }

    /**
     * 执行数据库事务
     * @access public
     * @param callable $callback 数据操作方法回调
     * @return mixed
     */
    public function transaction(callable $callback)
    {
        return $this->connection->transaction($callback);
    }

    /**
     * 启动事务
     * @access public
     * @return void
     */
    public function startTrans()
    {
        $this->connection->startTrans();
    }

    /**
     * 用于非自动提交状态下面的查询提交
     * @access public
     * @return void
     * @throws PDOException
     */
    public function commit()
    {
        $this->connection->commit();
    }

    /**
     * 事务回滚
     * @access public
     * @return void
     * @throws PDOException
     */
    public function rollback()
    {
        $this->connection->rollback();
    }
}