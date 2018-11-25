<?php

namespace AdminBundle\Controller;

use Carbon\Carbon;
use Entity\Mix;
use Entity\MixProduct;
use Entity\Product;
use Leaf\Application;
use Leaf\DB;
use Leaf\Json;
use Leaf\Pagination;
use Leaf\Request;
use Leaf\Session;
use Leaf\Url;
use Leaf\Validator;
use Leaf\View;
use Leaf\Redirect;
use Service\UploadTrait;

class MixController
{
    use UploadTrait;

    /**
     * 列表
     * @Route admin/mix
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Mix');

        if (!empty($search['id'])) {
            $condition[] = 'id = :id';
            $params[':id'] = $search['id'];
        }

        //数据
        $dataProvider = DB::table(Mix::tableName())
            ->where(implode(' and ', $condition), $params)
            ->asEntity(Mix::className())
            ->where('status != ?', [Mix::STATUS_DELETE])
            ->orderBy($request->get('sort', 'id desc'))
            ->paginate();

        //视图
        return View::render('@AdminBundle/mix/index.twig', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * 新增
     * @Route admin/mix/create
     */
    public function create(Request $request)
    {
        $entity = new Mix();
        $entity->loadDefaultValues();

        //保存
        $error = '';
        if ($request->isMethod('POST')) {

            $data = $request->get('Mix');

            $fileKey = $request->get('fileKey');
            $file = self::moveFromTemp($fileKey);

            if (self::checkData($data, $file, $error)) {
                $data['icon'] = $file;

                $data['created_at'] = $data['updated_at'] = Carbon::now();

                if (DB::table(Mix::tableName())->insert($data)) {
                    Session::setFlash('message', '添加成功');
                    return Redirect::to('admin/mix');
                } else {
                    $error = '系统错误';
                }
            }
        }

        //视图
        return View::render('@AdminBundle/mix/create.twig', [
            'entity' => $entity,
            'error' => $error,
        ]);
    }

    /**
     * 更新
     * @Route admin/mix/update
     */
    public function update(Request $request, Application $app)
    {
        //查询
        $entity = DB::table(Mix::tableName())
            ->asEntity(Mix::className())
            ->findByPkOrFail($request->get('id'));

        //保存
        $error = '';
        if ($request->isMethod('POST')) {

            $data = $request->get('Mix');

            $fileKey = $request->get('fileKey');
            $file = self::moveFromTemp($fileKey);

            //验证
            if (self::checkData($data, $file, $error)) {
                if ($file) {
                    $data['icon'] = $file;
                }

                $data['updated_at'] = Carbon::now();

                //更新
                if (DB::table(Mix::tableName())->wherePk($entity->id)->update($data)) {
                    Session::setFlash('message', '修改成功');
                    return Redirect::to('admin/mix');
                } else {
                    $error = '系统错误';
                }
            }
        }

        //视图
        return View::render('@AdminBundle/mix/update.twig', [
            'entity' => $entity,
            'error' => $error,
        ]);
    }

    /**
     * 删除
     * @Route admin/mix/delete
     * @Method post
     */
    public function delete(Request $request)
    {
        $result = DB::table(Mix::tableName())
            ->wherePk($request->get('id'))
            ->update(['status' => Mix::STATUS_DELETE]);

        if ($result) {
            Session::setFlash('message', '删除成功');
        } else {
            Session::setFlash('message', '删除失败');
        }

        return Redirect::back();
    }

    /**
     * 为 分组 的 新增、更新 验证数据
     * @param $data
     * @param $file
     * @param string $error
     * @return bool
     */
    private function checkData($data, $file, &$error = '')
    {
        if (!Validator::validate($data, self::getRules('create'), Mix::labels())) {
            $error = Validator::getFirstError();
            return false;
        }

        // 验证名称和图片缺一不可
        if ((!$data['name']) && (!$file)) {
            $error = '名称和icon必须填写一个';
            return false;
        }

        return true;
    }

    /**
     * 验证规则
     * @param string $scene create|update
     * @return array
     */
    protected function getRules($scene)
    {
        $rules = [
            [['name',], 'safe'],
        ];

        return $rules;
    }

    /**
     * 使用jquery-file-upload插件上传图片
     * @Route admin/mix/upload
     */
    public function upload()
    {
        return Json::render($this->uploadToTemp('file'));
    }

    /**
     * 商品管理
     *
     * @Route admin/mix/product
     */
    public function product(Request $request)
    {
        $mixId = (int)$request->get('mix_id');

        $mix = DB::table(Mix::tableName())
            ->asEntity(Mix::className())
            ->where('status != ?', [Mix::STATUS_DELETE])
            ->findByPkOrFail($mixId);

        $condition = [];
        $params = [];

        $condition = join(' and ', $condition);

        // 商品
        $from = 'FROM %s AS product LEFT JOIN %s AS mix_product ON product.id = mix_product.product_id ';
        $from = sprintf($from, Product::tableName(), MixProduct::tableName());

        $from .= ' WHERE mix_product.mix_id = ? and product.status != ?';

        if (!empty($condition)) {
            $from .= 'and (' . $condition . ')';
        }

        $params = array_merge([$mixId, Product::STATUS_DELETE], $params);

        $order = 'mix_product.id desc,mix_product.sort asc';

        $page = new Pagination();

        $page->itemCount = DB::getConnection()->queryScalar('SELECT COUNT(*) ' . $from, $params);

        $sql = 'SELECT product.*,mix_product.sort as psort,mix_product.id as mp_id ' . $from . ' ORDER BY ' . $order . '  LIMIT ' . $page->limit;

        $list = DB::table('')->asEntity(Product::className())->findAllBySql($sql, $params);

        //视图
        return View::render('@AdminBundle/mix/product.twig', [
            'list' => $list,
            'page' => $page,
            'mix' => $mix,
        ]);
    }

