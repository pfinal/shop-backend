<?php

namespace AdminBundle\Controller;

use AdminBundle\Service\CategoryService;
use Entity\Category;
use Leaf\DB;
use Leaf\Exception\HttpException;
use Leaf\Application;
use Leaf\Json;
use Leaf\Request;
use Leaf\Session;
use Leaf\View;
use Leaf\Redirect;
use Service\UploadTrait;

class CategoryController
{
    use  UploadTrait;

    /**
     * 列表
     * @Route admin/category
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Category');

        if (!empty($search['name'])) {
            $condition[] = 'name like :name';
            $params[':name'] = '%' . $search['name'] . '%';
        }

        $condition = implode(' and ', $condition);

        //查询数据
        $list = CategoryService::findList($condition, $params);

        foreach ($list as $key => $value) {
            $count = count(explode(',', $value->path)) - 2;
            $list[$key]->count = $count;
        }
        //视图
        return View::render('@AdminBundle/category/index.twig', [
            'list' => $list,
        ]);
    }

    /**
     * 新增
     * @Route admin/category/create
     */
    public function create(Request $request)
    {
        $entity = new Category();
        $entity->loadDefaultValues();
        if ($request->get('id') != null) {
            $categoryItem = Category::where('id=?', [$request->get('id')])->findOne();

            if (!empty($categoryItem->property)) {
                $categoryItem->property = @json_decode($categoryItem->property, true);
                if (count($categoryItem->property) > 0) {
                    $categoryItem->property = array_keys($categoryItem->property);
                    $categoryItem->property = implode(",", $categoryItem->property);
                } else {
                    $categoryItem->property = '';
                }
            }

            $entity->property = $categoryItem->property;
            $entity->parent_id = $categoryItem->id;
            $entity->path = $categoryItem->path . $categoryItem->id . ',';
            $entity->childCreate = 'create';
        }
        //保存
        $error = '';
        $data = $request->get('Category');
        if (isset($data['property'])) {
            $propertyArr = [];
            foreach ($data['property'] as $key => $val) {

                $propertyName = self::property(false, $val);

                if (!empty($propertyName)) {
                    $propertyArr[$val] = $propertyName;
                }
            }
            $data['property'] = $propertyArr;
        } else {
            $data['property'] = '';
        }

        $file = self::moveFromTemp($request->get('fileKey'));
        $data['cover'] = $file;

        if ($request->isMethod('post') && CategoryService::create($data, $error)) {
            Session::setFlash('message', '添加成功');
            return Redirect::to('admin/category');
        }

        //获取有哪些状态
        $status = Category::getStatus();
        //获取有哪些属性
        $property = self::property();

        $category = new Category();
        $category->property = Json::encode($data['property']);
        if ($category->property == null) {
            $category->property = '';
        }

        //视图
        return View::render('@AdminBundle/category/create.twig', [
            'entity' => $entity,
            'error' => $error,
            'status' => $status,
            'property' => $property,
            'category' => $category,
        ]);
    }

    /**
     * 更新
     * @Route admin/category/update
     */
    public function update(Request $request, Application $app)
    {
        //查询
        if (($entity = CategoryService::findOne($request->get('id'))) === null) {
            throw new HttpException(500, '操作需要的数据不存在');
        }

        if (!empty($entity->property)) {
            $entity->property = @json_decode($entity->property, true);
            if (count($entity->property) > 0) {
                $entity->property = array_keys($entity->property);
                $entity->property = implode(",", $entity->property);
            } else {
                $entity->property = '';
            }
        }

        //保存
        $error = '';
        $data = $request->get('Category');
        if (isset($data['property'])) {

            $propertyArr = [];
            foreach ($data['property'] as $key => $val) {

                $propertyName = self::property(false, $val);

                if (!empty($propertyName)) {
                    $propertyArr[$val] = $propertyName;
                }
            }
            $data['property'] = $propertyArr;
        } else {
            $data['property'] = '';
        }

        //保存主图
        $fileKey = $request->get('fileKey');

        if ($fileKey) {
            $file = self::moveFromTemp($fileKey);
            $data['cover'] = $file;
        } else {
            $data['cover'] = $entity->cover;
        }

        if ($request->isMethod('post') && CategoryService::update($entity->id, $data, $error)) {
            Session::setFlash('message', '修改成功');
            return Redirect::to('admin/category');
        }

        //获取有哪些状态
        $status = Category::getStatus();
        //获取有哪些属性
        $property = self::property();

        $category = new Category();
        $category->property = Json::encode($data['property']);
        if ($category->property == null) {
            $category->property = '';
        }

        //视图
        return View::render('@AdminBundle/category/update.twig', [
            'entity' => $entity,
            'error' => $error,
            'status' => $status,
            'property' => $property,
            'category' => $category,
        ]);
    }

    /**
     * 删除
     * @Route admin/category/delete
     */
    public function delete(Request $request)
    {
        if (CategoryService::delete($request->get('id'))) {
            Session::setFlash('message', '删除成功');
        } else {
            Session::setFlash('message', '删除失败');
        }
        return Redirect::back();
    }

    /**
     * 判断是否为单属性
     *
     * @Route admin/category/check-one
     */
    public function checkOne(Request $request)
    {
        $id = (int)$request->get('id');

        //查询
        $entity = CategoryService::findOne($id);

        if ($entity === null) {
            return Json::renderWithFalse("分类数据有误，不存在");
        }

        $entity = (array)$entity;

        $property = (string)$entity['property'];

        $property = trim($property);

        if (!$property) {
            return Json::renderWithTrue([
                'one' => 1, // 是否单一属性，1是，0否
            ]);
        }

        return Json::renderWithTrue([
            'one' => 0,
        ]);
    }

    /**
     * 获取有哪些属性
     */
    public function property($returnAll = true, $property = '')
    {
        $map = ['color' => '颜色', 'size' => '尺码', 'version' => '版本'];

        if ($returnAll) {
            return $map;
        }

        return array_key_exists($property, $map) ? $map[$property] : '';
    }

    /**
     * @Route admin/category/upload
     */
    public function upload()
    {
        return Json::render($this->uploadToTemp('file'));
    }

}