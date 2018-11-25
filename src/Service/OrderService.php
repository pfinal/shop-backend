<?php

namespace AdminBundle\Service;

use Carbon\Carbon;
use Entity\Address;
use Entity\BulkSku;
use Entity\Carrier;
use Entity\Cart;
use Entity\Delivery;
use Entity\Express;
use Entity\ExpressWay;
use Entity\Image;
use Entity\OrderItem;
use Entity\Payment;
use Entity\Product;
use Entity\Sku;
use Entity\Spell;
use Entity\SpellOrder;
use Entity\Wxpay;
use Entity\Wxrefund;
use Enum\OrderEventType;
use Leaf\Application;
use Leaf\DB;
use Leaf\Log;
use Leaf\Pagination;
use Leaf\Url;
use Leaf\Util;
use Leaf\Validator;

use Entity\Order;
use PFinal\Wechat\Service\PayService;
use Service\ExpressService;
use Service\RegionService;
use Service\SpellService;
use TicketBundle\Entity\Ticket;
use TicketBundle\Entity\TicketDetail;
use Vo\OrderCreateVo;

/**
 * 订单
 * @author  Liu Zhiwang
 * @since   1.0
 *
 * @author Wang Manyuan
 * @since 2.0
 */
class OrderService
{
    /**
     * 排序分页查询
     *
     * 为后台的订单管理使用
     *
     * 这里数据查询的基础条件是：无相关拼团、拼团成功的订单
     *
     * @param Pagination|null $page 分页对象
     * @param string $condition 查询条件
     * @param array $params
     * @param string $order
     * @return Order[]
     */
    public static function findListForAdminOrderManage(Pagination $page = null, $condition = '', $params = [], $order = '-o.id')
    {
        $from = 'FROM %s AS o LEFT JOIN %s AS so ON o.id = so.order_id WHERE (so.id is null or so.status = ?) ';
        $from = sprintf($from, Order::tableName(), SpellOrder::tableName());

        if (!empty($condition)) {
            $from .= 'and (' . $condition . ')';
        }

        $params = array_merge([Spell::STATUS_SUCCESS], $params);

        if (is_null($page)) {
            $sql = 'SELECT o.*' . $from . ' ORDER BY ' . $order;

            return DB::table('')->asEntity(Order::className())->findAllBySql($sql, $params);
        }

        $page->itemCount = DB::getConnection()->queryScalar('SELECT COUNT(*) ' . $from, $params);

        $sql = 'SELECT o.* ' . $from . ' ORDER BY ' . $order . '  LIMIT ' . $page->limit;

        return DB::table('')->asEntity(Order::className())->findAllBySql($sql, $params);
    }

    /**
     * 根据主键查询单条
     * @param $id
     * @return Order|null
     */
    public static function findOne($id)
    {
        return DB::table(Order::tableName())
            ->asEntity(Order::className())
            ->findByPk($id);
    }

    /**
     * 订单发货
     *
     * @param array $data
     * @param string $error
     * @return string 成功返回自增id，失败返回0
     */
    public static function send($data, &$error = '')
    {
        //验证
        $rule = [
            [['order_id', 'carrier_id', 'number'], 'required'],
            [['order_id', 'carrier_id'], 'integer'],
            ['number', 'string'],
        ];
        if (!Validator::validate($data, $rule, Delivery::labels())) {
            $error = Validator::getFirstError();
            return 0;
        }

        $data['created_at'] = $data['updated_at'] = date('Y-m-d H:i:s');


        DB::getConnection()->beginTransaction();

        $order = DB::table(Order::tableName())
            ->asEntity(Order::className())
            ->lockForUpdate()
            ->findByPk($data['order_id']);

        if ($order == null) {
            $error = '订单数据不存在';
            DB::getConnection()->rollBack();
            return false;
        }

        if (!$order->checkDelivery()) {
            $error = '该订单不能发货';
            DB::getConnection()->rollBack();
            return false;
        }

        DB::table(Order::tableName())->wherePk($data['order_id'])
            ->update([
                'delivery_status' => Order::DELIVERY_STATUS_SEND,
                'updated_at' => Carbon::now(),
            ]);

        if (($id = DB::table(Delivery::tableName())->insertGetId($data)) > 0) {

            DB::getConnection()->commit();


            //发货事件
            Application::$app['events']->dispatch('Event\\OrderEvent', new \Event\OrderEvent($data['order_id'], new OrderEventType(OrderEventType::SEND)));


            return $id;
        } else {

            DB::getConnection()->rollBack();

            $error = '系统错误';
            return 0;
        }
    }

    /**
     * 完成订单
     *
     * @param $id
     * @return bool
     */
    public static function success($id)
    {
        $order = DB::table(Order::tableName())
            ->findByPk($id);

        if ($order == null) {
            return false;
        }

        //已付款 已收货
        if ($order['payment_status'] == Order::PAYMENT_STATUS_SUCCESS
            && $order['delivery_status'] == Order::DELIVERY_STATUS_SUCCESS) {

            return 1 == DB::table(Order::tableName())
                    ->where('id = ?', [$id])
                    ->update([
                        'status' => Order::STATUS_SUCCESS,
                        'updated_at' => Carbon::now(),
                    ]);
        }

    }

