<?php

namespace AdminBundle\Controller;

use AdminBundle\Service\UserService;
use Leaf\Pagination;
use Leaf\Request;
use Leaf\View;

class UserController
{
    /**
     * 列表
     * @Route admin/user
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('User');

        if (!empty($search['username'])) {
            $condition[] = 'username like :username';
            $params[':username'] = '%' . $search['username'] . '%';
        }

        if (!empty($search['nickname'])) {
            $condition[] = 'nickname like :nickname';
            $params[':nickname'] = '%' . $search['nickname'] . '%';
        }
        if (!empty($search['mobile'])) {
            $condition[] = 'mobile like :mobile';
            $params[':mobile'] = '%' . $search['mobile'] . '%';
        }

        $condition = implode(' and ', $condition);

        //分页
        $page = new Pagination();

        //查询数据
        $list = UserService::findList($page, $condition, $params, $request->get('sort', '-id'));

        //视图
        return View::render('@AdminBundle/user/index.twig', [
            'list' => $list,
            'page' => $page,
        ]);
    }

}