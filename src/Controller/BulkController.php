<?php

namespace AdminBundle\Controller;

use Carbon\Carbon;
use Entity\Bulk;
use Entity\BulkSku;
use Entity\Product;
use Leaf\DB;
use Leaf\Json;
use Leaf\Request;
use Leaf\Session;
use Leaf\Validator;
use Leaf\View;
use Leaf\Redirect;

class BulkController
{
    const BULK_YES = 10;
    const BULK_NO = 20;

    private function bulkList()
    {
        return [
            self::BULK_YES => '开启',
            self::BULK_NO => '关闭',
        ];
    }

    /**
     * 更新
     * @Route admin/bulk/update
     */
    public function update(Request $request)
    {
        $productId = (int)$request->get('productId');

        $product = Product::findByPkOrFail($productId);

        $propertyList = $product->getPropertyList(); // 分类的属性数据
        $skuList = $product->getSkuList();

        if (count($skuList) <= 0) {
            Session::setFlash('message', '请先将商品信息填写完整');
            return Redirect::back();
        }

        // 获取团购商品信息
        $entity = Bulk::where('product_id = ?', [$productId])
            ->where('status != ?', [Bulk::STATUS_DELETE])
            ->findOne();

        if ($entity == null) {
            $entity = new Bulk();
            $entity->loadDefaultValues();
        }

        // 是否为团购
        $bulkList = self::bulkList();

        //视图
        return View::render('@AdminBundle/bulk/update.twig', [
            'productId' => $productId,
            'entity' => $entity,
            'bulkList' => $bulkList,
            'propertyList' => $propertyList,
            'skuList' => $skuList,
        ]);
    }

    /**
     * 保存数据
     * @Route admin/bulk/save
     * @Method post
     */
    public function save(Request $request)
    {
        $productId = (int)$request->get('productId');
        $bulk = (int)$request->get('bulk');
        $data = $request->get('Bulk');
        $bulkSkuList = $request->get('Sku', []);

        // 检测商品是否存在
        $product = Product::findByPkOrFail($productId);

        // 检测是否是团购商品
        $bulkList = self::bulkList();

        if (!array_key_exists($bulk, $bulkList)) {
            return Json::renderWithFalse("请正确选择是否为团购");
        }

        // 当为关闭团购时，删除团购数据
        // 团购开启的时候 保存数据

        if ($bulk == self::BULK_NO) {

            if (!self::saveBulkNo($productId, $error)) {
                return Json::renderWithFalse($error);
            }

        } else {

            // 开启的时候，验证，数据
            if (count($bulkSkuList) <= 0) {
                return Json::renderWithFalse("请正确填写团购价信息");
            }

            // 团购开启的时候 保存数据
            if (!self::saveBulkYes($productId, $data, $product, $bulkSkuList, $error)) {
                return Json::renderWithFalse($error);
            }
        }

        return Json::renderWithTrue("保存成功");
    }

    /**
     * 团购开启的保存信息
     * @param $productId
     * @param $data
     * @param Product $product
     * @param $bulkSkuList
     * @param string $error
     * @return bool
     */
    private function saveBulkYes($productId, $data, Product $product, $bulkSkuList, &$error = '')
    {
        DB::getConnection()->beginTransaction();

        // 验证团购商品数据
        $rule = [
            [['person', 'valid_start', 'valid_end',], 'trim'],
            [['person', 'valid_start', 'valid_end',], 'required'],
            ['person', 'integer'],
            ['person', 'compare', 'operator' => '>=', 'type' => 'number', 'compareValue' => 2, 'message' => '人数必须大于等于2'],
            [['valid_start', 'valid_end',], 'date', 'format' => 'Y-m-d'],
        ];

        if (!Validator::validate($data, $rule, Bulk::labels())) {
            DB::getConnection()->rollBack();
            $error = Validator::getFirstError();
            return false;
        }

        // 检测团购商品是否存在
        $one = Bulk::where('product_id = ?', [$productId])
            ->lockForUpdate()
            ->findOne();

        // 不存在的时候，新增一条
        // 存在的时候，更新
        if ($one == null) {
            $data['product_id'] = $productId;
            $data['limit_quantity'] = 1; // 暂不管理，默认为1，每人单次团购限购数量
            $data['status'] = Bulk::STATUS_NORMAL;
            $data['created_at'] = $data['updated_at'] = Carbon::now();

            $bulkId = Bulk::insertGetId($data);
        } else {
            $bulkId = $one['id'];

            $data['updated_at'] = Carbon::now();

            $row = Bulk::wherePk($bulkId)
                ->update($data);

            if ($row != 1) {
                DB::getConnection()->rollBack();
                $error = '保存失败';
                return false;
            }
        }

        // 保存 团购商品的库存信息

        $skuList = $product->getSkuList(); // 商品库存信息

        // 检测团购商品库存的团购价信息
        if (count($bulkSkuList) <= 0) {
            DB::getConnection()->rollBack();
            $error = '请正确填写团购价信息';
            return false;
        }

        // 验证规则
        $bulkSkuRule = [
            [['price',], 'trim'],
            [['price',], 'required'],
            [['price',], 'double'],
        ];

        foreach ($skuList as $sku) {
            $skuId = $sku['id'];

            if (!array_key_exists($skuId, $bulkSkuList)) {
                DB::getConnection()->rollBack();
                $error = '请完整填写属性中的团购价';
                return false;
            }

            $tempBulkSkuData = $bulkSkuList[$skuId];

            // 验证
            if (!Validator::validate($tempBulkSkuData, $bulkSkuRule, BulkSku::labels())) {
                DB::getConnection()->rollBack();
                $error = Validator::getFirstError();
                return false;
            }

            // 判断价格必须大于0
            if ($tempBulkSkuData['price'] <= 0) {
                DB::getConnection()->rollBack();
                $error = '团购价必须大于0';
                return false;
            }

            //检测团购商品库存信息
            $bulkSkuOne = BulkSku::where('product_id = ? and sku_id = ?', [$productId, $skuId])
                ->where('status != ?', [BulkSku::STATUS_DELETE])
                ->lockForUpdate()
                ->findOne();

            // 当团购商品库存不存在的时候，新增
            // 否则，更新
            if ($bulkSkuOne == null) {
                $tempBulkSkuData['product_id'] = $productId;
                $tempBulkSkuData['sku_id'] = $skuId;
                $tempBulkSkuData['bulk_id'] = $bulkId;
                $tempBulkSkuData['status'] = BulkSku::STATUS_NORMAL;
                $tempBulkSkuData['created_at'] = $tempBulkSkuData['updated_at'] = Carbon::now();

                BulkSku::insert($tempBulkSkuData);
            } else {
                $tempBulkSkuData['updated_at'] = Carbon::now();

                if (BulkSku::wherePk($bulkSkuOne['id'])->update($tempBulkSkuData) != 1) {
                    $error = '团购价保存失败';
                    DB::getConnection()->rollBack();
                    return false;
                }
            }
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 关闭团购
     * @param $productId
     * @param string $error
     * @return bool
     */
    private function saveBulkNo($productId, &$error = '')
    {
        DB::getConnection()->beginTransaction();

        // 删除团购数据
        Bulk::where('product_id = ?', [$productId])
            ->update(['status' => Bulk::STATUS_DELETE]);

        // 删除团购的库存数据
        BulkSku::where('product_id = ?', [$productId])
            ->update(['status' => BulkSku::STATUS_DELETE]);

        DB::getConnection()->commit();

        return true;
    }

}
