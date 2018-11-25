<?php

namespace AdminBundle\Controller;

use Entity\Comment;
use Entity\OrderComment;
use Leaf\DB;
use Leaf\Request;
use Leaf\View;

class CommentController
{
    /**
     * 列表
     * @Route admin/comment
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Search');

        if (!empty($search['id'])) {
            $condition[] = 'id = :id';
            $params[':id'] = $search['id'];
        }

        //数据
        $dataProvider = DB::table(OrderComment::tableName())
            ->where(implode(' and ', $condition), $params)
            ->asEntity(OrderComment::className())
            ->orderBy('id desc')
            ->paginate();

        //视图
        return View::render('@AdminBundle/comment/index.twig', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * 商品评价
     *
     * @Route admin/comment/product
     */
    public function product(Request $request)
    {
        $orderId = (int)$request->get('orderId');

        $list = Comment::where('order_id = ?', [$orderId])
            ->findAll();

        //视图
        return View::render('@AdminBundle/comment/product.twig', [
            'list' => $list,
        ]);
    }

}
