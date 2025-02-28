<?php

namespace think\db;

use think\Collection;
use think\db\exception\BindParamException;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException as Exception;
use think\db\exception\ModelNotFoundException;
use think\db\exception\PDOException;
use think\helper\Str;
use think\Model;
use think\Paginator;

/**
 * 数据查询基础类
 */
class BaseQuery
{
    use concern\TimeFieldQuery;
    use concern\AggregateQuery;
    use concern\ModelRelationQuery;
    use concern\ResultOperation;
    use concern\Transaction;
    use concern\WhereQuery;
    /**
     * 当前数据库连接对象
     * @var Connection
     */
    protected $connection;
    /**
     * 当前数据表名称（不含前缀）
     * @var string
     */
    protected $name = '';
    /**
     * 当前数据表主键
     * @var string|array
     */
    protected $pk;
    /**
     * 当前数据表前缀
     * @var string
     */
    protected $prefix = '';
    /**
     * 当前查询参数
     * @var array
     */
    protected $options = [];

    /**
     * 架构函数
     * @access public
     * @param Connection $connection 数据库连接对象
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->prefix = $this->connection->getConfig('prefix');
    }

    /**
     * 创建一个新的查询对象
     * @access public
     * @return BaseQuery
     */
    public function newQuery()
    {
        $query = new static($this->connection);
        if ($this->model) {
            $query->model($this->model);
        }
        if (isset($this->options['table'])) {
            $query->table($this->options['table']);
        } else {
            $query->name($this->name);
        }
        return $query;
    }

    /**
     * 利用__call方法实现一些特殊的Model方法
     * @access public
     * @param string $method 方法名称
     * @param array $args 调用参数
     * @return mixed
     * @throws Exception
     */
    public function __call($method, array $args)
    {
        if (strtolower(substr($method, 0, 5)) == 'getby') {
            // 根据某个字段获取记录
            $field = Str::snake(substr($method, 5));
            return $this->where($field, '=', $args[0])->find();
        } elseif (strtolower(substr($method, 0, 10)) == 'getfieldby') {
            // 根据某个字段获取记录的某个值
            $name = Str::snake(substr($method, 10));
            return $this->where($name, '=', $args[0])->value($args[1]);
        } elseif (strtolower(substr($method, 0, 7)) == 'whereor') {
            $name = Str::snake(substr($method, 7));
            array_unshift($args, $name);
            return call_user_func_array([$this, 'whereOr'], $args);
        } elseif (strtolower(substr($method, 0, 5)) == 'where') {
            $name = Str::snake(substr($method, 5));
            array_unshift($args, $name);
            return call_user_func_array([$this, 'where'], $args);
        } elseif ($this->model && method_exists($this->model, 'scope' . $method)) {
            // 动态调用命名范围
            $method = 'scope' . $method;
            array_unshift($args, $this);
            call_user_func_array([$this->model, $method], $args);
            return $this;
        } else {
            throw new Exception('method not exist:' . static::class . '->' . $method);
        }
    }

