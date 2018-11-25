<?php

namespace AdminBundle\Controller;

use Carbon\Carbon;
use Entity\Carrier;
use Entity\Order;
use Entity\OrderItem;
use Entity\Payment;
use Entity\Sku;
use Enum\OrderEventType;
use Leaf\DB;
use Leaf\Exception\HttpException;
use AdminBundle\Service\OrderService;
use Leaf\Application;
use Leaf\Json;
use Leaf\Pagination;
use Leaf\Request;
use Leaf\Session;
use Leaf\Url;
use Leaf\Util;
use Leaf\Validator;
use Leaf\View;
use Leaf\Redirect;
use Service\RegionService;

class OrderController
{
    /**
     * 列表
     * @Route admin/order
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Order');

        $tab = $request->get('tab', 0);

        // 是否能够修改订单
        $checkUpdatePrice = 0; // 默认不能

        switch ($tab) {

            //进行中的
            case '1':
                $condition[] = 'o.status=?';
                $params[] = Order::STATUS_ONGOING;
                break;

            //成功的
            case '2':
                $condition[] = 'o.status=?';
                $params[] = Order::STATUS_SUCCESS;
                break;

            //无效的
            case '3':
                $condition[] = 'o.status=?';
                $params[] = Order::STATUS_CANCEL;
                break;

            //待审核
            case '4':
                $condition[] = 'o.status=?';
                $params[] = Order::STATUS_NEW;
                break;

            //待发货
            case '5':
                $condition[] = 'o.status=? and o.delivery_type=? and o.delivery_status=?';
                $params[] = Order::STATUS_ONGOING;
                $params[] = Order::DELIVERY_TYPE_CARRIER;
                $params[] = Order::DELIVERY_STATUS_NO;
                break;

            // 待支付
            case '6':
                $condition[] = 'o.status=? and o.payment_status=?';
                $params[] = Order::STATUS_ONGOING;
                $params[] = Order::PAYMENT_STATUS_NO;

                $checkUpdatePrice = 1; // 待支付的可以修改订单
                break;

            // 全部
            case '0':
                break;

            default:
                // 不匹配的一个，内容查询为空
                $condition[] = 'o.id = -1';
        }

        if (!empty($search['number'])) {
            $condition[] = 'o.number = ?';
            $params[] = $search['number'];
        }

        if (!empty($search['receiver_mobile'])) {
            $condition[] = 'o.receiver_mobile = ?';
            $params[] = $search['receiver_mobile'];
        }

        if (!empty($search['receiver_name'])) {
            $condition[] = 'o.receiver_name like ?';
            $params[] = '%' . $search['receiver_name'] . '%';
        }

        $condition = implode(' and ', $condition);

        //分页
        $page = new Pagination();

        $orderBy = $request->get('sort', 'id desc');
        $orderBy = 'o.' . $orderBy;

        //查询数据
        $list = OrderService::findListForAdminOrderManage($page, $condition, $params, $orderBy);

        $tabList = [
            6 => '待支付',
            5 => '待发货',
            1 => '进行中',
            //4 => '待审核',
            2 => '已完成',
            // 3 => '无效',
            0 => '全部',
        ];

        //视图
        return View::render('@AdminBundle/order/index.twig', [
            'list' => $list,
            'page' => $page,
            'tab' => $tab,
            'tabList' => $tabList,

            'checkUpdatePrice' => $checkUpdatePrice,
        ]);
    }

    /**
     * 修改
     *
     * @Route admin/order/update
     */
    public function update(Request $request)
    {
        $orderId = $request->get('id');

        //保存
        $error = '';
        if ($request->isMethod('POST')) {
            if (self::updateAction($orderId, $request, $error)) {
                Session::setFlash('message', '保存成功');
                return Redirect::to('admin/order');
            }
        }

        //查询
        $entity = DB::table(Order::tableName())
            ->asEntity(Order::className())
            ->findByPkOrFail($orderId);

        // 检测这个订单是否可以修改
        if (!$entity->checkUpdate()) {
            Session::setFlash('message', '该状态下不可修改');
            return Redirect::back();
        }

        //获取orderId查询order_item表获取商品数据
        $orderId = $request->get('id');
        $orderItemList = DB::table(OrderItem::tableName())->asEntity(OrderItem::className())->where('order_id=?', [$orderId])->findAll();

        $orderItemSkuIds = array_column($orderItemList, 'sku_id');

        //通过sku_id查询sku表
        $skuList = DB::table(Sku::tableName())->whereIn('id', $orderItemSkuIds)->findAll();
        $skuList = array_column($skuList, null, 'id');

        //组合情况
        $productArr = [];
        foreach ($orderItemList as $key => $value) {
            $productArr[$key]['item_id'] = $value['id'];
            $productArr[$key]['sku_id'] = $value['sku_id'];
            $productArr[$key]['product_full_name'] = $value['product_full_name'];
            $productArr[$key]['quantity'] = $value['quantity'];
            $productArr[$key]['price'] = $value['price'];
            $productArr[$key]['total'] = $value['price'] * $value['quantity'];
            $productArr[$key]['color'] = $skuList[$value['sku_id']]['color'];
            $productArr[$key]['size'] = $skuList[$value['sku_id']]['size'];
            $productArr[$key]['version'] = $skuList[$value['sku_id']]['version'];
            $productArr[$key]['product'] = $value->product;

        }

        // 省、市、区
        $regionList = RegionService::codeList();

        $provinceList = [];
        $tempRegionInfo = [];
        foreach ($regionList as $value) {
            $provinceList[$value['code']] = $value['name'];

            if ($value['code'] == $entity->receiver_province) {
                $tempRegionInfo = $value;
            }
        }

        $cityList = [];
        foreach ($tempRegionInfo['children'] as $value) {
            $cityList[$value['code']] = $value['name'];

            if ($value['code'] == $entity->receiver_city) {
                $tempRegionInfo = $value;
            }
        }

        $districtList = [];
        foreach ($tempRegionInfo['children'] as $value) {
            $districtList[$value['code']] = $value['name'];
        }

        //视图
        return View::render('@AdminBundle/order/update.twig', [
            'entity' => $entity,
            'productArr' => $productArr,
            'error' => $error,

            'provinceList' => $provinceList,
            'cityList' => $cityList,
            'districtList' => $districtList,
        ]);
    }

