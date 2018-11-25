<?php

namespace AdminBundle\Controller;

use AdminBundle\Service\CarrierService;
use Entity\Carrier;
use Leaf\Exception\HttpException;
use Leaf\Application;
use Leaf\Pagination;
use Leaf\Request;
use Leaf\Session;
use Leaf\View;
use Leaf\Redirect;

class CarrierController
{
    /**
     * 列表
     * @Route admin/carrier
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Carrier');

        if (!empty($search['name'])) {
            $condition[] = 'name like :name';
            $params[':name'] = '%' . $search['name'] . '%';
        }

        $condition = implode(' and ', $condition);

        //分页
        $page = new Pagination();

        //查询数据
        $list = CarrierService::findList($page, $condition, $params, $request->get('sort', '-id'));

        //视图
        return View::render('@AdminBundle/carrier/index.twig', [
            'list' => $list,
            'page' => $page,
        ]);
    }

    /**
     * 新增
     * @Route admin/carrier/create
     */
    public function create(Request $request)
    {
        $entity = new Carrier();
        $entity->loadDefaultValues();

        //保存
        $error = '';
        if ($request->isMethod('post') && CarrierService::create($request->get('Carrier'), $error)) {
            Session::setFlash('message', '添加成功');
            return Redirect::to('admin/carrier');
        }

        //视图
        return View::render('@AdminBundle/carrier/create.twig', [
            'entity' => $entity,
            'error' => $error,
        ]);
    }

    /**
     * 更新
     * @Route admin/carrier/update
     */
    public function update(Request $request, Application $app)
    {
        //查询
        if (($entity = CarrierService::findOne($request->get('id'))) === null) {
            throw new HttpException(500, '操作需要的数据不存在');
        }

        //保存
        $error = '';
        if ($request->isMethod('post') && CarrierService::update($entity->id, $request->get('Carrier'), $error)) {
            Session::setFlash('message', '修改成功');
            return Redirect::to('admin/carrier');
        }

        //视图
        return View::render('@AdminBundle/carrier/update.twig', [
            'entity' => $entity,
            'error' => $error,
        ]);
    }

    /**
     * 删除
     * @Route admin/carrier/delete
     */
    public function delete(Request $request)
    {
        if (CarrierService::delete($request->get('id'))) {
            Session::setFlash('message', '删除成功');
        } else {
            Session::setFlash('message', '删除失败');
        }
        return Redirect::back();
    }
}