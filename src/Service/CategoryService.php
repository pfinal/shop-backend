<?php

namespace AdminBundle\Service;

use Leaf\DB;
use Leaf\Json;
use Leaf\Log;
use Leaf\Pagination;
use Leaf\Validator;

use Entity\Category;
use PFinal\Database\Expression;

/**
 * 分类
 * @author  leafphp curd generator
 * @since   1.0
 */
class CategoryService
{
    /**
     * 查询
     *
     * @param string $condition 查询条件
     * @param array $params
     * @return Category[]
     */
    public static function findList($condition = '', $params = [])
    {
        $query = DB::table(Category::tableName())
            ->asEntity(Category::className())
            ->where($condition, $params)
            ->where('status<>?', [Category::STATUS_DELETE])
            ->orderBy(new Expression('concat(path,id)'));

        return $query->findAll();
    }

    /**
     * 根据主键查询单条
     * @param $id
     * @return Category|null
     */
    public static function findOne($id)
    {
        return DB::table(Category::tableName())
            ->asEntity(Category::className())
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
            [['name',], 'required'],
            [['parent_id', 'path', 'status', 'sort', 'property', 'cover',], 'safe'],
            [['name',], 'string', 'length' => [1, 17], 'tooLong' => '名称不能超过17位'],
            ['cover', 'string', 'length' => [0, 255]]
        ];
        if (!Validator::validate($data, $rule, Category::labels())) {
            $error = Validator::getFirstError();
            return 0;
        }

        // 验证pid是否存在
        if ($data['parent_id']) {
            $pid = $data['parent_id'];

            $parentInfo = CategoryService::findOne($pid);
            if ($parentInfo == null) {
                $error = '父级分类不存在';
                return false;
            }

            if ($parentInfo['status'] == Category::STATUS_DELETE) {
                $error = '父级分类已被删除';
                return false;
            }

            $checkPath = $parentInfo['path'] . $parentInfo['id'] . ',';

            if ($checkPath != $data['path']) {
                $error = '父级分类有误';
                return false;
            }
        }

        if (!empty($data['property'])) {
            $propertyKeyList = Category::propertyList();
            foreach ($data['property'] as $propertyKey => $propertyValue) {
                if (!array_key_exists($propertyKey, $propertyKeyList)) {
                    $error = '属性非法，必须在限定属性内';
                    return false;
                }
            }

            $data['property'] = Json::encode($data['property']);
        } else {
            $data['property'] = "";
        }

        $data['created_at'] = $data['updated_at'] = date('Y-m-d H:i:s');

        if (($id = DB::table(Category::tableName())->insertGetId($data)) > 0) {
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
            [['name',], 'required'],
            [['parent_id', 'path', 'status', 'sort', 'property', 'cover',], 'safe'],
            [['name',], 'string', 'length' => [1, 17], 'tooLong' => '名称不能超过17位'],
            ['cover', 'string', 'length' => [0, 255]],
        ];
        if (!Validator::validate($data, $rule)) {
            $error = Validator::getFirstError();
            return false;
        }

        if (!empty($data['property'])) {

            $propertyKeyList = Category::propertyList();
            foreach ($data['property'] as $propertyKey => $propertyValue) {
                if (!array_key_exists($propertyKey, $propertyKeyList)) {
                    $error = '属性非法，必须在限定属性内';
                    return false;
                }
            }

            $data['property'] = Json::encode($data['property']);
        } else {
            $data['property'] = "";
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        //更新
        if (DB::table(Category::tableName())->where('id = ?', [$id])->update($data)) {
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
        $categoryList = DB::table(Category::tableName())
            ->where('status != ?', [Category::STATUS_DELETE])
            ->where('parent_id=?', [$id])
            ->findAll();
        if (count($categoryList) > 0) {
            return false;
        }
        return DB::table(Category::tableName())->where('id = ?', [$id])->update(['status' => Category::STATUS_DELETE]) == 1;
    }

}