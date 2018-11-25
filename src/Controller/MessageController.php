<?php

namespace AdminBundle\Controller;

use Carbon\Carbon;
use Entity\Message;
use Leaf\Application;
use Leaf\DB;
use Leaf\Request;
use Leaf\Session;
use Leaf\Validator;
use Leaf\View;
use Leaf\Redirect;

class MessageController
{
    /**
     * 列表
     * @Route admin/message
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Message');

        if (!empty($search['id'])) {
            $condition[] = 'id = :id';
            $params[':id'] = $search['id'];
        }

        if (!empty($search['title'])) {
            $condition[] = 'title like :title';
            $params[':title'] = '%' . trim($search['title']) . '%';
        }

        //数据
        $dataProvider = DB::table(Message::tableName())
            ->where('status!=?', Message::STATUS_DEL)
            ->where(implode(' and ', $condition), $params)
            ->asEntity(Message::className())
            ->orderBy($request->get('sort', 'id desc'))
            ->paginate();

        //视图
        return View::render('@AdminBundle/message/index.twig', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * 新增
     * @Route admin/message/create
     */
    public function create(Request $request)
    {
        $entity = new Message();
        $entity->loadDefaultValues();

        //保存
        $error = '';
        if ($request->isMethod('POST')) {

            $data = $request->get('Message');

            if (Validator::validate($data, self::getRules('create'), Message::labels())) {

                $data['created_at'] = $data['updated_at'] = Carbon::now();

                if (DB::table(Message::tableName())->insert($data)) {
                    Session::setFlash('message', '添加成功');
                    return Redirect::to('admin/message');
                } else {
                    $error = '系统错误';
                }
            } else {
                $error = Validator::getFirstError();
            }
        }

        //视图
        return View::render('@AdminBundle/message/create.twig', [
            'entity' => $entity,
            'error' => $error,
        ]);
    }

    /**
     * 更新
     * @Route admin/message/update
     */
    public function update(Request $request, Application $app)
    {
        //查询
        $entity = DB::table(Message::tableName())
            ->asEntity(Message::className())
            ->findByPkOrFail($request->get('id'));

        //保存
        $error = '';
        if ($request->isMethod('POST')) {

            $data = $request->get('Message');

            //验证
            if (Validator::validate($data, self::getRules('update'))) {

                $data['updated_at'] = Carbon::now();

                //更新
                if (DB::table(Message::tableName())->wherePk($entity->id)->update($data)) {
                    Session::setFlash('message', '修改成功');
                    return Redirect::to('admin/message');
                } else {
                    $error = '系统错误';
                }

            } else {
                $error = Validator::getFirstError();
            }
        }

        //视图
        return View::render('@AdminBundle/message/update.twig', [
            'entity' => $entity,
            'error' => $error,
        ]);
    }

    /**
     * 删除
     * @Route admin/message/delete
     * @Method post
     */
    public function delete(Request $request)
    {
        $result = DB::table(Message::tableName())->wherePk($request->get('id'))->delete();

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
            [['user_id', 'type', 'route', 'status', 'title', 'content',], 'safe'],
        ];

        return $rules;
    }

}
