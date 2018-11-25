<?php

namespace AdminBundle\Controller;

use AdminBundle\Service\ReturnOrderService;
use AdminBundle\Service\OrderService;
use Carbon\Carbon;
use Entity\Order;
use Entity\OrderItem;
use Entity\Payment;
use Entity\ReturnOrder;
use Entity\Sku;
use Entity\Wxpay;
use Entity\Wxrefund;
use Leaf\DB;
use Leaf\Exception\HttpException;
use Leaf\Application;
use Leaf\Log;
use Leaf\Pagination;
use Leaf\Request;
use Leaf\Session;
use Leaf\Url;
use Leaf\Util;
use Leaf\Validator;
use Leaf\View;
use Leaf\Redirect;

class ReturnOrderController
{
    /**
     * 列表
     * @Route admin/return-order
     */
    public function index(Request $request)
    {
        $search = $request->get('ReturnOrder');
        $status = $request->get('status', 0);

        $statusList = [
            ReturnOrder::STATUS_APPLICATION => '待处理',
            ReturnOrder::STATUS_AGREE => '退货中',
            ReturnOrder::STATUS_REFUSE => '拒绝',
        ];

        //查询条件
        $condition = [];
        $params = [];

        if ($status) {
            if (!array_key_exists($status, $statusList)) {
                throw new \InvalidArgumentException("状态参数异常");
            }

            $condition[] = 'status = :status';
            $params[':status'] = $status;
        }

        if (!empty($search['contact'])) {
            $condition[] = 'contact like :contact';
            $params[':contact'] = '%' . $search['contact'] . '%';
        }
        if (!empty($search['mobile'])) {
            $condition[] = 'mobile = :mobile';
            $params[':mobile'] = $search['mobile'];
        }

        $condition = implode(' and ', $condition);

        //分页
        $page = new Pagination();

        //查询数据
        $list = ReturnOrderService::findList($page, $condition, $params, $request->get('sort', '-id'));

        //视图
        return View::render('@AdminBundle/return-order/index.twig', [
            'list' => $list,
            'page' => $page,
            'statusList' => $statusList,
            'status' => $status,
        ]);
    }

    /**
     * 收货
     * @Route admin/return-order/finish
     */
    public function finish(Request $request, Application $app)
    {
        //查询
        if (($entity = ReturnOrderService::findOne($request->get('id'))) === null) {
            throw new HttpException(500, '操作需要的数据不存在');
        }

        // 验证状态
        if (!$entity->checkFinishAction()) {
            Session::setFlash('message', '该状态下不可确认收货');
            return Redirect::back();
        }

        // 收货
        // 待退款的，则退款，否则，直接处理完成

        if (self::finishAction($entity->id, $error)) {
            Session::setFlash('message', '收货成功');
        } else {
            Session::setFlash('message', $error);
        }

        return Redirect::back();
    }

