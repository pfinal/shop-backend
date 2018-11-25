<?php

namespace AdminBundle\Service;

use Leaf\DB;
use Leaf\Log;
use Leaf\Pagination;
use Leaf\Validator;
use Entity\ReturnOrder;

/**
 * 退货
 * @author  leafphp curd generator
 * @since   1.0
 */
class ReturnOrderService
{
    /**
     * 排序分页查询
     *
     * @param Pagination|null $page 分页对象
     * @param string $condition 查询条件
     * @param array $params
     * @param string $order
     * @return ReturnOrder[]
     */
    public static function findList(Pagination $page = null, $condition = '', $params = [], $order = '-id')
    {
        $query = DB::table(ReturnOrder::tableName())
            ->asEntity(ReturnOrder::className())
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
     * @return ReturnOrder|null
     */
    public static function findOne($id)
    {
        return DB::table(ReturnOrder::tableName())
            ->asEntity(ReturnOrder::className())
            ->findByPk($id);
    }

    /**
     * 删除
     * @param $id
     * @return bool
     */
    public static function delete($id)
    {
        return DB::table(ReturnOrder::tableName())->where('id = ?', [$id])->delete() == 1;
    }

}