    /**
     * 分组商品新增
     *
     * @Route admin/mix/product-create
     */
    public function productCreate(Request $request)
    {
        $mixId = (int)$request->get('mix_id');

        $mix = DB::table(Mix::tableName())
            ->asEntity(Mix::className())
            ->where('status != ?', [Mix::STATUS_DELETE])
            ->findByPkOrFail($mixId);

        //保存
        $error = '';
        if ($request->isMethod('POST')) {

            $data = $request->get('MixProduct');

            if (!self::checkUnique($mixId, $data['product_id'], 0)) {
                $error = '该分组下已存在该商品，不可重复';
            } else {
                if (Validator::validate($data, self::getMixProductRules('create'), Mix::labels())) {

                    $data['mix_id'] = $mixId;
                    $data['created_at'] = $data['updated_at'] = Carbon::now();

                    DB::table(MixProduct::tableName())->insert($data);

                    Session::setFlash('message', '添加成功');
                    return Redirect::to(Url::to('admin/mix/product', ['mix_id' => $mixId]));
                } else {
                    $error = Validator::getFirstError();
                }
            }
        }

        $entity = new MixProduct();
        $entity->loadDefaultValues();

        //视图
        return View::render('@AdminBundle/mix/product-create.twig', [
            'mix' => $mix,
            'entity' => $entity,
            'error' => $error,
        ]);
    }

    /**
     * 分组商品 更新
     *
     * @Route admin/mix/product-update
     */
    public function productUpdate(Request $request)
    {
        $id = (int)$request->get('id');

        $entity = DB::table(MixProduct::tableName())
            ->asEntity(MixProduct::className())
            ->findByPkOrFail($id);

        $mix = DB::table(Mix::tableName())
            ->asEntity(Mix::className())
            ->where('status != ?', [Mix::STATUS_DELETE])
            ->findByPkOrFail($entity['mix_id']);

        //保存
        $error = '';
        if ($request->isMethod('POST')) {

            $data = $request->get('MixProduct');

            if (Validator::validate($data, self::getMixProductRules('create'), Mix::labels())) {

                if (!self::checkUnique($mix['id'], $data['product_id'], $entity->id)) {
                    $error = '该分组下已存在该商品，不可重复';
                } else {
                    $data['updated_at'] = Carbon::now();

                    $rows = DB::table(MixProduct::tableName())
                        ->wherePk($entity->id)
                        ->update($data);

                    if ($rows == 1) {
                        Session::setFlash('message', '保存成功');
                        return Redirect::to(Url::to('admin/mix/product', ['mix_id' => $entity['mix_id']]));
                    } else {
                        $error = '系统错误';
                    }
                }
            } else {
                $error = Validator::getFirstError();
            }
        }

        //视图
        return View::render('@AdminBundle/mix/product-update.twig', [
            'mix' => $mix,
            'entity' => $entity,
            'error' => $error,
        ]);
    }

    /**
     * 检测分组ID和商品ID是否唯一
     *
     * 唯一返回true；否则返回false
     *
     * @param int $mixId
     * @param int $productId
     * @param int $id
     * @return bool
     */
    private function checkUnique($mixId, $productId, $id = 0)
    {
        $condition = [];
        $param = [];

        if ($id) {
            $condition[] = 'id != ?';
            $param[] = $id;
        }

        $count = DB::table(MixProduct::tableName())
            ->where('mix_id = ? and product_id = ?', [
                $mixId,
                $productId
            ])
            ->where(join(' and ', $condition), $param)
            ->count();

        if ($count > 0) {
            return false;
        }

        return true;
    }

    /**
     * 分组删除
     *
     * @Route admin/mix/product-delete
     */
    public function productDelete(Request $request)
    {
        $id = (int)$request->get('id');

        $rows = DB::table(MixProduct::tableName())
            ->wherePk($id)
            ->delete();

        if ($rows == 1) {
            Session::setFlash("message", "删除成功");
        } else {
            Session::setFlash('message', '删除失败');
        }

        return Redirect::back();
    }

    /**
     * 验证规则
     * @param string $scene create|update
     * @return array
     */
    protected function getMixProductRules($scene)
    {
        $rules = [
            [['product_id', 'sort',], 'trim'],
            [['product_id', 'sort',], 'required'],
            [['product_id', 'sort',], 'integer'],
        ];

        return $rules;
    }

}
