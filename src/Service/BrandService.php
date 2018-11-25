<?php

namespace AdminBundle\Service;

use Entity\User;
use Leaf\Application;
use Leaf\DB;
use Leaf\Log;
use Leaf\Pagination;
use Leaf\Session;
use Leaf\Util;
use Leaf\Validator;

use Entity\Brand;

/**
 * 品牌
 * @author  leafphp curd generator
 * @since   1.0
 */
class BrandService
{
    /**
     * 排序分页查询
     *
     * @param Pagination|null $page 分页对象
     * @param string $condition 查询条件
     * @param array $params
     * @param string $order
     * @return Brand[]
     */
    public static function findList(Pagination $page = null, $condition = '', $params = [], $order = '-id')
    {
        $query = DB::table(Brand::tableName())
            ->asEntity(Brand::className())
            ->where($condition, $params)
            ->where('status<>?', [Brand::STATUS_DELETE])
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
     * @return Brand|null
     */
    public static function findOne($id)
    {
        return DB::table(Brand::tableName())
            ->asEntity(Brand::className())
            ->findByPk($id);
    }

    /**
     * 新增
     * @param array $data
     * @param string $error
     * @return int 成功返回自增id，失败返回0
     */
    public static function create($data, &$error = '')
    {
        //验证
        if (!Validator::validate($data, self::getRule(), Brand::labels())) {
            $error = Validator::getFirstError();
            return 0;
        }

        $data['created_at'] = $data['updated_at'] = date('Y-m-d H:i:s');

        if (($id = DB::table(Brand::tableName())->insertGetId($data)) > 0) {
            return $id;
        } else {
            $error = '系统错误';
            return 0;
        }
    }

    protected static function getRule()
    {
        return [
            [['name',], 'required'],
            [['status', 'sort', 'description', 'logo'], 'safe'],
            [['name', 'description'], 'string'],
        ];
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
        if (!Validator::validate($data, self::getRule(), Brand::labels())) {
            $error = Validator::getFirstError();
            return false;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        //更新
        if (DB::table(Brand::tableName())->where('id = ?', [$id])->update($data)) {
            return true;
        } else {
            $error = '系统错误';
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
        return DB::table(Brand::tableName())->where('id = ?', [$id])->update(['status' => Brand::STATUS_DELETE]) == 1;
    }

    /**
     * 更新头像
     */
    protected static function updateAvatar($id, $avatar, &$error = '')
    {
        if (empty($avatar)) {
            return false;
        }

        $data['logo'] = $avatar;
        $data['updated_at'] = date('Y-m-d H:i:s');

        //更新
        if (DB::table(Brand::tableName())->where('id = ?', [$id])->update($data)) {
            return true;
        } else {
            $error = '系统错误';
            return false;
        }
    }
}