    /**
     * 获取当前的数据库Connection对象
     * @access public
     * @return Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * 设置当前的数据库Connection对象
     * @access public
     * @param Connection $connection 数据库连接对象
     * @return $this
     */
    public function setConnection(Connection $connection)
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * 指定当前数据表名（不含前缀）
     * @access public
     * @param string $name 不含前缀的数据表名字
     * @return $this
     */
    public function name($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 获取当前的数据表名称
     * @access public
     * @return string
     */
    public function getName()
    {
        return $this->name ?: $this->model->getName();
    }

    /**
     * 获取数据库的配置参数
     * @access public
     * @param string $name 参数名称
     * @return mixed
     */
    public function getConfig($name = '')
    {
        return $this->connection->getConfig($name);
    }

    /**
     * 得到当前或者指定名称的数据表
     * @access public
     * @param string $name 不含前缀的数据表名字
     * @return mixed
     */
    public function getTable($name = '')
    {
        if (empty($name) && isset($this->options['table'])) {
            return $this->options['table'];
        }
        $name = $name ?: $this->name;
        return $this->prefix . Str::snake($name);
    }

    /**
     * 执行查询 返回数据集
     * @access public
     * @param string $sql sql指令
     * @param array $bind 参数绑定
     * @return array
     * @throws BindParamException
     * @throws PDOException
     */
    public function query($sql, array $bind = [])
    {
        return $this->connection->query($this, $sql, $bind);
    }

    /**
     * 执行语句
     * @access public
     * @param string $sql sql指令
     * @param array $bind 参数绑定
     * @return int
     * @throws BindParamException
     * @throws PDOException
     */
    public function execute($sql, array $bind = [])
    {
        return $this->connection->execute($this, $sql, $bind, true);
    }

    /**
     * 获取返回或者影响的记录数
     * @access public
     * @return integer
     */
    public function getNumRows()
    {
        return $this->connection->getNumRows();
    }

    /**
     * 获取最近一次查询的sql语句
     * @access public
     * @return string
     */
    public function getLastSql()
    {
        return $this->connection->getLastSql();
    }

    /**
     * 获取最近插入的ID
     * @access public
     * @param string $sequence 自增序列名
     * @return mixed
     */
    public function getLastInsID($sequence = null)
    {
        return $this->connection->getLastInsID($this, $sequence);
    }

    /**
     * 批处理执行SQL语句
     * 批处理的指令都认为是execute操作
     * @access public
     * @param array $sql SQL批处理指令
     * @return bool
     */
    public function batchQuery(array $sql = [])
    {
        return $this->connection->batchQuery($this, $sql);
    }

    /**
     * 得到某个字段的值
     * @access public
     * @param string $field 字段名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function value($field, $default = null)
    {
        return $this->connection->value($this, $field, $default);
    }

    /**
     * 得到某个列的数组
     * @access public
     * @param string $field 字段名 多个字段用逗号分隔
     * @param string $key 索引
     * @return array
     */
    public function column($field, $key = '')
    {
        return $this->connection->column($this, $field, $key);
    }

    /**
     * 查询SQL组装 union
     * @access public
     * @param mixed $union UNION
     * @param boolean $all 是否适用UNION ALL
     * @return $this
     */
    public function union($union, $all = false)
    {
        $this->options['union']['type'] = $all ? 'UNION ALL' : 'UNION';
        if (is_array($union)) {
            $this->options['union'] = array_merge($this->options['union'], $union);
        } else {
            $this->options['union'][] = $union;
        }
        return $this;
    }

    /**
     * 查询SQL组装 union all
     * @access public
     * @param mixed $union UNION数据
     * @return $this
     */
    public function unionAll($union)
    {
        return $this->union($union, true);
    }

    /**
     * 指定查询字段
     * @access public
     * @param mixed $field 字段信息
     * @return $this
     */
    public function field($field)
    {
        if (empty($field)) {
            return $this;
        } elseif ($field instanceof Raw) {
            $this->options['field'][] = $field;
            return $this;
        }
        if (is_string($field)) {
            if (preg_match('/[\\<\'\\"\\(]/', $field)) {
                return $this->fieldRaw($field);
            }
            $field = array_map('trim', explode(',', $field));
        }
        if (true === $field) {
            // 获取全部字段
            $fields = $this->getTableFields();
            $field = $fields ?: ['*'];
        }
        if (isset($this->options['field'])) {
            $field = array_merge((array)$this->options['field'], $field);
        }
        $this->options['field'] = array_unique($field);
        return $this;
    }

    /**
     * 指定要排除的查询字段
     * @access public
     * @param array|string $field 要排除的字段
     * @return $this
     */
    public function withoutField($field)
    {
        if (empty($field)) {
            return $this;
        }
        if (is_string($field)) {
            $field = array_map('trim', explode(',', $field));
        }
        // 字段排除
        $fields = $this->getTableFields();
        $field = $fields ? array_diff($fields, $field) : $field;
        if (isset($this->options['field'])) {
            $field = array_merge((array)$this->options['field'], $field);
        }
        $this->options['field'] = array_unique($field);
        return $this;
    }

    /**
     * 指定其它数据表的查询字段
     * @access public
     * @param mixed $field 字段信息
     * @param string $tableName 数据表名
     * @param string $prefix 字段前缀
     * @param string $alias 别名前缀
     * @return $this
     */
    public function tableField($field, $tableName, $prefix = '', $alias = '')
    {
        if (empty($field)) {
            return $this;
        }
        if (is_string($field)) {
            $field = array_map('trim', explode(',', $field));
        }
        if (true === $field) {
            // 获取全部字段
            $fields = $this->getTableFields($tableName);
            $field = $fields ?: ['*'];
        }
        // 添加统一的前缀
        $prefix = $prefix ?: $tableName;
        foreach ($field as $key => &$val) {
            if (is_numeric($key) && $alias) {
                $field[$prefix . '.' . $val] = $alias . $val;
                unset($field[$key]);
            } elseif (is_numeric($key)) {
                $val = $prefix . '.' . $val;
            }
        }
        if (isset($this->options['field'])) {
            $field = array_merge((array)$this->options['field'], $field);
        }
        $this->options['field'] = array_unique($field);
        return $this;
    }

    /**
     * 表达式方式指定查询字段
     * @access public
     * @param string $field 字段名
     * @return $this
     */
    public function fieldRaw($field)
    {
        $this->options['field'][] = new Raw($field);
        return $this;
    }

    /**
     * 设置数据
     * @access public
     * @param array $data 数据
     * @return $this
     */
    public function data(array $data)
    {
        $this->options['data'] = $data;
        return $this;
    }

    /**
     * 字段值增长
     * @access public
     * @param string $field 字段名
     * @param float $step 增长值
     * @param integer $lazyTime 延时时间(s)
     * @param string $op INC/DEC
     * @return $this
     */
    public function inc($field, $step = 1, $lazyTime = 0, $op = 'INC')
    {
        if ($lazyTime > 0) {
            // 延迟写入
            $condition = isset($this->options['where']) ? $this->options['where'] : [];
            $guid = md5($this->getTable() . '_' . $field . '_' . serialize($condition));
            $step = $this->connection->lazyWrite($op, $guid, $step, $lazyTime);
            if (false === $step) {
                return $this;
            }
            $op = 'INC';
        }
        $this->options['data'][$field] = [$op, $step];
        return $this;
    }

    /**
     * 字段值减少
     * @access public
     * @param string $field 字段名
     * @param float $step 增长值
     * @param integer $lazyTime 延时时间(s)
     * @return $this
     */
    public function dec($field, $step = 1, $lazyTime = 0)
    {
        return $this->inc($field, $step, $lazyTime, 'DEC');
    }

    /**
     * 使用表达式设置数据
     * @access public
     * @param string $field 字段名
     * @param string $value 字段值
     * @return $this
     */
    public function exp($field, $value)
    {
        $this->options['data'][$field] = new Raw($value);
        return $this;
    }

    /**
     * 去除查询参数
     * @access public
     * @param string $option 参数名 留空去除所有参数
     * @return $this
     */
    public function removeOption($option = '')
    {
        if ('' === $option) {
            $this->options = [];
            $this->bind = [];
        } elseif (isset($this->options[$option])) {
            unset($this->options[$option]);
        }
        return $this;
    }

    /**
     * 指定查询数量
     * @access public
     * @param int $offset 起始位置
     * @param int $length 查询数量
     * @return $this
     */
    public function limit($offset, $length = null)
    {
        $this->options['limit'] = $offset . ($length ? ',' . $length : '');
        return $this;
    }

    /**
     * 指定分页
     * @access public
     * @param int $page 页数
     * @param int $listRows 每页数量
     * @return $this
     */
    public function page($page, $listRows = null)
    {
        $this->options['page'] = [$page, $listRows];
        return $this;
    }

    /**
     * 分页查询
     * @access public
     * @param int|array $listRows 每页数量 数组表示配置参数
     * @param int|bool $simple 是否简洁模式或者总记录数
     * @return Paginator
     * @throws Exception
     */
    public function paginate($listRows = null, $simple = false)
    {
        if (is_int($simple)) {
            $total = $simple;
            $simple = false;
        }
        $defaultConfig = [
            'query' => [],
            //url额外参数
            'fragment' => '',
            //url锚点
            'var_page' => 'page',
            //分页变量
            'list_rows' => 15,
        ];
        if (is_array($listRows)) {
            $config = array_merge($defaultConfig, $listRows);
            $listRows = intval($config['list_rows']);
        } else {
            $config = $defaultConfig;
            $listRows = intval($listRows ?: $config['list_rows']);
        }
        $page = isset($config['page']) ? (int)$config['page'] : Paginator::getCurrentPage($config['var_page']);
        $page = $page < 1 ? 1 : $page;
        $config['path'] = isset($config['path']) ? $config['path'] : Paginator::getCurrentPath();
        if (!isset($total) && !$simple) {
            $options = $this->getOptions();
            unset($this->options['order'], $this->options['limit'], $this->options['page'], $this->options['field']);
            $bind = $this->bind;
            $total = $this->count();
            $results = $this->options($options)->bind($bind)->page($page, $listRows)->select();
        } elseif ($simple) {
            $results = $this->limit(($page - 1) * $listRows, $listRows + 1)->select();
            $total = null;
        } else {
            $results = $this->page($page, $listRows)->select();
        }
        $this->removeOption('limit');
        $this->removeOption('page');
        return Paginator::make($results, $listRows, $page, $total, $simple, $config);
    }

    /**
     * 根据数字类型字段进行分页查询（大数据）
     * @access public
     * @param int|array $listRows 每页数量或者分页配置
     * @param string $key 分页索引键
     * @param string $sort 索引键排序 asc|desc
     * @return Paginator
     * @throws Exception
     */
    public function paginateX($listRows = null, $key = null, $sort = null)
    {
        $defaultConfig = [
            'query' => [],
            //url额外参数
            'fragment' => '',
            //url锚点
            'var_page' => 'page',
            //分页变量
            'list_rows' => 15,
        ];
        $config = is_array($listRows) ? array_merge($defaultConfig, $listRows) : $defaultConfig;
        $listRows = is_int($listRows) ? $listRows : (int)$config['list_rows'];
        $page = isset($config['page']) ? (int)$config['page'] : Paginator::getCurrentPage($config['var_page']);
        $page = $page < 1 ? 1 : $page;
        $config['path'] = isset($config['path']) ? $config['path'] : Paginator::getCurrentPath();
        $key = $key ?: $this->getPk();
        $options = $this->getOptions();
        if (is_null($sort)) {
            $order = isset($options['order']) ? $options['order'] : '';
            if (!empty($order)) {
                $sort = isset($order[$key]) ? $order[$key] : 'desc';
            } else {
                $this->order($key, 'desc');
                $sort = 'desc';
            }
        } else {
            $this->order($key, $sort);
        }
        $newOption = $options;
        unset($newOption['field'], $newOption['page']);
        $data = $this->newQuery()->options($newOption)->field($key)->where(true)->order($key, $sort)->limit(1)->find();
        $result = $data[$key];
        if (is_numeric($result)) {
            $lastId = 'asc' == $sort ? $result - 1 + ($page - 1) * $listRows : $result + 1 - ($page - 1) * $listRows;
        } else {
            throw new Exception('not support type');
        }
        $results = $this->when($lastId, function ($query) use ($key, $sort, $lastId) {
            $query->where($key, 'asc' == $sort ? '>' : '<', $lastId);
        })->limit($listRows)->select();
        $this->options($options);
        return Paginator::make($results, $listRows, $page, null, true, $config);
    }

    /**
     * 根据最后ID查询更多N个数据
     * @access public
     * @param int $limit LIMIT
     * @param int|string $lastId LastId
     * @param string $key 分页索引键 默认为主键
     * @param string $sort 索引键排序 asc|desc
     * @return array
     * @throws Exception
     */
    public function more($limit, $lastId = null, $key = null, $sort = null)
    {
        $key = $key ?: $this->getPk();
        if (is_null($sort)) {
            $order = $this->getOptions('order');
            if (!empty($order)) {
                $sort = isset($order[$key]) ? $order[$key] : 'desc';
            } else {
                $this->order($key, 'desc');
                $sort = 'desc';
            }
        } else {
            $this->order($key, $sort);
        }
        $result = $this->when($lastId, function ($query) use ($key, $sort, $lastId) {
            $query->where($key, 'asc' == $sort ? '>' : '<', $lastId);
        })->limit($limit)->select();
        $last = $result->last();
        $result->first();
        return ['data' => $result, 'lastId' => $last[$key]];
    }

    /**
     * 表达式方式指定当前操作的数据表
     * @access public
     * @param mixed $table 表名
     * @return $this
     */
    public function tableRaw($table)
    {
        $this->options['table'] = new Raw($table);
        return $this;
    }

    /**
     * 指定当前操作的数据表
     * @access public
     * @param mixed $table 表名
     * @return $this
     */
    public function table($table)
    {
        if (is_string($table)) {
            if (strpos($table, ')')) {
                // 子查询
            } elseif (false === strpos($table, ',')) {
                if (strpos($table, ' ')) {
                    list($item, $alias) = explode(' ', $table);
                    $table = [];
                    $this->alias([$item => $alias]);
                    $table[$item] = $alias;
                }
            } else {
                $tables = explode(',', $table);
                $table = [];
                foreach ($tables as $item) {
                    $item = trim($item);
                    if (strpos($item, ' ')) {
                        list($item, $alias) = explode(' ', $item);
                        $this->alias([$item => $alias]);
                        $table[$item] = $alias;
                    } else {
                        $table[] = $item;
                    }
                }
            }
        } elseif (is_array($table)) {
            $tables = $table;
            $table = [];
            foreach ($tables as $key => $val) {
                if (is_numeric($key)) {
                    $table[] = $val;
                } else {
                    $this->alias([$key => $val]);
                    $table[$key] = $val;
                }
            }
        }
        $this->options['table'] = $table;
        return $this;
    }

    /**
     * USING支持 用于多表删除
     * @access public
     * @param mixed $using USING
     * @return $this
     */
    public function using($using)
    {
        $this->options['using'] = $using;
        return $this;
    }

    /**
     * 存储过程调用
     * @access public
     * @param bool $procedure 是否为存储过程查询
     * @return $this
     */
    public function procedure($procedure = true)
    {
        $this->options['procedure'] = $procedure;
        return $this;
    }

    /**
     * 指定排序 order('id','desc') 或者 order(['id'=>'desc','create_time'=>'desc'])
     * @access public
     * @param string|array|Raw $field 排序字段
     * @param string $order 排序
     * @return $this
     */
    public function order($field, $order = '')
    {
        if (empty($field)) {
            return $this;
        } elseif ($field instanceof Raw) {
            $this->options['order'][] = $field;
            return $this;
        }
        if (is_string($field)) {
            if (!empty($this->options['via'])) {
                $field = $this->options['via'] . '.' . $field;
            }
            if (strpos($field, ',')) {
                $field = array_map('trim', explode(',', $field));
            } else {
                $field = empty($order) ? $field : [$field => $order];
            }
        } elseif (!empty($this->options['via'])) {
            foreach ($field as $key => $val) {
                if (is_numeric($key)) {
                    $field[$key] = $this->options['via'] . '.' . $val;
                } else {
                    $field[$this->options['via'] . '.' . $key] = $val;
                    unset($field[$key]);
                }
            }
        }
        if (!isset($this->options['order'])) {
            $this->options['order'] = [];
        }
        if (is_array($field)) {
            $this->options['order'] = array_merge($this->options['order'], $field);
        } else {
            $this->options['order'][] = $field;
        }
        return $this;
    }

    /**
     * 表达式方式指定Field排序
     * @access public
     * @param string $field 排序字段
     * @param array $bind 参数绑定
     * @return $this
     */
    public function orderRaw($field, array $bind = [])
    {
        if (!empty($bind)) {
            $this->bindParams($field, $bind);
        }
        $this->options['order'][] = new Raw($field);
        return $this;
    }

    /**
     * 指定Field排序 orderField('id',[1,2,3],'desc')
     * @access public
     * @param string $field 排序字段
     * @param array $values 排序值
     * @param string $order 排序 desc/asc
     * @return $this
     */
    public function orderField($field, array $values, $order = '')
    {
        if (!empty($values)) {
            $values['sort'] = $order;
            $this->options['order'][$field] = $values;
        }
        return $this;
    }

    /**
     * 随机排序
     * @access public
     * @return $this
     */
    public function orderRand()
    {
        $this->options['order'][] = '[rand]';
        return $this;
    }

    /**
     * 查询缓存
     * @access public
     * @param mixed $key 缓存key
     * @param integer|\DateTime $expire 缓存有效期
     * @param string $tag 缓存标签
     * @return $this
     */
    public function cache($key = true, $expire = null, $tag = null)
    {
        if (false === $key || !$this->getConnection()->getCache()) {
            return $this;
        }
        if ($key instanceof \DateTimeInterface || $key instanceof \DateInterval || is_int($key) && is_null($expire)) {
            $expire = $key;
            $key = true;
        }
        $this->options['cache'] = [$key, $expire, $tag];
        return $this;
    }

    /**
     * 指定group查询
     * @access public
     * @param string|array $group GROUP
     * @return $this
     */
    public function group($group)
    {
        $this->options['group'] = $group;
        return $this;
    }

    /**
     * 指定having查询
     * @access public
     * @param string $having having
     * @return $this
     */
    public function having($having)
    {
        $this->options['having'] = $having;
        return $this;
    }

    /**
     * 指定查询lock
     * @access public
     * @param bool|string $lock 是否lock
     * @return $this
     */
    public function lock($lock = false)
    {
        $this->options['lock'] = $lock;
        if ($lock) {
            $this->options['master'] = true;
        }
        return $this;
    }

    /**
     * 指定distinct查询
     * @access public
     * @param bool $distinct 是否唯一
     * @return $this
     */
    public function distinct($distinct = true)
    {
        $this->options['distinct'] = $distinct;
        return $this;
    }

    /**
     * 指定数据表别名
     * @access public
     * @param array|string $alias 数据表别名
     * @return $this
     */
    public function alias($alias)
    {
        if (is_array($alias)) {
            $this->options['alias'] = $alias;
        } else {
            $table = $this->getTable();
            $this->options['alias'][$table] = $alias;
        }
        return $this;
    }

    /**
     * 指定强制索引
     * @access public
     * @param string $force 索引名称
     * @return $this
     */
    public function force($force)
    {
        $this->options['force'] = $force;
        return $this;
    }

    /**
     * 查询注释
     * @access public
     * @param string $comment 注释
     * @return $this
     */
    public function comment($comment)
    {
        $this->options['comment'] = $comment;
        return $this;
    }

    /**
     * 获取执行的SQL语句而不进行实际的查询
     * @access public
     * @param bool $fetch 是否返回sql
     * @return $this|Fetch
     */
    public function fetchSql($fetch = true)
    {
        $this->options['fetch_sql'] = $fetch;
        if ($fetch) {
            return new Fetch($this);
        }
        return $this;
    }

    /**
     * 设置从主服务器读取数据
     * @access public
     * @param bool $readMaster 是否从主服务器读取
     * @return $this
     */
    public function master($readMaster = true)
    {
        $this->options['master'] = $readMaster;
        return $this;
    }

    /**
     * 设置是否严格检查字段名
     * @access public
     * @param bool $strict 是否严格检查字段
     * @return $this
     */
    public function strict($strict = true)
    {
        $this->options['strict'] = $strict;
        return $this;
    }

    /**
     * 设置自增序列名
     * @access public
     * @param string $sequence 自增序列名
     * @return $this
     */
    public function sequence($sequence = null)
    {
        $this->options['sequence'] = $sequence;
        return $this;
    }

    /**
     * 设置是否REPLACE
     * @access public
     * @param bool $replace 是否使用REPLACE写入数据
     * @return $this
     */
    public function replace($replace = true)
    {
        $this->options['replace'] = $replace;
        return $this;
    }

    /**
     * 设置当前查询所在的分区
     * @access public
     * @param string|array $partition 分区名称
     * @return $this
     */
    public function partition($partition)
    {
        $this->options['partition'] = $partition;
        return $this;
    }

    /**
     * 设置DUPLICATE
     * @access public
     * @param array|string|Raw $duplicate DUPLICATE信息
     * @return $this
     */
    public function duplicate($duplicate)
    {
        $this->options['duplicate'] = $duplicate;
        return $this;
    }

    /**
     * 设置查询的额外参数
     * @access public
     * @param string $extra 额外信息
     * @return $this
     */
    public function extra($extra)
    {
        $this->options['extra'] = $extra;
        return $this;
    }

    /**
     * 设置JSON字段信息
     * @access public
     * @param array $json JSON字段
     * @param bool $assoc 是否取出数组
     * @return $this
     */
    public function json(array $json = [], $assoc = false)
    {
        $this->options['json'] = $json;
        $this->options['json_assoc'] = $assoc;
        return $this;
    }

    /**
     * 指定数据表主键
     * @access public
     * @param string $pk 主键
     * @return $this
     */
    public function pk($pk)
    {
        $this->pk = $pk;
        return $this;
    }

    /**
     * 获取当前数据表的主键
     * @access public
     * @return string|array
     */
    public function getPk()
    {
        if (empty($this->pk)) {
            $this->pk = $this->connection->getPk($this->getTable());
        }
        return $this->pk;
    }

    /**
     * 查询参数批量赋值
     * @access protected
     * @param array $options 表达式参数
     * @return $this
     */
    protected function options(array $options)
    {
        $this->options = $options;
        return $this;
    }

    /**
     * 获取当前的查询参数
     * @access public
     * @param string $name 参数名
     * @return mixed
     */
    public function getOptions($name = '')
    {
        if ('' === $name) {
            return $this->options;
        }
        return isset($this->options[$name]) ? $this->options[$name] : null;
    }

    /**
     * 设置当前的查询参数
     * @access public
     * @param string $option 参数名
     * @param mixed $value 参数值
     * @return $this
     */
    public function setOption($option, $value)
    {
        $this->options[$option] = $value;
        return $this;
    }

    /**
     * 设置当前字段添加的表别名
     * @access public
     * @param string $via 临时表别名
     * @return $this
     */
    public function via($via = '')
    {
        $this->options['via'] = $via;
        return $this;
    }

    /**
     * 保存记录 自动判断insert或者update
     * @access public
     * @param array $data 数据
     * @param bool $forceInsert 是否强制insert
     * @return integer
     */
    public function save(array $data = [], $forceInsert = false)
    {
        if ($forceInsert) {
            return $this->insert($data);
        }
        $this->options['data'] = array_merge(isset($this->options['data']) ? $this->options['data'] : [], $data);
        if (!empty($this->options['where'])) {
            $isUpdate = true;
        } else {
            $isUpdate = $this->parseUpdateData($this->options['data']);
        }
        return $isUpdate ? $this->update() : $this->insert();
    }

    /**
     * 插入记录
     * @access public
     * @param array $data 数据
     * @param boolean $getLastInsID 返回自增主键
     * @return integer|string
     */
    public function insert(array $data = [], $getLastInsID = false)
    {
        if (!empty($data)) {
            $this->options['data'] = $data;
        }
        return $this->connection->insert($this, $getLastInsID);
    }

    /**
     * 插入记录并获取自增ID
     * @access public
     * @param array $data 数据
     * @return integer|string
     */
    public function insertGetId(array $data)
    {
        return $this->insert($data, true);
    }

    /**
     * 批量插入记录
     * @access public
     * @param array $dataSet 数据集
     * @param integer $limit 每次写入数据限制
     * @return integer
     */
    public function insertAll(array $dataSet = [], $limit = 0)
    {
        if (empty($dataSet)) {
            $dataSet = isset($this->options['data']) ? $this->options['data'] : [];
        }
        if (empty($limit) && !empty($this->options['limit']) && is_numeric($this->options['limit'])) {
            $limit = (int)$this->options['limit'];
        }
        return $this->connection->insertAll($this, $dataSet, $limit);
    }

    /**
     * 通过Select方式插入记录
     * @access public
     * @param array $fields 要插入的数据表字段名
     * @param string $table 要插入的数据表名
     * @return integer
     * @throws PDOException
     */
    public function selectInsert(array $fields, $table)
    {
        return $this->connection->selectInsert($this, $fields, $table);
    }

    /**
     * 更新记录
     * @access public
     * @param mixed $data 数据
     * @return integer
     * @throws Exception
     * @throws PDOException
     */
    public function update(array $data = [])
    {
        if (!empty($data)) {
            $this->options['data'] = array_merge(isset($this->options['data']) ? $this->options['data'] : [], $data);
        }
        if (empty($this->options['where'])) {
            $this->parseUpdateData($this->options['data']);
        }
        if (empty($this->options['where']) && $this->model) {
            $this->where($this->model->getWhere());
        }
        if (empty($this->options['where'])) {
            // 如果没有任何更新条件则不执行
            throw new Exception('miss update condition');
        }
        return $this->connection->update($this);
    }

    /**
     * 删除记录
     * @access public
     * @param mixed $data 表达式 true 表示强制删除
     * @return int
     * @throws Exception
     * @throws PDOException
     */
    public function delete($data = null)
    {
        if (!is_null($data) && true !== $data) {
            // AR模式分析主键条件
            $this->parsePkWhere($data);
        }
        if (empty($this->options['where']) && $this->model) {
            $this->where($this->model->getWhere());
        }
        if (true !== $data && empty($this->options['where'])) {
            // 如果条件为空 不进行删除操作 除非设置 1=1
            throw new Exception('delete without condition');
        }
        if (!empty($this->options['soft_delete'])) {
            // 软删除
            list($field, $condition) = $this->options['soft_delete'];
            if ($condition) {
                unset($this->options['soft_delete']);
                $this->options['data'] = [$field => $condition];
                return $this->connection->update($this);
            }
        }
        $this->options['data'] = $data;
        return $this->connection->delete($this);
    }

    /**
     * 查找记录
     * @access public
     * @param mixed $data 数据
     * @return Collection
     * @throws Exception
     * @throws ModelNotFoundException
     * @throws DataNotFoundException
     */
    public function select($data = null)
    {
        if (!is_null($data)) {
            // 主键条件分析
            $this->parsePkWhere($data);
        }
        $resultSet = $this->connection->select($this);
        // 返回结果处理
        if (!empty($this->options['fail']) && count($resultSet) == 0) {
            $this->throwNotFound();
        }
        // 数据列表读取后的处理
        if (!empty($this->model)) {
            // 生成模型对象
            $resultSet = $this->resultSetToModelCollection($resultSet);
        } else {
            $this->resultSet($resultSet);
        }
        return $resultSet;
    }

    /**
     * 查找单条记录
     * @access public
     * @param mixed $data 查询数据
     * @return Model|null
     */
    public function find($data = null)
    {
        if (!is_null($data)) {
            // AR模式分析主键条件
            $this->parsePkWhere($data);
        }

        $result = $this->connection->find($this);

        // 数据处理
        if (empty($result)) {
            return $this->resultToEmpty();
        }
        if (!empty($this->model)) {
            // 返回模型对象
            $this->resultToModel($result, $this->options);
        } else {
            $this->result($result);
        }
        return $result;
    }

    /**
     * 分批数据返回处理
     * @access public
     * @param integer $count 每次处理的数据数量
     * @param callable $callback 处理回调方法
     * @param string|array $column 分批处理的字段名
     * @param string $order 字段排序
     * @return bool
     * @throws Exception
     */
    public function chunk($count, callable $callback, $column = null, $order = 'asc')
    {
        $options = $this->getOptions();
        $column = $column ?: $this->getPk();
        if (isset($options['order'])) {
            unset($options['order']);
        }
        $bind = $this->bind;
        if (is_array($column)) {
            $times = 1;
            $query = $this->options($options)->page($times, $count);
        } else {
            $query = $this->options($options)->limit($count);
            if (strpos($column, '.')) {
                list($alias, $key) = explode('.', $column);
            } else {
                $key = $column;
            }
        }
        $resultSet = $query->order($column, $order)->select();
        while (count($resultSet) > 0) {
            if (false === call_user_func($callback, $resultSet)) {
                return false;
            }
            if (isset($times)) {
                $times++;
                $query = $this->options($options)->page($times, $count);
            } else {
                $end = $resultSet->pop();
                $lastId = is_array($end) ? $end[$key] : $end->getData($key);
                $query = $this->options($options)->limit($count)->where($column, 'asc' == strtolower($order) ? '>' : '<', $lastId);
            }
            $resultSet = $query->bind($bind)->order($column, $order)->select();
        }
        return true;
    }

    /**
     * 创建子查询SQL
     * @access public
     * @param bool $sub 是否添加括号
     * @return string
     * @throws Exception
     */
    public function buildSql($sub = true)
    {
        return $sub ? '( ' . $this->fetchSql()->select() . ' )' : $this->fetchSql()->select();
    }

    /**
     * 分析表达式（可用于查询或者写入操作）
     * @access public
     * @return array
     */
    public function parseOptions()
    {
        $options = $this->getOptions();
        // 获取数据表
        if (empty($options['table'])) {
            $options['table'] = $this->getTable();
        }
        if (!isset($options['where'])) {
            $options['where'] = [];
        } elseif (isset($options['view'])) {
            // 视图查询条件处理
            $this->parseView($options);
        }
        if (!isset($options['field'])) {
            $options['field'] = '*';
        }
        foreach (['data', 'order', 'join', 'union'] as $name) {
            if (!isset($options[$name])) {
                $options[$name] = [];
            }
        }
        if (!isset($options['strict'])) {
            $options['strict'] = $this->connection->getConfig('fields_strict');
        }
        foreach (['master', 'lock', 'fetch_sql', 'array', 'distinct', 'procedure'] as $name) {
            if (!isset($options[$name])) {
                $options[$name] = false;
            }
        }
        foreach (['group', 'having', 'limit', 'force', 'comment', 'partition', 'duplicate', 'extra'] as $name) {
            if (!isset($options[$name])) {
                $options[$name] = '';
            }
        }
        if (isset($options['page'])) {
            // 根据页数计算limit
            list($page, $listRows) = $options['page'];
            $page = $page > 0 ? $page : 1;
            $listRows = $listRows ?: (is_numeric($options['limit']) ? $options['limit'] : 20);
            $offset = $listRows * ($page - 1);
            $options['limit'] = $offset . ',' . $listRows;
        }
        $this->options = $options;
        return $options;
    }

    /**
     * 分析数据是否存在更新条件
     * @access public
     * @param array $data 数据
     * @return bool
     * @throws Exception
     */
    public function parseUpdateData(&$data)
    {
        $pk = $this->getPk();
        $isUpdate = false;
        // 如果存在主键数据 则自动作为更新条件
        if (is_string($pk) && isset($data[$pk])) {
            $this->where($pk, '=', $data[$pk]);
            $this->options['key'] = $data[$pk];
            unset($data[$pk]);
            $isUpdate = true;
        } elseif (is_array($pk)) {
            foreach ($pk as $field) {
                if (isset($data[$field])) {
                    $this->where($field, '=', $data[$field]);
                    $isUpdate = true;
                } else {
                    // 如果缺少复合主键数据则不执行
                    throw new Exception('miss complex primary data');
                }
                unset($data[$field]);
            }
        }
        return $isUpdate;
    }

    /**
     * 把主键值转换为查询条件 支持复合主键
     * @access public
     * @param array|string $data 主键数据
     * @return void
     * @throws Exception
     */
    public function parsePkWhere($data)
    {
        $pk = $this->getPk();
        if (is_string($pk)) {
            // 获取数据表
            if (empty($this->options['table'])) {
                $this->options['table'] = $this->getTable();
            }
            $table = is_array($this->options['table']) ? key($this->options['table']) : $this->options['table'];
            if (!empty($this->options['alias'][$table])) {
                $alias = $this->options['alias'][$table];
            }
            $key = isset($alias) ? $alias . '.' . $pk : $pk;
            // 根据主键查询
            if (is_array($data)) {
                $this->where($key, 'in', $data);
            } else {
                $this->where($key, '=', $data);
                $this->options['key'] = $data;
            }
        }
    }

    /**
     * 获取模型的更新条件
     * @access protected
     * @param array $options 查询参数
     */
    protected function getModelUpdateCondition(array $options)
    {
        return isset($options['where']['AND']) ? $options['where']['AND'] : null;
    }
}