    /**
     * 保存操作
     * @param $orderId
     * @param Request $request
     * @param string $error
     * @return bool
     */
    private function updateAction($orderId, Request $request, &$error = '')
    {
        $data = $request->geT('Order');

        // 验证数据
        $rule = [
            [['receiver_name', 'receiver_mobile', 'receiver_province', 'receiver_city', 'receiver_district', 'receiver_detail'], 'trim'],
            [['receiver_name', 'receiver_mobile', 'receiver_province', 'receiver_city', 'receiver_district', 'receiver_detail'], 'required'],
            [['receiver_name',], 'string', 'length' => [1, 50]],
            [['receiver_detail'], 'string', 'length' => [1, 255]],
            [['receiver_mobile',], 'mobile'],
            [['receiver_province', 'receiver_city', 'receiver_district',], 'integer'],
        ];

        if (!Validator::validate($data, $rule, Order::labels())) {
            $error = Validator::getFirstError();
            return false;
        }

        // 验证地区

        // 省、市、区
        $regionList = RegionService::codeList();
        $regionList = Util::arrayColumn($regionList, null, 'code');

        if (!array_key_exists($data['receiver_province'], $regionList)) {
            $error = '请正确选择收货地址';
            return false;
        }

        $cityList = $regionList[$data['receiver_province']]['children'];
        $cityList = Util::arrayColumn($cityList, null, 'code');

        if (!array_key_exists($data['receiver_city'], $cityList)) {
            $error = '请正确选择收货地址';
            return false;
        }

        $districtList = $cityList[$data['receiver_city']]['children'];
        $districtList = Util::arrayColumn($districtList, null, 'code');

        if (!array_key_exists($data['receiver_district'], $districtList)) {
            $error = '请正确选择收货地址';
            return false;
        }

        DB::getConnection()->beginTransaction();

        //查询
        $order = DB::table(Order::tableName())
            ->asEntity(Order::className())
            ->lockForUpdate()
            ->findByPk($orderId);

        if (!$order) {
            $error = '订单不存在';
            DB::getConnection()->rollBack();
            return false;
        }

        // 检测这个订单是否可以修改
        if (!$order->checkUpdate()) {
            $error = '该状态下不可修改';
            DB::getConnection()->rollBack();
            return false;
        }

        // 保存数据
        $data['updated_at'] = Carbon::now();

        $rows = DB::table(Order::tableName())
            ->wherePk($orderId)
            ->update($data);

        if ($rows != 1) {
            $error = '保存失败';
            DB::getConnection()->rollBack();
            return false;
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 根据省获取市
     *
     * @Route admin/order/region-by-pcode
     */
    public function regionByPcode(Request $request)
    {
        $provinceCode = $request->get('provinceCode');
        $cityCode = $request->get('cityCode');

        if (!$provinceCode) {
            return Json::renderWithFalse("请正确传参");
        }

        // 省、市、区
        $regionList = RegionService::codeList();

        $tempRegionInfo = [];
        foreach ($regionList as $value) {
            if ($value['code'] == $provinceCode) {
                $tempRegionInfo = $value;
            }
        }

        if (!$tempRegionInfo) {
            return Json::renderWithFalse("请正确传参");
        }

        $cityList = $tempRegionInfo['children'];

        if (!$cityCode) {
            return Json::renderWithTrue($cityList);
        }

        $tempRegionInfo = [];
        foreach ($cityList as $value) {
            if ($value['code'] == $cityCode) {
                $tempRegionInfo = $value;
            }
        }

        if (!$tempRegionInfo) {
            return Json::renderWithFalse("请正确传参");
        }

        $districtList = $tempRegionInfo['children'];

        return Json::renderWithTrue($districtList);
    }

    /**
     * 修改价格
     *
     * @Route admin/order/update-price
     */
    public function updatePrice(Request $request)
    {
        $orderId = $request->get('id');

        //查询
        $entity = DB::table(Order::tableName())
            ->asEntity(Order::className())
            ->findByPkOrFail($orderId);

        // 检测这个订单是否可以修改
        if (!$entity->checkUpdatePrice()) {
            Session::setFlash('message', '订单非待支付状态，不可修改');
            return Redirect::back();
        }

        //保存
        $error = '';
        if ($request->isMethod('POST')) {
            if (self::updatePriceAction($entity->id, $request, $error)) {
                return Json::renderWithTrue("保存成功");
            } else {
                return Json::renderWithFalse($error);
            }
        }

        //获取orderId查询order_item表获取商品数据
        $orderId = $request->get('id');
        $orderItemList = DB::table(OrderItem::tableName())->asEntity(OrderItem::className())->where('order_id=?', [$orderId])->findAll();

        $orderItemSkuIds = array_column($orderItemList, 'sku_id');

        //通过sku_id查询sku表
        $skuList = DB::table(Sku::tableName())->whereIn('id', $orderItemSkuIds)->findAll();
        $skuList = array_column($skuList, null, 'id');

        //组合情况
        $productArr = [];
        foreach ($orderItemList as $key => $value) {
            $productArr[$key]['item_id'] = $value['id'];
            $productArr[$key]['sku_id'] = $value['sku_id'];
            $productArr[$key]['product_full_name'] = $value['product_full_name'];
            $productArr[$key]['quantity'] = $value['quantity'];
            $productArr[$key]['price'] = $value['price'];
            $productArr[$key]['total'] = $value['price'] * $value['quantity'];
            $productArr[$key]['color'] = $skuList[$value['sku_id']]['color'];
            $productArr[$key]['size'] = $skuList[$value['sku_id']]['size'];
            $productArr[$key]['version'] = $skuList[$value['sku_id']]['version'];
            $productArr[$key]['product'] = $value->product;

        }

        //视图
        return View::render('@AdminBundle/order/update-price.twig', [
            'entity' => $entity,
            'productArr' => $productArr,
            'error' => $error,
        ]);
    }

    /**
     * 修改价格操作
     * @param $orderId
     * @param Request $request
     * @param string $error
     * @return bool
     */
    private function updatePriceAction($orderId, Request $request, &$error = '')
    {
        $expressFee = $request->get('express_fee');
        $productData = $request->get('Product', []);

        // 验证数据
        if (!is_array($productData)) {
            $error = '请正确传参(单价)';
            return false;
        }

        if (count($productData) <= 0) {
            $error = '缺失参数(单价)';
            return false;
        }

        if ($expressFee < 0) {
            $error = '运费不能小于0';
            return false;
        }

        // 验证运费
        $rule = [
            [['express_fee'], 'trim'],
            [['express_fee'], 'required'],
            [['express_fee'], 'double'],
        ];

        $orderData = [
            'express_fee' => $expressFee,
        ];

        if (!Validator::validate($orderData, $rule, Order::labels())) {
            $error = Validator::getFirstError();
            return false;
        }

        // 验证项目的单价
        $rule = [
            [['price'], 'trim'],
            [['price'], 'required'],
            [['price'], 'double'],
        ];
        foreach ($productData as $item) {
            if ($item < 0) {
                $error = '单价不能小于0';
                return false;
            }

            $tempData = [
                'price' => $item,
            ];

            if (!Validator::validate($tempData, $rule, OrderItem::labels())) {
                $error = Validator::getFirstError();
                return false;
            }
        }

        DB::getConnection()->beginTransaction();

        $order = DB::table(Order::tableName())
            ->asEntity(Order::className())
            ->lockForUpdate()
            ->findByPkOrFail($orderId);

        if (!$order->checkUpdatePrice()) {
            $error = '订单非待支付状态，不可修改价格';
            DB::getConnection()->rollBack();
            return false;
        }

        $list = DB::table(OrderItem::tableName())
            ->asEntity(OrderItem::className())
            ->lockForUpdate()
            ->where('order_id = ?', [$orderId])
            ->findAll();

        if (count($list) != count($productData)) {
            $error = '参数不完成(单价)';
            DB::getConnection()->rollBack();
            return false;
        }

        // 更新单价、统计商品总额
        $productTotalFee = 0;

        foreach ($list as $orderItem) {

            // 验证是否有对应的数据传入
            if (!array_key_exists($orderItem->id, $productData)) {
                $error = '单价参数有误';
                DB::getConnection()->rollBack();
                return false;
            }

            $tempPrice = $productData[$orderItem->id];

            // 价格相同的时候，不更新价格
            if (Util::calc($orderItem['price'], $tempPrice, 'comp', 2) == 0) {

                $productFeeTemp = Util::calc($orderItem['price'], $orderItem['quantity'], '*', 2);

                $productTotalFee = Util::calc($productTotalFee, $productFeeTemp, '+', 2);

                continue;
            }

            // 更新价格
            $rows = DB::table(OrderItem::tableName())
                ->wherePk($orderItem['id'])
                ->update([
                    'price' => $tempPrice,
                    'updated_at' => Carbon::now(),
                ]);

            if ($rows != 1) {
                $error = '单价更新失败';
                DB::getConnection()->rollBack();
                return false;
            }

            // 统计金额
            $productFeeTemp = Util::calc($tempPrice, $orderItem['quantity'], '*', 2);

            $productTotalFee = Util::calc($productTotalFee, $productFeeTemp, '+', 2);
        }

        // 更新订单
        $orderData['product_fee'] = $productTotalFee;
        $orderData['updated_at'] = Carbon::now();

        // 订单总额
        $totalFee = (float)$productTotalFee + (float)$expressFee;
        $totalFee = Util::calc($totalFee, 0, '+', 2);
        $orderData['total_fee'] = $totalFee;

        $rows = DB::table(Order::tableName())
            ->wherePk($orderId)
            ->update($orderData);

        if ($rows != 1) {
            $error = '订单的运费更新失败';
            DB::getConnection()->rollBack();
            return false;
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 详情
     * @Route admin/order/view
     */
    public function getView(Request $request, Application $app)
    {
        //查询
        if (($entity = OrderService::findOne($request->get('id'))) === null) {
            throw new HttpException(500, '操作需要的数据不存在');
        }


        //获取orderId查询order_item表获取商品数据
        $orderId = $request->get('id');
        $orderItemList = DB::table(OrderItem::tableName())->asEntity(OrderItem::className())->where('order_id=?', [$orderId])->findAll();

        $orderItemSkuIds = array_column($orderItemList, 'sku_id');

        //通过sku_id查询sku表
        $skuList = DB::table(Sku::tableName())->whereIn('id', $orderItemSkuIds)->findAll();
        $skuList = array_column($skuList, null, 'id');

        //组合情况
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
            $productArr[$key]['sku_code'] = $skuList[$value['sku_id']]['code'];
            $productArr[$key]['product'] = $value->product;

        }

        //视图
        return View::render('@AdminBundle/order/view.twig', [
            'entity' => $entity,
            'productArr' => $productArr,
        ]);
    }

    /**
     * 确认订单
     *
     * @Route admin/order/confirm
     */
    public function confirm(Request $request)
    {
        /** @var Order $order */

        $order = DB::table(Order::tableName())
            ->asEntity(Order::className())
            ->findByPkOrFail($request->get('id'));

        if (!$order->checkConfirm()) {
            Session::setFlash('message', '操作被禁止');
            return Redirect::back();
        }

        DB::table(Order::tableName())->wherePk($order->id)
            ->update([
                'status' => Order::STATUS_ONGOING,
                'updated_at' => Carbon::now(),
            ]);

        Application::$app['events']->dispatch(
            \Event\OrderEvent::className(),
            new \Event\OrderEvent($order->id, new OrderEventType(OrderEventType::CONFIRM))
        );

        Session::setFlash('message', '操作成功');
        return Redirect::back();
    }

    /**
     * 发货
     * @Route admin/order/delivery
     */
    public function delivery(Request $request, Application $app)
    {
        $order = DB::table(Order::tableName())
            ->asEntity(Order::className())
            ->findByPkOrFail($request->get('id'));

        if (!$order->checkDelivery()) {
            Session::setFlash('message', '操作被禁止');
            return Redirect::back();
        }

        //保存发货信息
        $error = '';
        if ($request->isMethod('post') && OrderService::send($request->get('Delivery'), $error)) {
            Session::setFlash('message', '发货成功');
            return Redirect::to(Url::to('admin/order', ['tab' => 5]));
        }

        if (!empty($error)) {
            Session::setFlash('message', $error);
        }

        //获取物流公司名称
        $carrierArr = DB::table(Carrier::tableName())->where('status=?', [Carrier::STATUS_SHOW])->findAll();
        if (empty($carrierArr)) {
            $error = '请添加物流公司';
            Session::setFlash('message', $error);
            return Redirect::to('admin/carrier');
        }
        $carrier = array_column($carrierArr, 'name', 'id');

        //视图
        return View::render('@AdminBundle/order/send.twig', [
            'entity' => $order,
            'carrier' => $carrier,
        ]);
    }


    /**
     * 将订单置为无效订单
     * @Route admin/order/cancel
     */
    public function cancel(Request $request)
    {
        /** @var Order $order */

        $order = DB::table(Order::tableName())
            ->asEntity(Order::className())
            ->findByPkOrFail($request->get('id'));

        if (!$order->checkCancel()) {
            Session::setFlash('message', '操作被禁止');
            return Redirect::back();
        }

        DB::table(Order::tableName())->wherePk($order->id)
            ->update([
                'status' => Order::STATUS_CANCEL,
                'updated_at' => Carbon::now(),
            ]);

        Session::setFlash('message', '操作成功');
        return Redirect::back();
    }

    /**
     * 收款
     *
     * @Route admin/order/collection
     */
    public function collection(Request $request)
    {
        /** @var Order $order */

        $order = DB::table(Order::tableName())
            ->asEntity(Order::className())
            ->findByPkOrFail($request->get('id'));

        if (!$order->checkCollection()) {
            Session::setFlash('message', '操作被禁止');
            return Redirect::back();
        }

        DB::getConnection()->beginTransaction();

        DB::table(Order::tableName())->wherePk($order->id)
            ->update([
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'updated_at' => Carbon::now(),
            ]);

        DB::table(Payment::tableName())
            ->insert([
                'order_number' => $order->number,
                'pay_type' => Payment::PAY_TYPE_CASH,
                'status' => Payment::STATUS_YES,
                'pay_time' => Carbon::now(),
                'money' => Util::calc($order->total_fee, OrderService::paidMoney, '-'),
            ]);

        DB::getConnection()->commit();

        Session::setFlash('message', '操作成功');

        return Redirect::back();
    }

    /**
     * 订单完成确认
     * @Route admin/order/finish
     */
    public function finish(Request $request)
    {
        //查询
        if (($entity = OrderService::findOne($request->get('id'))) === null) {
            throw new HttpException(500, '操作需要的数据不存在');
        }

        if (OrderService::success($request->get('id'))) {
            Session::setFlash('message', '订单完成确认成功');
        } else {
            Session::setFlash('message', '订单完成确认失败');
        }
        return Redirect::back();
    }
}