    /**
     * 确认收货操作
     * @param $id
     * @param string $error
     * @return bool
     */
    private function finishAction($id, &$error = '')
    {
        DB::getConnection()->beginTransaction();

        $returnOrder = DB::table(ReturnOrder::tableName())
            ->asEntity(ReturnOrder::className())
            ->lockForUpdate()
            ->findByPk($id);

        if ($returnOrder == null) {
            $error = '数据不存在';
            DB::getConnection()->rollBack();
            return false;
        }

        if (!$returnOrder->checkFinishAction()) {
            $error = '该状态下不可确认收货';
            DB::getConnection()->rollBack();
            return false;
        }

        $orderItem = DB::table(OrderItem::tableName())
            ->asEntity(OrderItem::className())
            ->lockForUpdate()
            ->findByPk($returnOrder['order_item_id']);

        if ($orderItem == null) {
            $error = '退货商品有误';
            DB::getConnection()->rollBack();
            return false;
        }

        $returnOrderData = [
            'status' => ReturnOrder::STATUS_FINISH,
            'delivery_status' => ReturnOrder::DELIVERY_STATUS_TAKE,
        ];

        if ($returnOrder['refund_status'] == ReturnOrder::REFUND_STATUS_WAIT) {
            $returnOrderData['refund_status'] = ReturnOrder::REFUND_STATUS_FINISH;
        }

        // 退款完成
        if (!self::dealActionForFinish($returnOrder['id'], $orderItem, $returnOrderData, $error)) {
            DB::getConnection()->rollBack();
            return false;
        }

        // 退款  (放在最后一步操作，以防微信退款成功，结果数据库操作失败)
        if ($returnOrder['refund_status'] == ReturnOrder::REFUND_STATUS_WAIT) {
            // 微信退款
            if ($returnOrder['refund_fee'] > 0) {
                if (!self::wxRefundForReturnOrder($returnOrder['refund_fee'], $returnOrder, $error)) {
                    return false;
                }
            }
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 退货处理
     * @Route admin/return-order/deal
     */
    public function deal(Request $request, Application $app)
    {
        //查询
        if (($entity = ReturnOrderService::findOne($request->get('id'))) === null) {
            throw new HttpException(500, '操作需要的数据不存在');
        }

        // 验证状态
        if ($entity->status != ReturnOrder::STATUS_APPLICATION) {
            Session::setFlash('message', '已处理过');
            return Redirect::back();
        }

        $orderInfo = $entity->getOrder();

        if (!$orderInfo) {
            throw new \InvalidArgumentException("退款相关订单有误");
        }

        $error = '';

        if ($request->isMethod('post')) {
            if (self::dealAction($entity['id'], $request, $error)) {
                Session::setFlash('message', '保存成功');
                return Redirect::to(Url::to('admin/return-order', ['status' => ReturnOrder::STATUS_APPLICATION]));
            }
        }

        $statusList = [
            ReturnOrder::STATUS_AGREE => '同意',
            ReturnOrder::STATUS_REFUSE => '驳回',
        ];

        $refundStatusList = [
            ReturnOrder::REFUND_STATUS_NO => '不用退款',
            ReturnOrder::REFUND_STATUS_WAIT => '需要退款',
        ];

        $deliveryCheckList = [
            ReturnOrder::DELIVERY_STATUS_WAIT => '需要',
            ReturnOrder::DELIVERY_STATUS_NO => '不需要',
        ];

        // 订单支付记录
        $paymentList = DB::table(Payment::tableName())
            ->asEntity(Payment::className())
            ->where('order_number = ?', [$orderInfo['number']])
            ->where('status = ?', [Payment::STATUS_YES])
            ->findAll();

        $orderId = $orderInfo['id'];

        //组合情况
        $orderItemList = DB::table(OrderItem::tableName())->asEntity(OrderItem::className())->where('order_id=?', [$orderId])->findAll();

        $orderItemSkuIds = array_column($orderItemList, 'sku_id');

        //通过sku_id查询sku表
        $skuList = DB::table(Sku::tableName())->whereIn('id', $orderItemSkuIds)->findAll();
        $skuList = array_column($skuList, null, 'id');

        /** @var $orderItemList OrderItem[] */

        $productArr = [];
        foreach ($orderItemList as $key => $value) {
            $productArr[$key]['sku_id'] = $value['sku_id'];
            $productArr[$key]['product_full_name'] = $value['product_full_name'];
            $productArr[$key]['quantity'] = $value['quantity'];
            $productArr[$key]['price'] = $value['price'];
            $productArr[$key]['total'] = $value['price'] * $value['quantity'];
            $productArr[$key]['color'] = $skuList[$value['sku_id']]['color'];
            $productArr[$key]['size'] = $skuList[$value['sku_id']]['size'];
            $productArr[$key]['version'] = $skuList[$value['sku_id']]['version'];
            $productArr[$key]['product'] = $value->product;
            $productArr[$key]['order_item_id'] = $value->id;
            $productArr[$key]['status_string'] = $value->statusAlias(1);
        }

        //加载视图
        return View::render('@AdminBundle/return-order/deal.twig', [
            'entity' => $entity,
            'error' => $error,

            'statusList' => $statusList,
            'refundStatusList' => $refundStatusList,
            'deliveryCheckList' => $deliveryCheckList,

            'orderInfo' => $orderInfo,
            'paymentList' => $paymentList,
            'productArr' => $productArr,
        ]);
    }

    /**
     * 处理操作
     * @param $id
     * @param Request $request
     * @param string $error
     * @return bool
     */
    private function dealAction($id, Request $request, &$error = '')
    {
        // 验证数据
        $data = $request->get('ReturnOrder');

        if (!is_array($data)) {
            $error = '参数有误';
            return false;
        }

        if (!isset($data['status'])) {
            $error = '请选择处理结果';
            return false;
        }

        $status = $data['status'];

        $rule = [
            [['status', 'reply', 'delivery_status', 'refund_status', 'refund_fee'], 'trim'],
            [['status', 'reply', 'delivery_status', 'refund_status', 'refund_fee'], 'safe'],
            [['status',], 'required'],
            ['status', 'in', 'range' => [ReturnOrder::STATUS_REFUSE, ReturnOrder::STATUS_AGREE],],
        ];

        if ($status == ReturnOrder::STATUS_REFUSE) {
            // 拒绝
            $rule = array_merge($rule, [
                [['reply',], 'trim'],
                [['reply',], 'required'],
                [['reply',], 'safe',],
            ]);

        } else if ($status == ReturnOrder::STATUS_AGREE) {
            // 同意
            $rule = array_merge($rule, [
                [['delivery_status', 'refund_status',], 'trim'],
                [['delivery_status', 'refund_status',], 'required'],
                ['delivery_status', 'in', 'range' => [ReturnOrder::DELIVERY_STATUS_NO, ReturnOrder::DELIVERY_STATUS_WAIT]],
                ['refund_status', 'in', 'range' => [ReturnOrder::REFUND_STATUS_NO, ReturnOrder::REFUND_STATUS_WAIT]],
            ]);

            // 判断退款状态
            if (!isset($data['refund_status'])) {
                $error = '请选择是否退款';
                return false;
            }

            $requestRefundStatus = $data['refund_status'];

            if ($requestRefundStatus == ReturnOrder::REFUND_STATUS_NO) {
                // 不退款，不需要其他验证

            } else {
                // 退款，验证退款金额
                $rule = array_merge($rule, [
                    [['refund_fee',], 'trim'],
                    [['refund_fee',], 'required'],
                    [['refund_fee',], 'double'],
                ]);

                if (!isset($data['refund_fee'])) {
                    $error = '请填写退款金额';
                    return false;
                }

                if ($data['refund_fee'] < 0) {
                    $error = '退款金额不能小于0';
                    return false;
                }
            }

        } else {
            $error = '处理结果有误，请正确选择';
            return false;
        }

        $labels = [
            'status' => '处理',
            'reply' => '拒绝原因',
            'refund_status' => '是否退款',
            'refund_fee' => '退款金额',
            'delivery_status' => '是否需要返还商品',
        ];

        if (!Validator::validate($data, $rule, $labels)) {
            $error = Validator::getFirstError();
            return false;
        }

        DB::getConnection()->beginTransaction();

        $returnOrder = DB::table(ReturnOrder::tableName())
            ->asEntity(ReturnOrder::className())
            ->lockForUpdate()
            ->findByPk($id);

        if ($returnOrder == null) {
            $error = '数据不存在';
            DB::getConnection()->rollBack();
            return false;
        }

        if ($returnOrder['status'] != ReturnOrder::STATUS_APPLICATION) {
            $error = '该退货数据已处理过';
            DB::getConnection()->rollBack();
            return false;
        }

        $orderItem = DB::table(OrderItem::tableName())
            ->asEntity(OrderItem::className())
            ->lockForUpdate()
            ->findByPk($returnOrder['order_item_id']);

        if ($orderItem == null) {
            $error = '退货商品有误';
            DB::getConnection()->rollBack();
            return false;
        }

        /**
         * 根据处理结果决定
         * 拒绝：1、订单明细恢复正常状态；2、更新退货处理数据
         * 同意：根据是否退款以及是否返还商品决定
         *      需要返回商品：  处理退款数据(其他的操作待页面处理"收货"进行处理)
         *      不需要返还商品：
         *          不需要退款：操作订单明细、处理退款数据 -> 退款完成
         *          待退款：退款、操作订单明细、处理退款数据 -> 退款完成
         *          已退款：操作订单明细、处理退款数据 -> 退款完成
         *
         * 待退款：验证退款金额
         */

        // 待退款的验证数据
        if ($data['refund_status'] == ReturnOrder::REFUND_STATUS_WAIT) {
            if (!self::checkRefundMoney($data['refund_fee'], $returnOrder, $error)) {
                DB::getConnection()->rollBack();
                return false;
            }
        }

        if ($data['status'] == ReturnOrder::STATUS_REFUSE) {
            // 拒绝
            if (!self::dealActionForRefuse($returnOrder['id'], $orderItem['id'], $data, $error)) {
                DB::getConnection()->rollBack();
                return false;
            }
        } else {
            // 同意
            if (($data['delivery_status'] == ReturnOrder::DELIVERY_STATUS_WAIT)) {
                // 返还商品：只更新退款数据即可。其他的操作待页面处理"收货"进行处理
                if (!self::dealActionHandleReturnOrder($returnOrder['id'], $data, $error)) {
                    DB::getConnection()->rollBack();
                    return false;
                }
            } else {
                // 不需要返回商品

                // 判断退款状态
                if ($data['refund_status'] == ReturnOrder::REFUND_STATUS_WAIT) {
                    // 待退款

                    // 执行退款操作，然后操作状态
                    if (!self::dealActionForRefund($returnOrder, $orderItem, $data, $error)) {
                        DB::getConnection()->rollBack();
                        return false;
                    }

                } else {
                    // 不退款、已退款
                    if (!self::dealActionForFinish($returnOrder['id'], $orderItem, $data, $error)) {
                        DB::getConnection()->rollBack();
                        return false;
                    }
                }

            }
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 待退款->原路退款->退款完成
     * @param ReturnOrder $returnOrder
     * @param OrderItem $orderItem
     * @param $data
     * @param string $error
     * @return bool
     */
    private function dealActionForRefund(ReturnOrder $returnOrder, $orderItem, $data, &$error = '')
    {
        $id = $returnOrder['id'];

        $refundFee = $data['refund_fee'];

        // 退款完成
        if (!self::dealActionForFinish($id, $orderItem, $data, $error)) {
            return false;
        }

        // 微信退款 (放在最后一步操作，以防微信退款成功，结果数据库操作失败)
        if (!self::wxRefundForReturnOrder($refundFee, $returnOrder, $error)) {
            return false;
        }

        return true;
    }

    /**
     * 微信退款  (放在最后一步操作，以防微信退款成功，结果数据库操作失败)
     * @param $refundFee
     * @param ReturnOrder $returnOrder
     * @param string $error
     * @return bool
     */
    private function wxRefundForReturnOrder($refundFee, ReturnOrder $returnOrder, &$error = '')
    {
        if ($refundFee <= 0) {
            return true;
        }

        $resultData = self::checkRefundMoney($refundFee, $returnOrder, $error);

        if (!$resultData) {
            return false;
        }

        $paymentList = $resultData['paymentList'];
        $refundFee = $resultData['refundFee'];
        $wxRefundData = $resultData['wxRefundData'];
        $wxPayList = $resultData['wxPayList'];

        $paymentList = Util::arrayColumn($paymentList, null, 'out_trade_no');

        /** @var $wxPayList Wxpay[] */

        // 退款
        $checkFirstRefund = 0; // 是否第一次退款 第一次退款失败则回滚，否则不回滚
        foreach ($wxPayList as $value) {

            // 没有剩余待退款金额，即结束
            if ($refundFee <= 0) {
                break;
            }

            $wxpayId = $value['id'];

            // 退款的记录
            $tempRefundList = isset($wxRefundData[$wxpayId]) ? $wxRefundData[$wxpayId] : [];

            $totalFee = $value['total_fee'];

            if (count($tempRefundList) > 0) {
                $totalFee = $totalFee - $tempRefundList['money'];
            }

            // 退款金额大于等于本次的支付记录时，直接退全款
            // 否则，只需要操作退款金额即可
            if ($refundFee >= $totalFee) {
                $tempMoney = $totalFee;
            } else {
                $tempMoney = $refundFee;
            }

            // 订单信息
            $payment = isset($paymentList[$value['out_trade_no']]) ? $paymentList[$value['out_trade_no']] : null;

            if (!$payment) {
                continue;
            }

            // 微信，将退款到微信
            if (!self::refundWxpay($payment, $value, $tempMoney, $error)) {
                if ($checkFirstRefund == 0) {
                    return false;
                }

                $checkFirstRefund += 1;

                // 记录错误日志
                Log::warning("微信退款失败(退成功过)", [
                    'title' => '退款退款',
                    'returnOrderId' => $returnOrder->id,
                    'returnOrder' => json_encode((array)$returnOrder),
                    'error' => $error
                ]);
            }

            // 剩余的退款金额
            $refundFee = $refundFee - $tempMoney;
        }

        return true;
    }

    /**
     * 验证退款金额
     * @param $refundFee
     * @param ReturnOrder $returnOrder
     * @param string $error
     * @return bool|array
     */
    private function checkRefundMoney($refundFee, ReturnOrder $returnOrder, &$error = '')
    {
        // 退款金额小于等于0的时候，不需要退款，直接返回
        if ($refundFee <= 0) {
            return true;
        }

        $orderNumber = $returnOrder->getOrderNumber();

        if (!$orderNumber) {
            $error = '退款订单有误';
            return false;
        }

        // 原路退款
        $paymentList = DB::table(Payment::tableName())
            ->asEntity(Payment::className())
            ->where('order_number = ?', [$orderNumber])
            ->where('status = ?', [Payment::STATUS_YES])
            ->findAll();

        if (count($paymentList) <= 0) {
            $error = '退款金额大于用户实际支付金额';
            return false;
        }

        $wxPaymentList = []; // 微信支付方式

        foreach ($paymentList as $payment) {
            if ($payment['pay_type'] == Payment::PAY_TYPE_TICKET) {
                continue;
            } else if ($payment['pay_type'] == Payment::PAY_TYPE_WXPAY) {
                $wxPaymentList[] = $payment;
            } else {
                $error = '暂时只能对微信支付的订单进行退款';
                return false;
            }
        }

        if (count($wxPaymentList) <= 0) {
            $error = '退款金额大于用户实际支付金额1';
            return false;
        }

        $out_trade_noList = Util::arrayColumn($wxPaymentList, 'out_trade_no');

        if (count($out_trade_noList) <= 0) {
            $error = '退款金额大于用户实际支付金额2';
            return false;
        }

        $wxPayList = DB::table(Wxpay::tableName())
            ->asEntity(Wxpay::className())
            ->whereIn('out_trade_no', $out_trade_noList)
            ->findAll();

        if (count($wxPayList) <= 0) {
            $error = '退款金额大于用户实际支付金额3';
            return false;
        }

        $wxPayMoney = 0;
        foreach ($wxPayList as $value) {
            $wxPayMoney = $wxPayMoney + $value['total_fee'];
        }

        // 退款记录
        $wxRefundMoney = 0;
        $wxRefundData = [];

        $wxpayIds = Util::arrayColumn($wxPayList, 'id');
        if (count($wxpayIds) > 0) {
            $wxRefundList = DB::table(Wxrefund::tableName())
                ->whereIn('wxpay_id', $wxpayIds)
                ->where('time_end != 0')
                ->findAll();

            foreach ($wxRefundList as $value) {
                $wxRefundMoney = $wxRefundMoney + $value['money'];

                if (!array_key_exists($value['wxpay_id'], $wxRefundData)) {
                    $wxRefundData[$value['wxpay_id']] = [
                        'money' => 0,
                        'list' => []
                    ];
                }

                $wxRefundData[$value['wxpay_id']]['list'][] = $value;
                $wxRefundData[$value['wxpay_id']]['money'] += $value['money'];
            }
        }

        $remainMoney = $wxPayMoney - $wxRefundMoney;

        if ($refundFee > $remainMoney) {
            $error = '用户实际支付金额不足与退款金额';
            return false;
        }

        return [
            'paymentList' => $paymentList,
            'refundFee' => $refundFee,
            'wxRefundData' => $wxRefundData,
            'wxPayList' => $wxPayList,
        ];
    }

    /**
     * 微信退款
     * @param Payment $payment
     * @param Wxpay $wxpay
     * @param $money
     * @param string $error
     * @return bool
     */
    private function refundWxpay(Payment $payment, Wxpay $wxpay, $money, &$error = '')
    {
        // 退款记录
        $data = [
            'wxpay_id' => $wxpay['id'],
            'money' => $money,
        ];

        $data['created_at'] = $data['updated_at'] = Carbon::now();

        // 退款编号
        $refundNumber = strtolower(str_replace('-', '', Util::guid()));
        $data['out_trade_no'] = $refundNumber;

        $rewfunId = DB::table(Wxrefund::tableName())->insertGetId($data);

        $wxrefund = DB::table(Wxrefund::tableName())
            ->asEntity(Wxrefund::className())
            ->findByPk($rewfunId);

        return OrderService::refundWxpayAction($wxpay['out_trade_no'], $wxpay['total_fee'], $wxrefund, $error);
    }

    /**
     * 退款完成
     * @param $id
     * @param OrderItem $orderItem
     * @param $data
     * @param string $error
     * @return bool
     */
    private function dealActionForFinish($id, $orderItem, $data, &$error = '')
    {
        $orderItemId = $orderItem['id'];

        DB::getConnection()->beginTransaction();

        // 将订单明细更新为退款成功
        $rows = DB::table(OrderItem::tableName())
            ->wherePk($orderItemId)
            ->update([
                'status' => OrderItem::STATUS_REFUND,
                'updated_at' => Carbon::now(),
            ]);

        if ($rows != 1) {
            $error = '处理失败';
            DB::getConnection()->rollBack();
            return false;
        }

        // 处理退款结果
        if (!self::dealActionHandleReturnOrder($id, $data, $error)) {
            DB::getConnection()->rollBack();
            return false;
        }

        // 退款完成
        if ($data['status'] != ReturnOrder::STATUS_FINISH) {
            $rows = DB::table(ReturnOrder::tableName())
                ->wherePk($id)
                ->update([
                    'status' => ReturnOrder::STATUS_FINISH,
                ]);

            if ($rows != 1) {
                $error = '更新失败';
                DB::getConnection()->rollBack();
                return false;
            }
        }

        // 检测是否处理订单为关闭
        // 1、未发货的订单
        // 2、订单明细所有的退款成功
        $order = DB::table(Order::tableName())
            ->lockForUpdate()
            ->findByPk($orderItem['order_id']);

        if ($order == null) {
            $error = '退货订单不存在';
            DB::getConnection()->rollBack();
            return false;
        }

        // 1、未发货的订单
        if ($order['delivery_status'] == Order::DELIVERY_STATUS_NO) {
            // 2、是否所有的订单明细均是退货成功状态
            $count = DB::table(OrderItem::tableName())
                ->where('order_id = ?', [$order['id']])
                ->where('status != ?', [OrderItem::STATUS_REFUND])
                ->count();

            // 都是退款成功的状态，则将订单关闭
            if ($count == 0) {
                // 当订单是已完成、交易关闭的状态，则不修改订单状态
                // 否则，将订单修改为"交易关闭"
                if (($order['status'] != Order::STATUS_SUCCESS) && ($order['status'] != Order::STATUS_CANCEL)) {
                    $rows = DB::table(Order::tableName())
                        ->wherePk($order['id'])
                        ->update([
                            'status' => Order::STATUS_CANCEL,
                            'updated_at' => Carbon::now(),
                        ]);

                    if ($rows != 1) {
                        $error = '订单退货失败';
                        DB::getConnection()->rollBack();
                        return false;
                    }
                }
            }
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 拒绝退款
     * @param $id
     * @param $orderItemId
     * @param $data
     * @param string $error
     * @return bool
     */
    private function dealActionForRefuse($id, $orderItemId, $data, &$error = '')
    {
        // 将订单明细恢复正常
        $rows = DB::table(OrderItem::tableName())
            ->wherePk($orderItemId)
            ->update([
                'status' => OrderItem::STATUS_NORMAL,
                'updated_at' => Carbon::now(),
            ]);

        if ($rows != 1) {
            $error = '处理失败';
            return false;
        }

        // 处理退款结果
        if (!self::dealActionHandleReturnOrder($id, $data, $error)) {
            return false;
        }

        return true;
    }

    /**
     * 处理退款数据
     * @param $id
     * @param $data
     * @param string $error
     * @return bool
     */
    private function dealActionHandleReturnOrder($id, $data, &$error = '')
    {
        // 处理退款结果
        $data['updated_at'] = Carbon::now();

        $rows = DB::table(ReturnOrder::tableName())
            ->wherePk($id)
            ->update($data);

        if ($rows != 1) {
            $error = '退货处理失败';
            return false;
        }

        return true;
    }


//    /**
//     * 修改退货状态
//     * @Route admin/return-order/update
//     */
//    public function update(Request $request, Application $app)
//    {
//        if ($request->isMethod('post')) {
//            $id = $request->get('id');
//            $data['status'] = $request->get('status');
//
//            //验证
//            $rule = [
//                ['status', 'required'],
//                ['status', 'in', 'range' => [ReturnOrder::STATUS_AGREE, ReturnOrder::STATUS_REFUSE, ReturnOrder::STATUS_FINISH]],
//            ];
//            if (!Validator::validate($data, $rule, ReturnOrder::labels())) {
//                $error = Validator::getFirstError();
//                return Json::renderWithFalse($error);
//            }
//
//            $data['updated_at'] = date('Y-m-d H:i:s');
//
//            //更新
//            if (DB::table(ReturnOrder::tableName())->where('id = ?', [$id])->update($data)) {
//                return Json::renderWithTrue('操作成功');
//            } else {
//                $error = '系统错误';
//                Log::error("操作失败: " . DB::getConnection()->getLastSql());
//                return Json::renderWithFalse($error);
//            }
//
//        }
//
//        //查询
//        if (($entity = ReturnOrderService::findOne($request->get('id'))) === null) {
//            throw new HttpException(500, '操作需要的数据不存在');
//        }
//        //加载视图
//        return View::render('@AdminBundle/return-order/update.twig', [
//            'entity' => $entity,
//        ]);
//    }

    /**
     * 详情
     * @Route admin/return-order/view
     */
    public function view(Request $request)
    {
        //查询
        if (($entity = ReturnOrderService::findOne($request->get('id'))) === null) {
            throw new HttpException(500, '操作需要的数据不存在');
        }

        $orderInfo = $entity->getOrder();

        if (!$orderInfo) {
            throw new \InvalidArgumentException("退款相关订单有误");
        }

        // 订单支付记录
        $paymentList = DB::table(Payment::tableName())
            ->asEntity(Payment::className())
            ->where('order_number = ?', [$orderInfo['number']])
            ->where('status = ?', [Payment::STATUS_YES])
            ->findAll();

        $orderId = $orderInfo['id'];

        //组合情况
        $orderItemList = DB::table(OrderItem::tableName())->asEntity(OrderItem::className())->where('order_id=?', [$orderId])->findAll();

        $orderItemSkuIds = array_column($orderItemList, 'sku_id');

        //通过sku_id查询sku表
        $skuList = DB::table(Sku::tableName())->whereIn('id', $orderItemSkuIds)->findAll();
        $skuList = array_column($skuList, null, 'id');

        /** @var $orderItemList OrderItem[] */

        $productArr = [];
        foreach ($orderItemList as $key => $value) {
            $productArr[$key]['sku_id'] = $value['sku_id'];
            $productArr[$key]['product_full_name'] = $value['product_full_name'];
            $productArr[$key]['quantity'] = $value['quantity'];
            $productArr[$key]['price'] = $value['price'];
            $productArr[$key]['total'] = $value['price'] * $value['quantity'];
            $productArr[$key]['color'] = $skuList[$value['sku_id']]['color'];
            $productArr[$key]['size'] = $skuList[$value['sku_id']]['size'];
            $productArr[$key]['version'] = $skuList[$value['sku_id']]['version'];
            $productArr[$key]['product'] = $value->product;
            $productArr[$key]['order_item_id'] = $value->id;
            $productArr[$key]['status_string'] = $value->statusAlias(1);
        }

        //加载视图
        return View::render('@AdminBundle/return-order/view.twig', [
            'entity' => $entity,
            'orderInfo' => $orderInfo,
            'paymentList' => $paymentList,
            'productArr' => $productArr,
        ]);
    }

//    /**
//     * 删除
//     * @Route admin/return-order/delete
//     */
//    public function delete(Request $request)
//    {
//        if (ReturnOrderService::delete($request->get('id'))) {
//            Session::setFlash('message', '删除成功');
//        } else {
//            Session::setFlash('message', '删除失败');
//        }
//        return Redirect::back();
//    }
}