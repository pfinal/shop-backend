<?php

namespace AdminBundle\Controller;

use Entity\Spell;
use Leaf\Pagination;
use Leaf\Request;
use Leaf\View;
use Service\SpellService;

/**
 * 拼团订单
 */
class SpellOrderController
{
    /**
     * 列表
     * @Route admin/spell-order
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Search');
        $status = $request->get('status');

        $statusList = [
            Spell::STATUS_SUCCESS => '拼团成功',
            Spell::STATUS_ING => '拼团中',
            Spell::STATUS_FAIL => '拼团失败',
        ];

        if (!array_key_exists($status, $statusList)) {
            throw new \InvalidArgumentException('参数有误');
        }

        $condition[] = 'so.status=?';
        $params[] = $status;

        if (!empty($search['product_id'])) {
            $condition[] = 'so.product_id = ?';
            $params[':product_id'] = $search['product_id'];
        }

        if (!empty($search['number'])) {
            $condition[] = 'o.number = :number';
            $params[':number'] = $search['number'];
        }

        if (!empty($search['receiver_mobile'])) {
            $condition[] = 'o.receiver_mobile = :receiver_mobile';
            $params[':receiver_mobile'] = $search['receiver_mobile'];
        }

        if (!empty($search['receiver_name'])) {
            $condition[] = 'o.receiver_name = :receiver_name';
            $params[':receiver_name'] = $search['receiver_name'];
        }

        $condition = implode(' and ', $condition);

        //分页
        $page = new Pagination();

        //查询数据
        $list = SpellService::findListForAdminSpellManage($page, $condition, $params);

        //视图
        return View::render('@AdminBundle/spell-order/index.twig', [
            'list' => $list,
            'page' => $page,
            'statusList' => $statusList,
        ]);
    }

}

