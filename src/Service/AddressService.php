<?php

namespace AdminBundle\Service;

use Entity\Address;
use Leaf\DB;

/**
 * 地址管理
 * @author  Wang Manyuan
 * @since   1.0
 */
class AddressService
{
    /**
     * 根据ID查找对应会员名下的地址信息
     *
     * @param int $userId 会员ID
     * @param $id
     * @return Address|null
     */
    public static function findOneByMemberAndId($userId, $id)
    {
        return DB::table(Address::tableName())
            ->asEntity(Address::className())
            ->where('user_id = ?', [$userId])
            ->where('status != ?', [Address::STATUS_DELETE])
            ->findByPk($id);
    }
}