    /**
     * 检查支付状态，如果已支付金额正确，将订单改为已支付
     *
     * @param $orderNumber
     */
    public static function checkPaymentStatus($orderNumber)
    {
        $order = DB::table(Order::tableName())
            ->where('number=?', $orderNumber)
            ->findOne();

        if ($order == null) {
            return;
        }

        //已支付金额
        $money = self::paidMoney($order['number']);

        if ($money == $order['total_fee']) {

            //在线支付
            if ($order['pay_type'] == Order::PAY_TYPE_ONLINE && $order['payment_status'] == Order::PAYMENT_STATUS_NO) {

                //在线支付，更新支付状态时，自动确认订单
                DB::table(Order::tableName())
                    ->wherePk($order['id'])
                    ->update([
                        'status' => Order::STATUS_ONGOING,
                        'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                    ]);


                Application::$app['events']->dispatch(
                    \Event\OrderEvent::className(),
                    new \Event\OrderEvent($order['id'], new OrderEventType(OrderEventType::CONFIRM))
                );

            } else {

                //非在线支付情况，只更新支付状态
                DB::table(Order::tableName())
                    ->wherePk($order['id'])
                    ->update([
                        'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                    ]);
            }

            // 拼团已付款的情况下，进行检测更新对应的拼团数据
            SpellService::updateSpellByPayYesOrder($order['id']);

        }
    }

    /**
     * 订单已支付金额
     *
     * @param $orderNumber
     * @return float
     */
    public static function paidMoney($orderNumber)
    {
        //已支付金额
        $list = DB::table(Payment::tableName())
            ->where('order_number=?', $orderNumber)
            ->where('status=?', Payment::STATUS_YES)
            ->findAll();

        $sum = 0;
        foreach ($list as $item) {
            $sum = Util::calc($item['money'], $sum, '+');
        }

        return $sum;
    }

    /* 20180718 Wang Manyuan 新加数据 */

    /**
     * 根据金额和地址计算运费
     * @param Product[] $productList
     * @param $totalQuantity
     * @param $productFee
     * @param Address $address
     * @param string $error
     * @return array
     */
    public static function calcExpressFee(array $productList, $totalQuantity, $productFee, Address $address, &$error = '')
    {
        if (count($productList) <= 0) {
            $error = '无对应商品';
            return false;
        }

        // 找出商品相关的所有运费模板
        $expressList = [];

        foreach ($productList as $product) {
            $tempExpress = $product->userExpress();

            $expressList[$tempExpress['id']] = $tempExpress;
        }

        // 计算运费
        $expressFeeDataList = [];
        foreach ($expressList as $express) {
            $tempExpressData = ExpressService::calcExpressFeeActionForOneExpress($productFee, $totalQuantity, $express, $address, $error);

            if ($tempExpressData === false) {
                return false;
            }

            $expressFeeDataList[$express['id']] = $tempExpressData;
        }

        // todo 获取最高的运费进行收费
        $edition = [];
        foreach ($expressFeeDataList as $v) {
            $edition[] = (float)$v['express_fee'];
        }
        array_multisort($edition, SORT_DESC, $expressFeeDataList);

        return current($expressFeeDataList);

//        $data = [
//            'enabled' => true, //该地址是否支持发货
//            'express_fee' => 0,
//            'description' => '全场包邮',
//        ];

//        if ($productFee >= 50) {
//
//            $data = [
//                'enabled' => true,
//                'express_fee' => 0,
//                'description' => '满50免运费',
//            ];
//
//        } else {
//
//            //默认运费
//            $expressFee = 6;
//
//            $data = [
//                'enabled' => true,  //该地址是否支持发货
//                'express_fee' => Util::calc($expressFee, 0, '+', 2),
//                'description' => '基础运费',
//            ];
//        }

//        return $data;
    }

    /**
     * 根据购物车内容进行新增订单
     * @param $userId
     * @param string $cartIds 购物车ID
     * @param OrderCreateVo $orderCreateVo
     * @param string $error
     * @param string $errorCode
     * @return array|bool
     */
    public static function createByCartIds($userId, $cartIds, OrderCreateVo $orderCreateVo, &$error = '', &$errorCode = '')
    {
        //计算订单总价和商品件数
        $info = self::calcProductFeeByCartIds($cartIds, $userId, $error, $errorCode);
        if ($info == false) {
            return false;
        }

        $productFee = $info['productFee'];        //商品总额
        $totalQuantity = $info['totalQuantity'];  //总件数
        $productList = $info['productList'];      //用户下单的商品列表
        $cartList = $info['cartList'];

        // 对应的库存信息
        $skuList = [];
        foreach ($cartList as $cart) {

            $tempSkuInfo = DB::table(Sku::tableName())
                ->asEntity(Sku::className())
                ->where('status = ?', [Sku::STATUS_DISPLAY])
                ->findByPk($cart['sku_id']);

            if ($tempSkuInfo == null) {
                $error = '商品库存不存在';
                $errorCode = 'no sku';
                return false;
            }

            $skuList[] = [
                'id' => $cart['sku_id'],
                'quantity' => $cart['quantity'],
                'price' => $tempSkuInfo->getNowPrice(),
                'sku_info' => $tempSkuInfo,
            ];
        }

        DB::getConnection()->beginTransaction();

        // 新增订单
        $result = self::createAction($userId, $orderCreateVo, $productFee, $totalQuantity, $productList, $skuList, $error, $errorCode);

        if ($result === false) {
            DB::getConnection()->rollBack();
            return false;
        }

        //清空购物车
        if (static::updateCartToOrder($userId, $cartIds) < 1) {
            DB::getConnection()->rollBack();
            $error = '系统错误';
            $errorCode = 'DELETE_CART';
            return false;
        }

        DB::getConnection()->commit();

        return [
            'number' => $result['number'],
            'order_id' => $result['order_id'],
        ];
    }

    /**
     * 根据 库存ID+数量 进行新增订单
     * @param $userId
     * @param $skuId
     * @param $quantity
     * @param OrderCreateVo $orderCreateVo
     * @param string $error
     * @param string $errorCode
     * @return array|bool
     */
    public static function createBySkuId($userId, $skuId, $quantity, OrderCreateVo $orderCreateVo, &$error = '', &$errorCode = '')
    {
        //计算订单总价和商品件数
        $totalQuantity = $quantity;

        $resultData = ProductService::productAndSkuBySkuId($skuId, $error, $errorCode);

        if ($resultData === false) {
            return false;
        }

        $sku = $resultData['sku'];
        $product = $resultData['product'];

        $price = $sku->getNowPrice();

        $productFee = Util::calc($price, $quantity, '*', 2);

        // 准备新增订单要用的数据
        $productList = [$product];
        $skuList = [];

        $skuList[] = [
            'id' => $sku['id'],
            'quantity' => $quantity,
            'price' => $price, // 使用库存的的价格
            'sku_info' => $sku,
        ];

        // 新增订单
        $result = self::createAction($userId, $orderCreateVo, $productFee, $totalQuantity, $productList, $skuList, $error, $errorCode);

        if ($result === false) {
            return false;
        }

        return [
            'number' => $result['number'],
            'order_id' => $result['order_id'],
        ];
    }

    /**
     * 新增订单   仅提供给以下使用：
     *
     * OrderService::createByCartIds
     * OrderService::createBySkuId
     * SpellService::createOrder
     *
     *
     * @param $userId
     * @param OrderCreateVo $orderCreateVo
     * @param double $productFee 商品总额
     * @param int $totalQuantity 商品总数量
     * @param array $productList 用户下单的商品列表
     * @param array $skuList 库存列表  由  id、quantity、price、sku对象 组成的数组
     * @param string $error
     * @param string $errorCode
     * @return array|bool
     */
    public static function createAction($userId, OrderCreateVo $orderCreateVo, $productFee, $totalQuantity, $productList, $skuList, &$error = '', &$errorCode = '')
    {
        //获取用户id和订单表信息

        //验证传输过来的数据
        $data = [
            'pay_type' => (int)$orderCreateVo->payType,
            'delivery_type' => (int)$orderCreateVo->deliveryType,
            'express_fee' => 0,
            'invoice_type' => (int)$orderCreateVo->invoiceType,
            'invoice_title' => $orderCreateVo->invoiceTitle,
            'invoice_taxpayer_ident' => $orderCreateVo->invoiceTaxpayerIdent,
            'invoice_content' => $orderCreateVo->invoiceContent,
            'message' => $orderCreateVo->message,
            'addressId' => (int)$orderCreateVo->addressId,
        ];

        $ticketDetailId = $orderCreateVo->ticketDetailId; // 卡券ID

        $rules = [
            [['pay_type', 'delivery_type', 'express_fee', 'invoice_type', 'addressId'], 'required'],
            ['pay_type', 'in', 'range' => [Order::PAY_TYPE_ONLINE, Order::PAY_TYPE_CASH_ON_DELIVERY, Order::PAY_TYPE_OFFLINE]],
            ['delivery_type', 'in', 'range' => [Order::DELIVERY_TYPE_CARRIER, Order::DELIVERY_TYPE_PICK]],
            ['express_fee', 'number'],
            ['invoice_type', 'in', 'range' => [Order::INVOICE_TYPE_NO, Order::INVOICE_TYPE_YES]],
            [['invoice_title', 'invoice_content', 'invoice_taxpayer_ident'], 'string', 'length' => [0, 255]],
            ['message', 'string', 'length' => [0, 255]],
            ['addressId', 'integer'],
        ];
        $labels = [
            'pay_type' => '支付方式',
            'delivery_type' => '运输方式',
            'express_fee' => '运费',
            'invoice_type' => '是否开发票',
            'invoice_title' => '发票抬头',
            'invoice_content' => '发票内容',
            'invoice_taxpayer_ident' => '纳税人识别号',
            'message' => '备注',
            'addressId' => '地址',
        ];
        if (!Validator::validate($data, $rules, $labels)) {
            $error = Validator::getFirstError();
            return false;
        }

        //收货人信息
        $address = DB::table(Address::tableName())
            ->asEntity(Address::className())
            ->findByPk($data['addressId']);

        if ($address == null) {
            $error = '地址不能为空';
            $errorCode = 'no address';
            return false;
        }

        if ($address['user_id'] != $userId) {
            $error = '请选择地址';
            $errorCode = 'ADDRESS_ID_ERROR';
            return false;
        }

        $temp = OrderService::calcExpressFee($productList, $totalQuantity, $productFee, $address, $error);

        if ($temp === false) {
            return false;
        }

        $data['express_fee'] = $temp['express_fee'];

        if (!$temp['enabled']) {
            $error = '不支持发货到该地区';
            $errorCode = 'EXPRESS_ERROR';
            return false;
        }

        //订单名称
        $name = '购买' . $productList[0]['name'] . '等共' . $totalQuantity . '件商品';

        //运费及订单总额
        $expressFee = $data['express_fee'];
        $totalFee = $productFee + $expressFee;

        $regionId = $address['province'];

        //订单号 (要转为string，不然js会当作浮点数，会产生精度问题)
        $orderNumber = (string)DB::getConnection()->queryScalar('select uuid_short()');

        //组装完整订单信息并存入订单表
        $order = [
            'number' => $orderNumber,
            'name' => $name,
            'user_id' => $userId,
            'pay_type' => $data['pay_type'],
            'delivery_type' => $data['delivery_type'],
            'product_fee' => $productFee,
            'express_fee' => $data['express_fee'],
            'total_fee' => $totalFee,
            'receiver_name' => $address['name'],
            'receiver_province' => $address['province'],
            'receiver_city' => $address['city'],
            'receiver_district' => $address['district'],
            'receiver_detail' => $address['detail'],
            'receiver_zip' => $address['zip'],
            'receiver_phone' => $address['phone'],
            'receiver_mobile' => $address['mobile'],
            'receiver_email' => $address['email'],
            'invoice_type' => $data['invoice_type'],
            'invoice_title' => $data['invoice_title'],
            'invoice_taxpayer_ident' => $data['invoice_taxpayer_ident'],
            'invoice_content' => $data['invoice_content'],
            'message' => $data['message'],
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];

        // 当支付方式为在线支付的时候，状态为进行中
        if ($order['pay_type'] == Order::PAY_TYPE_ONLINE) {
            $order['status'] = Order::STATUS_ONGOING;
        }

        DB::getConnection()->beginTransaction();

        $orderId = DB::table(Order::tableName())->insertGetId($order);

        //往订单明细表里存数据
        $tempSkuRule = [
            [['id', 'quantity', 'price',], 'trim'],
            [['id', 'quantity', 'price',], 'required'],
            [['id', 'quantity',], 'integer'],
            [['price',], 'double'],
            [['sku_info',], 'safe'],
        ];
        foreach ($skuList as $tempSkuData) {
            if (!Validator::validate($tempSkuData, $tempSkuRule)) {
                DB::getConnection()->rollBack();
                $error = Validator::getFirstError();
                return false;
            }

            $sku = isset($tempSkuData['sku_info']) ? $tempSkuData['sku_info'] : false;

            if (!$sku) {
                DB::getConnection()->rollBack();
                $error = '商品库存不存在';
                $errorCode = 'no sku';
                return false;
            }

            // 判断数量是否超过库存的数量
            $skuInfo = DB::table(Sku::tableName())
                ->lockForUpdate()
                ->where('status=?', [Sku::STATUS_DISPLAY])
                ->findByPk($tempSkuData['id']);

            if ($skuInfo == null) {
                $error = '库存信息有误';
                $errorCode = 'no sku';
                DB::getConnection()->rollBack();
                return false;
            }

            if ($skuInfo['quantity'] < $tempSkuData['quantity']) {
                $error = '库存不足';
                DB::getConnection()->rollBack();
                return false;
            }

            //更新库存数量
            $row = DB::table(Sku::tableName())
                ->where('id=?', [$tempSkuData['id']])
                ->where('status=?', [Sku::STATUS_DISPLAY])
                ->increment('quantity', -$tempSkuData['quantity']);

            if ($row != 1) {
                DB::getConnection()->rollBack();
                $error = '系统错误';
                $errorCode = 'UPDATE_STOCK_ERROR';
                return false;
            }

            //商品完整名称
            $product = DB::table(Product::tableName())->findByPk($sku['product_id']);

            $productFullName = $product['name'];
            if (!empty($sku['color'])) {
                $productFullName .= ' ' . $sku['color'];
            }
            if (!empty($sku['size'])) {
                $productFullName .= ' ' . $sku['size'];
            }
            if (!empty($sku['version'])) {
                $productFullName .= ' ' . $sku['version'];
            }

            // 20180801 检测商品对应地区是否可以配送
            if (!RegionService::checkProductRegion($product['id'], $regionId)) {
                $error = $product['name'] . '暂不支持配送该区域';
                DB::getConnection()->rollBack();
                return false;
            }

            // 20180923 检测是否限购
            if ($product['buy_num']) {
                $hadBuyNum = self::getHadBuyNum($userId, $product['id']);

                $tempNum = (int)$product['buy_num'] - (int)$hadBuyNum;

                if ($tempSkuData['quantity'] > $tempNum) {
                    $error = '您购买的' . $product['name'] . '已超过购买上限';
                    DB::getConnection()->rollBack();
                    return false;
                }
            }

            // 20180710 新加product_data字段，记录商品名、颜色、尺码、版本的json数据
            $productData = [
                'product_name' => $product['name'],
                'color' => $sku['color'],
                'size' => $sku['size'],
                'version' => $sku['version'],
            ];

            $orderItem = [
                'order_id' => $orderId,
                'product_id' => $product['id'],
                'product_full_name' => $productFullName,
                'sku_id' => $sku['id'],
                'quantity' => $tempSkuData['quantity'],
                'price' => $tempSkuData['price'], // 使用存入的单价，因为这里的单价不一定就是sku中的价格，存在着团购价格，以后可能还有其他价格
                'user_id' => $userId,
                'product_data' => json_encode($productData),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            DB::table(OrderItem::tableName())->insert($orderItem);
        }

        //使用卡券
        if ($ticketDetailId) {
            if (!self::useTicket($order, $ticketDetailId, $error)) {
                DB::getConnection()->rollBack();
                $errorCode = '';
                return false;
            }
        }

        DB::getConnection()->commit();

        //检查订单状态(有可能卡券直接购买，不需要支付)
        OrderService::checkPaymentStatus($order['number']);

        return [
            'number' => $order['number'],
            'order_id' => $orderId
        ];
    }

    /**
     * 获取会员已经购买的数量
     * @param $userId
     * @param $projectId
     * @return int
     */
    public static function getHadBuyNum($userId, $projectId)
    {
        // 会员之前购买的数量
        $from = 'FROM %s AS o LEFT JOIN %s AS oi ON o.id = oi.order_id WHERE (o.user_id = ?) ';
        $from = sprintf($from, Order::tableName(), OrderItem::tableName());

        $condition = [];
        $params = [];

        // 排除 取消、退货完成 的订单
        $condition[] = 'o.status != ?';
        $params[] = Order::STATUS_CANCEL;

        $condition[] = 'oi.product_id = ?';
        $params[] = $projectId;

        $condition = join(' and ', $condition);

        if (!empty($condition)) {
            $from .= ' and (' . $condition . ')';
        }

        $params = array_merge([$userId], $params);

        $sql = 'SELECT sum(oi.quantity) as `quantity`' . $from;

        $tempNum = DB::getConnection()->query($sql, $params);

        $tempNum = current($tempNum)['quantity'];

        return $tempNum;
    }

    /**
     * 已下单的购物车从列表删除
     * @param int $userId
     * @param string $cartIds
     * @return int
     * @author Li Yangyang
     */
    private static function updateCartToOrder($userId, $cartIds)
    {
        $cartIds = explode(',', $cartIds);

        if (count($cartIds) <= 0) {
            return false;
        }

        $row = DB::table(Cart::tableName())
            ->where('user_id=?', [$userId])
            ->whereIn('id', $cartIds)
            ->update(['status' => Cart::STATUS_ORDER]);

        return $row;
    }

    /**
     * 下单时，使用卡券
     */
    private static function useTicket(array $order, $ticketDetialId, &$error = '')
    {
        DB::getConnection()->beginTransaction();

        /** @var TicketDetail $ticketDetail */
        $ticketDetail = DB::table(TicketDetail::tableName())
            ->asEntity(TicketDetail::className())
            ->lockForUpdate()
            ->findByPk($ticketDetialId);

        if ($ticketDetail == null) {
            DB::getConnection()->rollBack();
            $error = '卡券不存在[ticketDetailId:' . $ticketDetialId . ']';
            return false;
        }

        //非本人卡券
        if ($order['user_id'] != $ticketDetail['user_id']) {
            DB::getConnection()->rollBack();
            $error = '卡券非本人所有';
            return false;
        }

        // 非未使用状态
        if ($ticketDetail->status != TicketDetail::STATUS_UNUSED) {
            DB::getConnection()->rollBack();
            $error = '卡券状态异常';
            return false;
        }

        //未启用或已过期
        if ($ticketDetail->begin_timestamp > time() || $ticketDetail->end_timestamp < time()) {
            DB::getConnection()->rollBack();
            $error = '卡券未启用或已过期';
            return false;
        }

        $ticket = $ticketDetail->getTicket();
        if ($ticket == null) {
            DB::getConnection()->rollBack();
            $error = '卡券数据异常';
            return false;
        }

        //商品金额小于抵扣条件
        if ($order['total_fee'] < $ticket->least_cost) {
            DB::getConnection()->rollBack();
            $error = '订单金额未达到抵扣条件';
            return false;
        }

        //非代金券
        if ($ticket->type != Ticket::TYPE_CASH) {
            DB::getConnection()->rollBack();
            $error = '卡券非代金券';
            return false;
        }

        $bool1 = DB::table(TicketDetail::tableName())
            ->wherePk($ticketDetialId)
            ->update([
                'status' => TicketDetail::STATUS_USED,
                'exchange_timestamp' => time(),
                'updated_at' => Carbon::now(),
                'order_number' => $order['number'],
            ]);

        $bool2 = DB::table(Payment::tableName())
            ->insert([
                'order_number' => $order['number'],
                'pay_type' => Payment::PAY_TYPE_TICKET,
                'status' => Payment::STATUS_YES,
                'pay_time' => Carbon::now(),
                'money' => min($ticket->reduce_cost, $order['total_fee']),
            ]);

        if ($bool1 && $bool2) {

            DB::getConnection()->commit();

            return true;
        }

        DB::getConnection()->rollBack();

        $error = '系统错误，请重试';
        return false;
    }

    /**
     * 根据购物车IDs 获取用户的购物车内容
     * @param array $ids
     * @param $userId
     * @return array
     */
    private static function getCartListByIds(array $ids, $userId)
    {
        if (count($ids) == 0) {
            return [];
        }

        return DB::table(Cart::tableName())
            ->asEntity(Cart::className())
            ->whereIn('id', $ids)
            ->where('user_id=? and status=?', [$userId, Cart::STATUS_WAIT])
            ->findAll();
    }

    /**
     * 根据购物车ID统计项目价格和数量
     * @return array|false
     */
    public static function calcProductFeeByCartIds($cartIds, $userId, &$error = '', &$errorCode = '0')
    {
        $productFee = 0;
        $totalQuantity = 0;
        $productList = [];
        $skuList = [];

        if (empty($cartIds)) {

            $error = 'cartIds数据异常1';
            return false;
        }

        $cartIds = explode(',', $cartIds);

        if (!is_array($cartIds) || count($cartIds) == 0) {
            $error = '参数异常';
            $errorCode = 'CART_ID_MISS';
            return false;
        }

        //购物车列表
        $cartList = static::getCartListByIds($cartIds, $userId);
        if (!$cartList) {
            $error = '参数异常';
            $errorCode = 'CART_ID_INVALID';
            return false;
        }

        if (count($cartList) != count($cartIds)) {
            $error = '购物车数据有误';
            $errorCode = 'cart data not match cart_id';
            return false;
        }

        //计算订单总价和商品件数
        foreach ($cartList as $cart) {
            //商品库存信息
            $sku = DB::table(Sku::tableName())
                ->asEntity(Sku::className())
                ->where('status = ?', [Sku::STATUS_DISPLAY])
                ->findByPk($cart['sku_id']);
            $skuList[] = $sku;

            //商品信息
            $product = DB::table(Product::tableName())
                ->asEntity(Product::className())
                ->findByPk($sku['product_id']);

            if ($product == null) {
                $error = '商品不存在';
                return false;
            }
            if ($sku == null) {
                $error = '商品' . $product['name'] . '库存不存在';
                return false;
            }
            $productList[] = $product;

            //如果库存小于购买数或购买数小于1  下单失败
            if ($cart['quantity'] < 1 || $sku['quantity'] < $cart['quantity']) {
                $error = '商品' . $product['name'] . '库存不足';
                return false;
            }

            $price = $sku->getNowPrice();

            $productFeeTemp = Util::calc($price, $cart['quantity'], '*', 2);

            $productFee = Util::calc($productFee, $productFeeTemp, '+', 2);
            $totalQuantity += $cart['quantity'];
        }

        return [
            'productFee' => $productFee,
            'totalQuantity' => $totalQuantity,
            'productIds' => Util::arrayColumn($productList, 'id'),
            'productList' => $productList,
            'cartList' => $cartList,
            'skuList' => $skuList,
        ];
    }

    /**
     * 为接口返回订单信息做处理
     * @param $orderList
     * @return array
     */
    public static function handleOrderApiReturnData($orderList)
    {
        foreach ($orderList as $key => $order) {

            /** @var $order Order */

            $orderItem = DB::table(OrderItem::tableName())->where('order_id=?', [$order['id']])->findAll();

            foreach ($orderItem as $k => $val) {

                /** @var Image $image */
                $image = DB::table('image')
                    ->asEntity(Image::className())
                    ->where('product_id=? and type=?', [$val['product_id'], Image::TYPE_COVER])
                    ->findOne();

                if ($image != null) {
                    //缩略图
                    $orderItem[$k]['cover_pic'] = $image->getUrl('s');
                } else {
                    $orderItem[$k]['cover_pic'] = Url::asset('images/no-pic.jpg', true);
                }

                $orderItem[$k]['product_data'] = json_decode($val['product_data'], true);
            }

            $orderList[$key]['order_status'] = $order->showStatusStrForApi();

            $orderList[$key]['order_item'] = $orderItem;

            // 20181122 新加

            // 检测支付是否过期
            $orderList[$key]['pay_expire'] = 0; // 默认未过期
            $remainingTime = strtotime($order['created_at']) + Order::PAY_EXPIRE_TIME;
            $remainingTime = $remainingTime - time();
            if ($remainingTime <= 0) {
                $orderList[$key]['pay_expire'] = 1; // 过期
            }

            // 当状态为待支付的时候，展示为"支付过期"
            if ($order['payment_status'] == Order::PAYMENT_STATUS_NO && $order['pay_type'] == Order::PAY_TYPE_ONLINE) {
                if (($order['status'] == Order::STATUS_NEW) || ($order['status'] == Order::STATUS_ONGOING)) {
                    if ($order['delivery_status'] == Order::DELIVERY_STATUS_NO) {
                        if ($orderList[$key]['pay_expire']) {
                            $orderList[$key]['order_status'] = '支付过期';
                        }
                    }
                }
            }
        }

        return $orderList;
    }

    /**
     * 根据订单编号取消订单
     * @param $number
     * @param $userId
     * @param string $error
     * @return bool
     */
    public static function cancelOrderByOrderNumber($number, $userId, &$error = '')
    {
        DB::getConnection()->beginTransaction();

        /** @var Order $order */
        $order = DB::table(Order::tableName())
            ->asEntity(Order::className())
            ->lockForUpdate()
            ->where('number=?', [$number])
            ->where('user_id=?', [$userId])
            ->findOne();

        if ($order == null) {
            $error = '订单ID错误';
            DB::getConnection()->rollBack();
            return false;
        }

        if (!$order->allowCancel()) {
            $error = '订单当前状态不能取消';
            DB::getConnection()->rollBack();
            return false;
        }

        // 取消订单操作
        if (!self::cancelOrderAction($order, $userId, $error)) {
            DB::getConnection()->rollBack();
            return false;
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 根据订单ID取消订单
     *
     * 仅为 未过期拼团提供的方法
     *
     * @param $orderId
     * @param $userId
     * @param string $error
     * @return bool
     */
    public static function cancelOrderByOrderIdForExpire($orderId, $userId, &$error = '')
    {
        $orderId = (int)$orderId;

        DB::getConnection()->beginTransaction();

        $order = DB::table(Order::tableName())
            ->asEntity(Order::className())
            ->lockForUpdate()
            ->where('user_id=?', [$userId])
            ->findByPk($orderId);

        if ($order == null) {
            $error = '订单ID错误';
            DB::getConnection()->rollBack();
            return false;
        }

        if ($order == null) {
            $error = '订单ID错误';
            DB::getConnection()->rollBack();
            return false;
        }

        // 未发货可以退款，否则不可动
        // 涉及到总额为0的商品，故这里只要是拼团中的，且未发货的都可以退款

        if ($order['delivery_status'] != Order::DELIVERY_STATUS_NO) {
            $error = '订单当前状态不能取消';
            DB::getConnection()->rollBack();
            return false;
        }

        // 取消订单操作
        if (!self::cancelOrderAction($order, $userId, $error)) {
            DB::getConnection()->rollBack();
            return false;
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 取消订单的操作
     *
     * 20180808 Wang Manyuan 调用此方法的外围必须配有事务，锁住订单的这条记录
     *
     * @param Order $order
     * @param $userId
     * @param string $error
     * @return bool
     */
    private static function cancelOrderAction(Order $order, $userId, &$error = '')
    {
        DB::getConnection()->beginTransaction();

        //修改订单状态
        $bool = DB::table(Order::tableName())
            ->where('id=?', [$order['id']])
            ->where('user_id=?', [$userId])
            ->update(['status' => Order::STATUS_CANCEL]);

        if (!$bool) {
            $error = '取消订单失败(状态)';
            DB::getConnection()->rollBack();
            return false;
        }

        //订单明细表信息
        $orderItemList = DB::table(OrderItem::tableName())
            ->where('order_id=?', [$order['id']])
            ->where('user_id=?', [$userId])
            ->findAll();

        if (count($orderItemList) == 0) {
            $error = '订单不存在';
            DB::getConnection()->rollBack();
            return false;
        }

        //还原库存
        foreach ($orderItemList as $orderItem) {

            $row = DB::table(Sku::tableName())
                ->where('id=?', [$orderItem['sku_id']])
                ->increment('quantity', $orderItem['quantity']);
            if ($row != 1) {
                //还原失败回滚
                DB::getConnection()->rollBack();
                $error = '取消失败(库存)';
                return false;
            }
        }

        // 退款
        if (!self::refundPaymentForOrder($order['number'], $error)) {
            DB::getConnection()->rollBack();
            return false;
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 为取消订单处理对应的支付记录
     * @param $orderNumber
     * @param string $error
     * @return bool
     */
    private static function refundPaymentForOrder($orderNumber, &$error = '')
    {
        $paymentList = DB::table(Payment::tableName())
            ->asEntity(Payment::className())
            ->where('order_number = ?', [$orderNumber])
            ->where('status = ?', [Payment::STATUS_YES])
            ->findAll();

        if(count($paymentList) > 0) {
            $error = '已付款订单不允许取消';
            return false;
        }

        return true;

//        DB::getConnection()->beginTransaction();
//
//        $paymentList = DB::table(Payment::tableName())
//            ->asEntity(Payment::className())
//            ->where('order_number = ?', [$orderNumber])
//            ->where('status = ?', [Payment::STATUS_YES])
//            ->findAll();
//
//        if (count($paymentList) <= 0) {
//            DB::getConnection()->commit();
//            return true;
//        }
//
//        // 将订单更新为已退款
//        $rows = DB::table(Order::tableName())
//            ->where('number = ?', [$orderNumber])
//            ->update(['payment_status' => Order::PAYMENT_STATUS_RETURN_SUCCESS, 'updated_at' => Carbon::now()]);
//
//        if ($rows != 1) {
//            $error = '订单退款失败';
//            DB::getConnection()->rollBack();
//            return false;
//        }
//
//        // 超过6个月订单无法退款
//        $temp = current($paymentList)['created_at'];
//
//        if (strtotime($temp) <= (time() - 6 * 30 * 24 * 60 * 60)) {
//            $error = '6个月前的订单无法进行退款';
//            DB::getConnection()->rollBack();
//            return false;
//        }
//
//        // 处理卡券的支付记录
//        $ticketPaymentList = [];
//        $newPaymentList = []; // 排除卡券之外的支付记录
//
//        foreach ($paymentList as $payment) {
//
//            // 处理卡券
//            if ($payment['pay_type'] == Payment::PAY_TYPE_TICKET) {
//                $ticketPaymentList[] = $payment;
//            } else {
//                $newPaymentList[] = $payment;
//            }
//
//        }
//
//        if (count($ticketPaymentList) > 0) {
//            if (!self::refundTicketForPayment($orderNumber, $paymentList, $error)) {
//                DB::getConnection()->rollBack();
//                return false;
//            }
//        }
//
//        $paymentList = $newPaymentList;
//
//        // todo 暂时不支持支付宝
//        foreach ($paymentList as $payment) {
//            if ($payment['pay_type'] == Payment::PAY_TYPE_ALIPAY) {
//                $error = '暂不支持支付宝退款';
//                DB::getConnection()->rollBack();
//                return false;
//            }
//        }
//
//        // 将剩余的支付记录进行退款
//        $checkFirstRefund = 0; // 是否第一次退款 第一次退款失败则回滚，否则不回滚
//        foreach ($paymentList as $payment) {
//
//            // 将支付记录作废
//            if (DB::table(Payment::tableName())->wherePk($payment['id'])->update([
//                    'status' => Payment::STATUS_RETURN,
//                    'refund_time' => Carbon::now(),
//                ]) != 1) {
//                $error = '退款失败';
//                DB::getConnection()->rollBack();
//                return false;
//            }
//
//            // 微信，将退款到微信
//            if ($payment['pay_type'] == Payment::PAY_TYPE_WXPAY) {
//                // 第一次的退款失败则回滚；否则不回滚
//                if (!self::refundWxpayForPayment($payment, $error)) {
//
//                    if ($checkFirstRefund == 0) {
//                        DB::getConnection()->rollBack();
//                        return false;
//                    }
//
//                    $checkFirstRefund += 1;
//
//                    // 记录错误日志
//                    Log::warning("微信退款失败(退成功过)", ['title' => '取消订单退款', 'orderNumber' => $orderNumber, 'error' => $error]);
//                }
//            }
//
//        }
//
//        DB::getConnection()->commit();
//        return true;
    }

    /**
     * 微信退款
     * @param Payment $payment
     * @param string $error
     * @return bool
     */
    private static function refundWxpayForPayment(Payment $payment, &$error = '')
    {
        $out_trade_no = $payment->out_trade_no;

        // 查找对应的微信支付记录
        $info = DB::table(Wxpay::tableName())
            ->asEntity(Wxpay::className())
            ->where('out_trade_no = ?', [$out_trade_no])
            ->findOne();

        if ($info == null) {
            $error = '无对应的微信支付记录';
            return false;
        }

        // 查询是否有对应的退款记录
        // todo 因为这里退款暂时是一次性退款

        $wxrefund = DB::table(Wxrefund::tableName())
            ->asEntity(Wxrefund::className())
            ->where('wxpay_id = ?', [$info['id']])
            ->findOne();

        if ($wxrefund == null) {
            // 退款记录
            $data = [
                'wxpay_id' => $info['id'],
                'money' => $info['total_fee'],
            ];

            $data['created_at'] = $data['updated_at'] = Carbon::now();

            // 退款编号
            $refundNumber = strtolower(str_replace('-', '', Util::guid()));
            $data['out_trade_no'] = $refundNumber;

            $rewfunId = DB::table(Wxrefund::tableName())->insertGetId($data);

            $wxrefund = DB::table(Wxrefund::tableName())
                ->asEntity(Wxrefund::className())
                ->findByPk($rewfunId);
        }

        return self::refundWxpayAction($info['out_trade_no'], $info['total_fee'], $wxrefund, $error);
    }

    /**
     * 微信退款执行
     * @param $out_trade_no
     * @param $totalFee
     * @param Wxrefund $wxrefund
     * @return bool
     */
    public static function refundWxpayAction($out_trade_no, $totalFee, Wxrefund $wxrefund, &$error = '')
    {
        // 初始化SDK
        $config = Application::$app['open.weixin.config'];
        \PFinal\Wechat\Kernel::init($config);

        try {
            // 退款
            $result = PayService::refund($out_trade_no, $totalFee, $wxrefund['money'], $wxrefund['out_trade_no']);

//        if (!isset($result['return_code'])) {
//            $error = '微信退款失败';
//            Log::error("微信退款失败", ['message' => '返回数据无return_code', 'wxrefund_number' => $result['out_trade_no'], 'result' => json_encode($result)]);
//            return false;
//        }
//
//        $code = $result['return_code'];
//        if ($code != 'SUCCESS') {
//            $error = isset($result['return_msg']) ? $result['return_msg'] : '微信退款失败';
//            Log::error("微信退款失败", ['message' => 'return_code不为SUCCESS', 'wxrefund_number' => $result['out_trade_no'], 'result' => json_encode($result)]);
//            return false;
//        }

            // 保存信息
            DB::table(Wxrefund::tableName())
                ->wherePk($wxrefund['id'])
                ->update([
                    'time_end' => Carbon::now(),
                    'result' => json_encode($result),
                ]);

            return true;

        } catch (\Exception $ex) {
            Log::error("微信退款失败" . $ex->getMessage());
            $error = '微信退款失败:' . $ex->getMessage();
            return false;
        }
    }

    /**
     * 取消订单时  退还订单中对应的卡券使用
     *
     * 仅为退款使用
     *
     * @param $orderNumber
     * @param array $paymentList
     * @param string $error
     * @return bool
     */
    private static function refundTicketForPayment($orderNumber, array $paymentList, &$error = '')
    {
        if (count($paymentList) <= 0) {
            return true;
        }

        DB::getConnection()->beginTransaction();

        $nowTime = Carbon::now();

        // 支付记录退款
        foreach ($paymentList as $payment) {
            // 支付记录退款
            $rows = DB::table(Payment::tableName())
                ->wherePk($payment['id'])
                ->update([
                    'status' => Payment::STATUS_RETURN,
                    'refund_time' => $nowTime,
                    'updated_at' => $nowTime,
                ]);

            if ($rows != 1) {
                $error = '卡券退还失败';
                Log::warning("取消订单退还卡券中的支付记录失败", [DB::getConnection()->getLastSql()]);
                DB::getConnection()->rollBack();
                return false;
            }
        }

        // 还原卡券
        $rows = DB::table(TicketDetail::tableName())
            ->where('order_number = ?', [$orderNumber])
            ->update([
                'status' => TicketDetail::STATUS_UNUSED,
                'exchange_timestamp' => 0,
                'updated_at' => Carbon::now(),
                'order_number' => '',
            ]);

        if ($rows != count($paymentList)) {
            $error = '卡券退还失败1';
            Log::warning("取消订单退还卡券失败", [DB::getConnection()->getLastSql()]);
            DB::getConnection()->rollBack();
            return false;
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 检测超时未支付的订单，关闭
     *
     * 关闭30分钟内未支付的订单
     *
     * @param int $num 取消操作的个数
     */
    public static function checkPayExpire(&$num = 0)
    {
        $expireTime = Order::PAY_EXPIRE_TIME;

        // 过期时间
        $expireTime = time() - $expireTime;
        $expireTime = date('Y-m-d H:i:s', $expireTime);

        // 在线支付、未支付的订单
        // 新订单、进行中的订单
        // 未发货
        DB::table(Order::tableName())
            ->where('created_at < ?', [$expireTime])
            ->where('pay_type = ? and payment_status = ?', [
                Order::PAY_TYPE_ONLINE, // 在线支付
                Order::PAYMENT_STATUS_NO, // 未支付
            ])
            ->where('(status = ? or status = ?)', [
                Order::STATUS_NEW, // 新订单
                Order::STATUS_ONGOING, // 进行中的订单
            ])
            ->where('delivery_status = ?', [
                Order::DELIVERY_STATUS_NO, // 未发货
            ])
            ->field(['id', 'user_id'])
            ->chunkById(300, function ($list) use (&$num) {

                foreach ($list as $val) {
                    $orderId = $val['id'];
                    $userId = $val['user_id'];

                    if (!OrderService::cancelOrderByOrderIdForExpire($orderId, $userId, $error)) {
                        Log::info("自动检测未支付订单关闭失败", [$error]);
                        continue;
                    }

                    $num++;
                }

            }, 'id');
    }

}