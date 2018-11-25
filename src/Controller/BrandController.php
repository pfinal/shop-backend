<?php

namespace AdminBundle\Controller;

use AdminBundle\Service\BrandService;
use Entity\Brand;
use Leaf\Exception\HttpException;
use Leaf\Application;
use Leaf\Json;
use Leaf\Pagination;
use Leaf\Request;
use Leaf\Session;
use Leaf\View;
use Leaf\Redirect;
use Service\UploadTrait;

class BrandController
{
    use UploadTrait;

    /**
     * 列表
     * @Route admin/brand
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Brand');

        if (!empty($search['name'])) {
            $condition[] = 'name like :name';
            $params[':name'] = '%' . $search['name'] . '%';
        }

        $page = new Pagination();
        $condition = implode(' and ', $condition);
        $list = BrandService::findList($page, $condition, $params, $request->get('sort', '-id'));

        //视图
        return View::render('@AdminBundle/brand/index.twig', [
            'list' => $list,
            'page' => $page,
        ]);
    }

    /**
     * 新增
     * @Route admin/brand/create
     */
    public function create(Request $request)
    {
        $entity = new Brand();
        $entity->loadDefaultValues();

        //保存
        $error = '';
        if ($request->isMethod('post')) {

            $data = $request->get('Brand');

            $data['logo'] = $this->moveFromTemp($request->get('fileKey'));

            if (BrandService::create($data, $error)) {
                Session::setFlash('message', '添加成功');
                return Redirect::to('admin/brand');
            }
        }

        //视图
        return View::render('@AdminBundle/brand/create.twig', [
            'entity' => $entity,
            'error' => $error,
            'status' => Brand::getStatus(),
        ]);
    }

    /**
     * 更新
     * @Route admin/brand/update
     */
    public function update(Request $request, Application $app)
    {
        //查询
        if (($entity = BrandService::findOne($request->get('id'))) === null) {
            throw new HttpException(500, '操作需要的数据不存在');
        }

        //保存
        $error = '';
        if ($request->isMethod('post')) {

            $data = $request->get('Brand');

            $data['logo'] = $this->moveFromTemp($request->get('fileKey'));
            if (empty($data['logo'])) {
                $data['logo'] = $entity['logo'];
            }

            if (BrandService::update($entity->id, $data, $error)) {

                Session::setFlash('message', '修改成功');
                return Redirect::to('admin/brand');
            }
        }

        //视图
        return View::render('@AdminBundle/brand/update.twig', [
            'entity' => $entity,
            'error' => $error,
            'status' => Brand::getStatus(),
        ]);
    }

    /**
     * 删除
     * @Route admin/brand/delete
     */
    public function delete(Request $request)
    {
        if (BrandService::delete($request->get('id'))) {
            Session::setFlash('message', '删除成功');
        } else {
            Session::setFlash('message', '删除失败');
        }
        return Redirect::back();
    }

    /**
     * @Route admin/brand/upload
     */
    public function upload(Application $app)
    {
        //上传到临时目录
        return Json::render($this->uploadToTemp('file'));
    }
}