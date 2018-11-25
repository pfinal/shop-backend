<?php

namespace AdminBundle\Controller;

use Carbon\Carbon;
use Entity\Product;
use Entity\Tag;
use Entity\TagProduct;
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

class TagController
{
    use UploadTrait;

    /**
     * 列表
     * @Route admin/tag
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Tag');

        if (!empty($search['id'])) {
            $condition[] = 'id = :id';
            $params[':id'] = $search['id'];
        }

        //if (!empty($search['name'])) {
        //    $condition[] = 'name like :name';
        //    $params[':name'] = '%' . trim($search['name']) . '%';
        //}

        //数据
        $dataProvider = DB::table(Tag::tableName())
            ->where(implode(' and ', $condition), $params)
            ->asEntity(Tag::className())
            ->where('status != ?', [Tag::STATUS_DELETE])
            ->orderBy($request->get('sort', 'id desc'))
            ->paginate();

        //视图
        return View::render('@AdminBundle/tag/index.twig', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * 新增
     * @Route admin/tag/create
     */
    public function create(Request $request)
    {
        $entity = new Tag();
        $entity->loadDefaultValues();

        //保存
        $error = '';
        if ($request->isMethod('POST')) {

            $data = $request->get('Tag');

            $fileKey = $request->get('fileKey');
            $file = self::moveFromTemp($fileKey);

            if (Validator::validate($data, self::getRules('create'), Tag::labels())) {

                if ($file) {
                    $data['icon'] = $file;
                }

                $data['status'] = Tag::STATUS_NORMAL;
                $data['created_at'] = $data['updated_at'] = Carbon::now();

                DB::table(Tag::tableName())->insert($data);

                Session::setFlash('message', '添加成功');
                return Redirect::to('admin/tag');
            } else {
                $error = Validator::getFirstError();
            }
        }

        //视图
        return View::render('@AdminBundle/tag/create.twig', [
            'entity' => $entity,
            'error' => $error,
        ]);
    }

    /**
     * 更新
     * @Route admin/tag/update
     */
    public function update(Request $request, Application $app)
    {
        //查询
        $entity = DB::table(Tag::tableName())
            ->asEntity(Tag::className())
            ->findByPkOrFail($request->get('id'));

        //保存
        $error = '';
        if ($request->isMethod('POST')) {

            $data = $request->get('Tag');
            $fileKey = $request->get('fileKey');
            $file = self::moveFromTemp($fileKey);

            //验证
            if (Validator::validate($data, self::getRules('update'))) {
                if ($file) {
                    $data['icon'] = $file;
                }

                $data['updated_at'] = Carbon::now();

                //更新
                if (DB::table(Tag::tableName())->wherePk($entity->id)->update($data)) {
                    Session::setFlash('message', '修改成功');
                    return Redirect::to('admin/tag');
                } else {
                    $error = '系统错误';
                }

            } else {
                $error = Validator::getFirstError();
            }
        }

        //视图
        return View::render('@AdminBundle/tag/update.twig', [
            'entity' => $entity,
            'error' => $error,
        ]);
    }

    /**
     * 删除
     * @Route admin/tag/delete
     * @Method post
     */
    public function delete(Request $request)
    {
        $result = DB::table(Tag::tableName())
            ->wherePk($request->get('id'))
            ->update(['status' => Tag::STATUS_DELETE, 'updated_at' => Carbon::now()]);

        if ($result) {
            Session::setFlash('message', '删除成功');
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
    protected function getRules($scene)
    {
        $rules = [
            [['name', 'sort',], 'trim'],
            [['name', 'sort',], 'required'],
            [['name',], 'string', 'length' => [1, 100]],
            [['sort',], 'integer'],
        ];

        return $rules;
    }

    /**
     * 使用jquery-file-upload插件上传图片
     * @Route admin/tag/upload
     */
    public function upload()
    {
        return Json::render($this->uploadToTemp('file'));
    }

    /**
     * 商品管理
     *
     * @Route admin/tag/product
     */
    public function product(Request $request)
    {
        $tagId = (int)$request->get('tag_id');

        $tag = DB::table(Tag::tableName())
            ->asEntity(Tag::className())
            ->where('status != ?', [Tag::STATUS_DELETE])
            ->findByPkOrFail($tagId);

        $condition = [];
        $params = [];

        $condition = join(' and ', $condition);

        // 商品
        $from = 'FROM %s AS product LEFT JOIN %s AS tag_product ON product.id = tag_product.product_id ';
        $from = sprintf($from, Product::tableName(), TagProduct::tableName());

        $from .= ' WHERE tag_product.tag_id = ? and product.status != ?';

        if (!empty($condition)) {
            $from .= 'and (' . $condition . ')';
        }

        $params = array_merge([$tagId, Product::STATUS_DELETE], $params);

        $order = 'product.id desc';

        $page = new Pagination();

        $page->itemCount = DB::getConnection()->queryScalar('SELECT COUNT(*) ' . $from, $params);

        $sql = 'SELECT product.* ' . $from . ' ORDER BY ' . $order . '  LIMIT ' . $page->limit;

        $list = DB::table('')->asEntity(Product::className())->findAllBySql($sql, $params);

        //视图
        return View::render('@AdminBundle/tag/product.twig', [
            'list' => $list,
            'page' => $page,
            'tag' => $tag,
        ]);
    }

    /**
     * 新增 标签关联商品
     *
     * @Route admin/tag/product-create
     */
    public function productCreate(Request $request)
    {
        $tagId = (int)$request->get('tag_id');

        $tag = DB::table(Tag::tableName())
            ->asEntity(Tag::className())
            ->where('status != ?', [Tag::STATUS_DELETE])
            ->findByPkOrFail($tagId);

        $error = '';

        if ($request->isMethod('POST')) {

            $data = $request->get('TagProduct');

            $rule = [
                [['product_id',], 'trim'],
                [['product_id',], 'required'],
                [['product_id',], 'integer'],
            ];

            if (Validator::validate($data, $rule)) {

                $product = DB::table(Product::tableName())
                    ->findByPk($data['product_id']);

                if ($product) {

                    DB::table(TagProduct::tableName())
                        ->where('product_id = ?', [$data['product_id']])
                        ->delete();

                    $data['tag_id'] = $tagId;

                    DB::table(TagProduct::tableName())->insert($data);

                    Session::setFlash('message', '添加成功');
                    return Redirect::to(Url::to('admin/tag/product', ['tag_id' => $tagId]));

                } else {
                    $error = '商品不存在';
                }

            } else {
                $error = Validator::getFirstError();
            }
        }

        //视图
        return View::render('@AdminBundle/tag/product-create.twig', [
            'tag' => $tag,
            'error' => $error,
        ]);
    }

    /**
     * 删除 标签关联商品
     *
     * @Route admin/tag/product-delete
     */
    public function productDelete(Request $request)
    {
        $tagId = (int)$request->get('tag_id');
        $productId = (int)$request->get('product_id');

        $row = DB::table(TagProduct::tableName())
            ->where('product_id = ? and tag_id = ?', [$productId, $tagId])
            ->delete();

        if ($row) {
            Session::setFlash('message', '删除成功');
        } else {
            Session::setFlash('message', '删除失败');
        }

        return Redirect::back();
    }

}
