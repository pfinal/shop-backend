<?php

namespace AdminBundle\Service;

use Leaf\DB;
use Leaf\Log;
use Leaf\Pagination;
use Leaf\Validator;

use Entity\Carrier;

/**
 * 物流公司
 * @author  leafphp curd generator
 * @since   1.0
 */
class CarrierService
{
    /**
     * 排序分页查询
     *
     * @param Pagination|null $page 分页对象
     * @param string $condition 查询条件
     * @param array $params
     * @param string $order
     * @return Carrier[]
     */
    public static function findList(Pagination $page = null, $condition = '', $params = [], $order = '-id')
    {
        $query = DB::table(Carrier::tableName())
            ->asEntity(Carrier::className())
            ->where($condition, $params)
            ->orderBy($order);

        if (is_null($page)) {
            return $query->findAll();
        }

        $queryCount = clone $query;
        $page->itemCount = $queryCount->count();

        return $query->limit($page->limit)->findAll();
    }

    /**
     * 根据主键查询单条
     * @param $id
     * @return Carrier|null
     */
    public static function findOne($id)
    {
        return DB::table(Carrier::tableName())
            ->asEntity(Carrier::className())
            ->findByPk($id);
    }

    /**
     * 新增
     * @param array $data
     * @param string $error
     * @return string 成功返回自增id，失败返回0
     */
    public static function create($data, &$error = '')
    {
        //验证
        $rule = [
            [['name', 'phone', 'status', 'code'], 'safe'],
        ];
        if (!Validator::validate($data, $rule, Carrier::labels())) {
            $error = Validator::getFirstError();
            return 0;
        }

        $data['created_at'] = $data['updated_at'] = date('Y-m-d H:i:s');

        if (($id = DB::table(Carrier::tableName())->insertGetId($data)) > 0) {
            return $id;
        } else {
            $error = '系统错误';
            Log::error("注册用户失败: " . DB::getConnection()->getLastSql());
            return 0;
        }
    }

    /**
     * 根据主键修改单条
     * @param $id
     * @param array $data
     * @param string $error
     * @return bool
     */
    public static function update($id, $data, &$error = '')
    {
        //验证
        $rule = [
            [['name', 'phone', 'status', 'code'], 'safe'],
        ];
        if (!Validator::validate($data, $rule)) {
            $error = Validator::getFirstError();
            return false;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        //更新
        if (DB::table(Carrier::tableName())->where('id = ?', [$id])->update($data)) {
            return true;
        } else {
            $error = '系统错误';
            Log::error("修改失败: " . DB::getConnection()->getLastSql());
            return false;
        }
    }

    /**
     * 删除
     * @param $id
     * @return bool
     */
    public static function delete($id)
    {
        return DB::table(Carrier::tableName())->where('id = ?', [$id])->delete() == 1;
    }

}