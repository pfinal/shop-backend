<?php

namespace AdminBundle\Controller;

use Carbon\Carbon;
use Entity\Push;
use Entity\UserGetui;
use Leaf\DB;
use Leaf\Request;
use Leaf\Session;
use Leaf\Util;
use Leaf\Validator;
use Leaf\View;
use Leaf\Redirect;

/**
 * 推送管理
 * @author  curd generator
 * @since   1.0
 */
class PushController
{
    /**
     * 列表
     * @Route admin/push
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Push');

        if (!empty($search['id'])) {
            $condition[] = 'id = :id';
            $params[':id'] = $search['id'];
        }

        //数据
        $dataProvider = DB::table(Push::tableName())
            ->where(implode(' and ', $condition), $params)
            ->asEntity(Push::className())
            ->where('status!=?', Push::STATUS_DELETE)
            ->orderBy($request->get('sort', 'id desc'))
            ->paginate();

        //视图
        return View::render('@AdminBundle/push/index.twig', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * 新增
     * @Route admin/push/create
     */
    public function create(Request $request)
    {
        $from = $request->get('from');
        if ($from) {
            $entity = Push::wherePk($from)->findOne();
        } else {
            $entity = new Push();
            $entity->loadDefaultValues();
        }

        //保存
        $error = '';
        if ($request->isMethod('POST')) {

            $data = $request->get('Push');

            if (Validator::validate($data, self::getRules('create'), Push::labels())) {

                $data['created_at'] = $data['updated_at'] = Carbon::now();

                $id = DB::table(Push::tableName())->insertGetId($data);
                if ($id) {

                    $this->doPush($id);

                    Session::setFlash('message', '添加成功');
                    return Redirect::to('admin/push');
                } else {
                    $error = '系统错误';
                }
            } else {
                $error = Validator::getFirstError();
            }
        }

        //视图
        return View::render('@AdminBundle/push/create.twig', [
            'entity' => $entity,
            'error' => $error,
        ]);
    }


    public function doPush($id)
    {
        //todo queue

        /** @var Push $push */
        $push = Push::wherePk($id)->findOne();
        if ($push == null) {
            return;
        }

        $ge = new \Getui\Getui();
//        $data = [
//            'message' => '推送测试',
//            'route' => ['path' => '/detail', 'query' => ['skuId' => 1601]],
//        ];


        $data = json_decode($push->content);

        if ($push->cid) {
            $cids = explode("\n", $push->cid);
            $cids = array_filter($cids);
        } else {

            //todo 调用批量接口
            $all = UserGetui::findAll();
            $cids = Util::arrayColumn($all, 'cid');
        }

        foreach ($cids as $cid) {
            $cid = trim($cid);
            if (empty($cid)) {
                continue;
            }

            //todo 判断是否成功
            $result = $ge->pushSingle($cid, $push->title, $data);
        }

        DB::table(Push::tableName())->wherePk($id)->update(['status' => Push::STATUS_FINISH]);

    }

    /**
     * 删除
     * @Route admin/push/delete
     * @Method post
     */
    public function delete(Request $request)
    {
        $result = DB::table(Push::tableName())->wherePk($request->get('id'))->update(
            ['status' => Push::STATUS_DELETE, 'updated_at' => Carbon::now()]
        );

        if ($result) {
            Session::setFlash('message', '删除成功');
        } else {
            Session::setFlash('message', '删除失败');
        }

        return Redirect::back();
    }

//    /**
//     * 详情
//     * @Route admin/push/view
//     */
//    public function view(Request $request, Application $app)
//    {
//        //查询
//        $entity = DB::table(Push::tableName())
//            ->asEntity(Push::className())
//            ->findByPkOrFail($request->get('id'));
//
//        //视图
//        return View::render('@AdminBundle/push/view.twig', [
//            'entity' => $entity,
//        ]);
//    }

    /**
     * 验证规则
     * @param string $scene create|update
     * @return array
     */
    protected function getRules($scene)
    {
        $rules = [
            [['cid', 'title', 'content',], 'trim'],
            [['title', 'content',], 'required'],
            [['cid', 'title', 'content',], 'safe'],
        ];

        return $rules;
    }

}
