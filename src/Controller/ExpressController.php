<?php

namespace AdminBundle\Controller;

use Entity\Express;
use Leaf\Application;
use Leaf\DB;
use Leaf\Request;
use Leaf\Session;
use Leaf\View;
use Leaf\Redirect;
use Service\Auth;
use Service\ExpressService;
use Service\RegionService;

/**
 * 运费模板
 */
class ExpressController
{
    /**
     * 列表
     * @Route admin/express
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Express');

        if (!empty($search['id'])) {
            $condition[] = 'id = :id';
            $params[':id'] = $search['id'];
        }

        //数据
        $dataProvider = DB::table(Express::tableName())
            ->where(implode(' and ', $condition), $params)
            ->where('status != ?', [Express::STATUS_DELETE])
            ->asEntity(Express::className())
            ->orderBy($request->get('sort', 'id desc'))
            ->paginate(6);

        //视图
        return View::render('@AdminBundle/express/index.twig', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * 新增
     * @Route admin/express/create
     */
    public function create(Request $request)
    {
        $entity = new Express();
        $entity->loadDefaultValues();

        $userId = Auth::getId();

        //保存
        $error = '';
        if ($request->isMethod('POST')) {
            $expressData = $request->get('Express', []);
            $expressWayCommonData = $request->get('ExpressWayCommon', []);
            $expressWayAppointData = $request->get('ExpressWayAppoint', []);
            $expressFreeData = $request->get('ExpressFree', []);

            if (ExpressService::create($userId, $expressData, $expressWayCommonData, $expressWayAppointData, $expressFreeData, $error)) {
                Session::setFlash('message', '新增成功');
                return Redirect::to('admin/express');
            }
        }

        // 地区
        $regionList = RegionService::findParentList();

        //视图
        return View::render('@AdminBundle/express/create.twig', [
            'entity' => $entity,
            'error' => $error,

            'regionList' => $regionList,
        ]);
    }

    /**
     * 更新
     * @Route admin/express/update
     */
    public function update(Request $request, Application $app)
    {
        $userId = Auth::getId();

        $id = (int)$request->get('id');

        //查询
        $entity = DB::table(Express::tableName())
            ->asEntity(Express::className())
            ->findByPkOrFail($id);

        //保存
        $error = '';
        if ($request->isMethod('POST')) {
            $expressData = $request->get('Express', []);
            $expressWayCommonData = $request->get('ExpressWayCommon', []);
            $expressWayAppointData = $request->get('ExpressWayAppoint', []);
            $expressFreeData = $request->get('ExpressFree', []);

            if (ExpressService::update($userId, $id, $expressData, $expressWayCommonData, $expressWayAppointData, $expressFreeData, $error)) {
                Session::setFlash('message', '保存成功');
                return Redirect::to('admin/express');
            }
        }

        // 地区
        $regionList = RegionService::findParentList();

        //视图
        return View::render('@AdminBundle/express/update.twig', [
            'entity' => $entity,
            'error' => $error,

            'regionList' => $regionList,
        ]);
    }

    /**
     * 删除
     * @Route admin/express/delete
     * @Method post
     */
    public function delete(Request $request)
    {
//        $id = (int)$request->get('id');
//
//        $error = '';
//
//        if (ExpressService::delete($id, $error)) {
//            Session::setFlash('message', '删除成功');
//        } else {
//            Session::setFlash('message', $error);
//        }

        Session::setFlash('message', '测试中');

        return Redirect::back();
    }
}
