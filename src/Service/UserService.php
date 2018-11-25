<?php

namespace AdminBundle\Service;

use Carbon\Carbon;
use Leaf\Cache;
use Leaf\DB;
use Leaf\Log;
use Leaf\Pagination;

use Entity\User;

/**
 * 用户
 * @author  leafphp Geng Chengguang
 * @since   1.0
 */
class UserService
{
    /**
     * 排序分页查询
     *
     * @param Pagination|null $page 分页对象
     * @param string $condition 查询条件
     * @param array $params
     * @param string $order
     * @return User[]
     */
    public static function findList(Pagination $page = null, $condition = '', $params = [], $order = '-id')
    {
        $query = DB::table(User::tableName())
            ->where('status != ?', [User::STATUS_DEL])
            ->asEntity(User::className())
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
     * 删除
     * @param $id
     * @return bool
     */
    public static function delete($id)
    {
        return DB::table(User::tableName())->where('id = ?', [$id])->update(['status' => User::STATUS_DEL, 'updated_at' => Carbon::now()]) == 1;
    }

    /**
     * 根据ID查询用户信息
     *
     * 提供给关联的数据表查询用户信息
     *
     * @param $id
     * @return User|null
     */
    public static function findOneInAllStatus($id)
    {
        $cacheKey = 'user:' . $id;

        $user = Cache::get($cacheKey, false);

        if ($user === false) {
            $user = DB::table(User::tableName())
                ->asEntity(User::className())
                ->field(['id', 'username', 'nickname', 'avatar', 'email', 'mobile', 'point', 'money'])
                ->findByPk($id);

            Cache::set($cacheKey, $user, 1 * 60); // 缓存1分钟
        }

        return $user;
    }

}