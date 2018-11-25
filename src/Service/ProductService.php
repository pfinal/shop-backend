<?php

namespace AdminBundle\Service;

use Carbon\Carbon;
use Entity\Brand;
use Entity\Category;
use Entity\Express;
use Entity\Image;
use Entity\MixProduct;
use Entity\ProductContent;
use Entity\Sku;
use Leaf\Cache;
use Leaf\DB;
use Leaf\Pagination;
use Leaf\Util;
use Leaf\Validator;

use Entity\Product;

/**
 * 商品
 * @author  leafphp curd generator
 * @since   1.0
 */
class ProductService
{
    /**
     * 排序分页查询
     *
     * @param Pagination|null $page 分页对象
     * @param string $condition 查询条件
     * @param array $params
     * @param string $order
     * @return Product[]
     */
    public static function findList(Pagination $page = null, $condition = '', $params = [], $order = '-id')
    {
        $query = DB::table(Product::tableName())
            ->asEntity(Product::className())
            ->where($condition, $params)
            ->where('status<>?', [Product::STATUS_DELETE])
            ->orderBy($order);

        if (is_null($page)) {
            return $query->findAll();
        }

        $queryCount = clone $query;
        $page->itemCount = $queryCount->count();

        return $query->limit($page->limit)->findAll();
    }

    /**
     * 根据主键查询单条
     * @param $id
     * @return Product|null
     */
    public static function findOne($id, $useCache = false)
    {
        $cacheKey = 'product:id:' . $id;

        if ($useCache) {
            $one = Cache::get($cacheKey, false);

            if ($one === false) {
                $one = DB::table(Product::tableName())
                    ->asEntity(Product::className())
                    ->findByPk($id);

                Cache::set($cacheKey, $one, 60); // 缓存60秒
            }
        } else {
            $one = DB::table(Product::tableName())
                ->asEntity(Product::className())
                ->findByPk($id);
        }

        return $one;
    }

    /**
     * 新增
     * @param array $data
     * @param array $mixIds 分组相关信息
     * @param string $error
     * @return string 成功返回自增id，失败返回0
     */
    public static function create($data, $mixIds, &$error = '')
    {
        if (!isset($data['category_id'])) {
            $error = '没有分类,请先添加分类';
            return 0;
        }
        if (!isset($data['brand_id'])) {
            $error = '没有品牌,请先添加品牌';
            return 0;
        }

        //分类 20181010 -- 当分类存在的时候，检测分类数据
        if ($data['category_id']) {
            $category = DB::table(Category::tableName())
                ->where('status!=?', Category::STATUS_DELETE)
                ->findByPk($data['category_id']);
            if ($category == null) {
                $error = '分类id错误';
                return 0;
            }
        }

        if (!isset($data['sku'])) {
            $error = '商品分类数据有误';
            return 0;
        }

        $skuInfo = $data['sku'];
        unset($data['sku']);

        //验证
        $rule = [
            [['category_id', 'brand_id', 'name', 'status', 'sort', 'online_at', 'offline_at', 'express_id', 'basic_sale_num', 'promotion', 'parameter', 'buy_num', 'code',], 'trim'],
            [['category_id', 'brand_id', 'name', 'status', 'sort', 'online_at', 'offline_at', 'express_id', 'basic_sale_num',], 'required'],
            [['sell_point',], 'string', 'length' => [0, 255]],
            [['price', 'remark', 'name_short',], 'safe'],
            [['name',], 'string', 'length' => [1, 255]],
            [['category_id', 'brand_id', 'sort', 'status', 'express_id', 'basic_sale_num',], 'integer'],
            [['online_at', 'offline_at',], 'datetime'],
            [['promotion', 'parameter',], 'string', 'length' => [0, 255]],
            [['buy_num',], 'integer'],
            [['code',], 'string', 'length' => [0, 50]],
            [['content',], 'safe'],
        ];
        if (!Validator::validate($data, $rule, Product::labels())) {
            $error = Validator::getFirstError();
            return 0;
        }

        if ($data['brand_id']) {
            $brand = BrandService::findOne($data['brand_id']);
            if ($brand == null) {
                $error = '品牌不存在';
                return false;
            }
        }

        // 当code有值的时候，判断唯一
        if ($data['code']) {
            $count = DB::table(Product::tableName())
                ->where('code = ?', [$data['code']])
                ->where('status != ?', [Product::STATUS_DELETE])
                ->count();

            if ($count > 0) {
                $error = '货号重复';
                return false;
            }
        }

        // 验证模板
        $express = DB::table(Express::tableName())
            ->where('status = ?', [Express::STATUS_NORMAL])
            ->findByPk($data['express_id']);

        if ($express == null) {
            $error = '运费模板不存在';
            return false;
        }

        $data['created_at'] = $data['updated_at'] = date('Y-m-d H:i:s');

        DB::getConnection()->beginTransaction();

        $id = DB::table(Product::tableName())->insertGetId($data);

        // 判断分类属性

        //没有属性的商品，直接插入一条到SKU中
        if ($category['property'] == '') {

            // 验证库存数据
            $skuRule = [
                [['quantity', 'original_price', 'price',], 'trim'],
                [['quantity',], 'required'],
                [['quantity',], 'integer'],
                [['original_price', 'price',], 'double'],
            ];

            $skuLabels = [
                'quantity' => '数量',
                'original_price' => '原价',
                'price' => '价格',
            ];

            if (!Validator::validate($skuInfo, $skuRule, $skuLabels)) {
                $error = Validator::getFirstError();
                DB::getConnection()->rollBack();
                return false;
            }

            $skuData = [
                'product_id' => $id,
                'color' => '',
                'size' => '',
                'version' => '',
                'quantity' => $skuInfo['quantity'],
                'original_price' => (float)$skuInfo['original_price'],
                'price' => (float)$skuInfo['price'],
                'status' => Sku::STATUS_DISPLAY,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];

            DB::table(Sku::tableName())
                ->insert($skuData);

            // 商品展示的价格
            DB::table(Product::tableName())
                ->wherePk($id)
                ->update(['price' => $skuData['price']]);
        }

        // 处理分组数据
        if (!self::handleMix($id, $mixIds, $error)) {
            DB::getConnection()->rollBack();
            return false;
        }

        DB::getConnection()->commit();
        return $id;
    }

    /**
     * 根据主键修改单条
     * @param $id
     * @param array $data
     * @param array $mixIds
     * @param string $error
     * @return bool
     */
    public static function update($id, $data, $mixIds, &$error = '')
    {

        if (!isset($data['category_id'])) {
            $error = '没有分类,请先添加分类';
            return 0;
        }

        if (!isset($data['brand_id'])) {
            $error = '没有品牌,请先添加品牌';
            return 0;
        }

        DB::getConnection()->beginTransaction();

        $product = DB::table(Product::tableName())
            ->asEntity(Product::className())
            ->lockForUpdate()
            ->wherePk($id)
            ->findOne();

        if ($product == null) {
            $error = '商品数据不存在';
            DB::getConnection()->rollBack();
            return false;
        }

        // 处理分组信息
        if (!self::handleMix($id, $mixIds, $error)) {
            DB::getConnection()->rollBack();
            return false;
        }

        //将sku中最低价格，存入product表中
        $minPrice = false;

        // 20181025 根据分类属性
        $productPropertyList = $product->getPropertyList();
        if (count($productPropertyList) <= 0) {
            if (!isset($data['sku'])) {
                $error = '参数有误(价格、数量)';
                DB::getConnection()->rollBack();
                return false;
            }

            //此商品没有属性，只有一个SKU的情况
            $row = DB::table(Sku::tableName())
                ->where('product_id=? and color="" and size="" and version=""', $id)
                ->update([
                    'original_price' => $data['sku']['original_price'],
                    'price' => $data['sku']['price'],
                    'quantity' => $data['sku']['quantity'],
                    'updated_at' => Carbon::now(),
                    'status' => Sku::STATUS_DISPLAY,
                ]);

            if ($row != 1) {
                $error = '保存库存和单价失败';
                DB::getConnection()->rollBack();
                return 0;
            }

            $minPrice = $data['sku']['price'];
            unset($data['sku']);
        } else {
            if (isset($data['sku'])) {
                unset($data['sku']);
            }
        }

        if (isset($data['property'])) {
            $propertyList = $data['property'];

            if (!self::saveSku($id, $propertyList, $minPrice, $error)) {
                DB::getConnection()->rollBack();
                return false;
            }

            unset($data['property']);
        }

        $minPrice = (float)$minPrice;

        //验证
        $rule = [
            [['category_id', 'brand_id', 'name', 'status', 'sort', 'online_at', 'offline_at', 'express_id', 'basic_sale_num', 'promotion', 'parameter', 'buy_num', 'code',], 'trim'],
            [['category_id', 'brand_id', 'name', 'status', 'sort', 'online_at', 'offline_at', 'express_id', 'basic_sale_num',], 'required'],
            [['sell_point',], 'string', 'length' => [0, 255]],
            [['price', 'remark', 'name_short',], 'safe'],
            [['name',], 'string', 'length' => [1, 255]],
            [['category_id', 'brand_id', 'sort', 'status', 'express_id', 'basic_sale_num',], 'integer'],
            [['online_at', 'offline_at',], 'datetime'],
            [['promotion', 'parameter',], 'string', 'length' => [0, 255]],
            [['buy_num',], 'integer'],
            [['code',], 'string', 'length' => [0, 50]],
            [['content',], 'safe'],
        ];

        // 20181025 检测新传入的分类和商品本身的分类的属性是否一致
        if ($data['category_id'] !== $product['category_id']) {
            $tempCategory = CategoryService::findOne($data['category_id']);
            if ($tempCategory == null) {
                $error = '分类数据不存在';
                DB::getConnection()->rollBack();
                return false;
            }

            if ($tempCategory['status'] == Category::STATUS_DELETE) {
                $error = '分类数据已被删除';
                DB::getConnection()->rollBack();
                return false;
            }

            // 检测属性是否一致
            $tempPropertyList = $tempCategory['property'];
            $tempPropertyList = trim($tempPropertyList);

            // 商品的分类
            $productCategory = DB::table(Category::tableName())
                ->findByPkOrFail($product['category_id']);

            $productCategoryProperty = $productCategory['property'];
            $productCategoryProperty = trim($productCategoryProperty);

            if (!$productCategoryProperty) {
                if ($tempPropertyList) {
                    $error = '编辑商品时的分类属性必须与原商品分类属性不同';
                    DB::getConnection()->rollBack();
                    return false;
                }
            } else {
                $tempPropertyList = json_decode($tempPropertyList, true);
                $productCategoryProperty = json_decode($productCategoryProperty, true);

                $tempArr1 = array_diff($tempPropertyList, $productCategoryProperty);
                $tempArr2 = array_diff($productCategoryProperty, $tempPropertyList);

                if ((count($tempArr1) != 0) || (count($tempArr2) != 0)) {
                    $error = '编辑商品时的分类属性必须与原商品分类属性不同';
                    DB::getConnection()->rollBack();
                    return false;
                }
            }
        }

        //取最低价存入商品表
        $data['price'] = $minPrice;
        if (!Validator::validate($data, $rule, Product::labels())) {
            $error = Validator::getFirstError();
            DB::getConnection()->rollBack();
            return false;
        }

        // 当code有值的时候，判断唯一
        if ($data['code']) {
            $count = DB::table(Product::tableName())
                ->where('code = ?', [$data['code']])
                ->where('id != ? and status != ?', [$id, Product::STATUS_DELETE])
                ->count();

            if ($count > 0) {
                $error = '货号重复';
                DB::getConnection()->rollBack();
                return false;
            }
        }

        // 验证模板
        $express = DB::table(Express::tableName())
            ->where('status = ?', [Express::STATUS_NORMAL])
            ->findByPk($data['express_id']);

        if ($express == null) {
            $error = '运费模板不存在';
            DB::getConnection()->rollBack();
            return false;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');

        //更新
        if (!(DB::table(Product::tableName())->where('id = ?', [$id])->update($data))) {
            DB::getConnection()->rollBack();
            $error = '商品更新失败';
            return false;
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 处理商品的分组信息
     * @param $productId
     * @param $mixIds
     * @param string $error
     * @return bool
     */
    private static function handleMix($productId, $mixIds, &$error = '')
    {
        $prevData = DB::table(MixProduct::tableName())
            ->where('product_id = ?', [$productId])
            ->field(['id', 'mix_id', 'sort'])
            ->findAll();

        $prevIds = Util::arrayColumn($prevData, 'id');

        if (count($prevIds) > 0) {
            // 删除分组信息
            DB::table(MixProduct::tableName())
                ->whereIn('id', $prevIds)
                ->delete();
        }

        $prevData = Util::arrayColumn($prevData, null, 'mix_id');

        if ($mixIds) {
            foreach ($mixIds as $mixId) {
                $sort = 10; // 默认排序为10

                // 如果原来就存在，则保持原来的排序值不变
                if (array_key_exists($mixId, $prevData)) {
                    $sort = $prevData[$mixId]['sort'];
                }


                $data = [
                    'mix_id' => $mixId,
                    'product_id' => $productId,
                    'sort' => $sort,
                ];

                $data['created_at'] = $data['updated_at'] = Carbon::now();

                DB::table(MixProduct::tableName())->insert($data);
            }
        }

        return true;
    }

    /**
     * 保存sku相关数据
     * @param $productId
     * @param $propertyList
     * @param $minPrice
     * @param string $error
     * @return bool
     */
    private static function saveSku($productId, $propertyList, &$minPrice, &$error = '')
    {
        DB::getConnection()->beginTransaction();

        $skuList = DB::table(Sku::tableName())->where('product_id=?', [$productId])->where('status=?', [Sku::STATUS_DISPLAY])->findAll();

        //以color、size、version为key
        $skuListTemp = [];
        foreach ($skuList as $item) {
            //加一个前缀 "_", 避免三个属性都没有的情况，key是个字符串
            $skuListTemp['_' . $item['color'] . $item['size'] . $item['version']] = $item;
        }

        // 验证规则
        $rule = [
            [['name', 'value', 'sku', 'original_price', 'price', 'code',], 'trim'],
            [['name', 'value', 'sku', 'original_price', 'price',], 'required'],
            [['name', 'value',], 'string', 'length' => [1, 255]],
            [['sku',], 'integer'],
            [['original_price', 'price',], 'double'],
            [['code',], 'string', 'length' => [0, 255]],
        ];

        $labels = [
            'name' => '属性名称',
            'value' => '属性值',
            'sku' => ' 库存',
            'original_price' => '原价',
            'price' => '现价',
            'code' => '条形码',
        ];

        foreach ($propertyList as $key => $value) {

            // 验证数据
            if (!Validator::validate($value, $rule, $labels)) {
                $error = Validator::getFirstError();
                DB::getConnection()->rollBack();
                return false;
            }

            $arr = [];
            $arr['color'] = '';
            $arr['size'] = '';
            $arr['version'] = '';
            $arr['product_id'] = $productId;
            $arr['quantity'] = $value['sku'];
            $arr['code'] = $value['code'];
            $arr['original_price'] = $value['original_price']; // 20180717 新加的原价
            $arr['price'] = $value['price'];
            $arr['created_at'] = $arr['updated_at'] = Carbon::now();

            if ($arr['price'] < 0 || $arr['quantity'] < 0) {
                $error = '数量、单价不能为空';
                DB::getConnection()->rollBack();
                return false;
            }

            //最便宜的一个SKU
            if ($minPrice === false) {
                $minPrice = $arr['price'];
            } else {
                $minPrice = min($minPrice, $arr['price']);
            }

            //$fieldNames = ['color', 'size', 'version']
            $fieldNames = json_decode($value['name']);

            //$fieldValues = ['白', 'M', '男版']
            $fieldValues = json_decode($value['value']);

            foreach ($fieldNames as $ind => $val) {

                if (empty($fieldNames[$ind]) || empty($fieldValues[$ind])) {
                    $error = '属性不能为空';
                    DB::getConnection()->rollBack();
                    return false;
                }

                $arr[$fieldNames[$ind]] = $fieldValues[$ind];
            }

            $tempKey = '_' . $arr['color'] . $arr['size'] . $arr['version'];

            //如果这个属性组合存在，则更新，否则，新增
            if (array_key_exists($tempKey, $skuListTemp)) {
                $tempSkuId = $skuListTemp[$tempKey]['id'];

                // code有值的时候，必须是唯一的
                if ($arr['code']) {
                    $count = DB::table(Sku::tableName())
                        ->where('code = ?', [$arr['code']])
                        ->where('id != ?', [$tempSkuId])
                        ->where('status = ?', [Sku::STATUS_DISPLAY])// 20181105 新加在有效的库存中唯一
                        ->count();

                    if ($count > 0) {
                        $error = '库存条形码不允许重复';
                        DB::getConnection()->rollBack();
                        return false;
                    }
                }

                $res = DB::table(Sku::tableName())->wherePk($tempSkuId)->update($arr);

                //操作完成后，$skuListTemp中剩下的，需要删除
                unset($skuListTemp[$tempKey]);

            } else {
                // code有值的时候，必须是唯一的
                if ($arr['code']) {
                    $count = DB::table(Sku::tableName())
                        ->where('code = ?', [$arr['code']])
                        ->where('status = ?', [Sku::STATUS_DISPLAY])// 20181105 新加在有效的库存中唯一
                        ->count();

                    if ($count > 0) {
                        $error = '库存条形码不允许重复';
                        DB::getConnection()->rollBack();
                        return false;
                    }
                }

                $res = DB::table(Sku::tableName())->insertGetId($arr);
            }

            if ($res < 1) {
                $error = '库存保存失败';
                DB::getConnection()->rollBack();
                return false;
            }
        }

        //$skuListTemp中剩下的数据，表示已不存在的
        foreach ($skuListTemp as $item) {
            DB::table(Sku::tableName())
                ->where('id=?', [$item['id']])
                ->update(['status' => Sku::STATUS_DELETE, 'updated_at' => Carbon::now()]);
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 删除
     * @param $id
     * @return bool
     */
    public static function delete($id, &$error = '')
    {
        // 20181105 修改
        DB::getConnection()->beginTransaction();

        $product = DB::table(Product::tableName())
            ->lockForUpdate()
            ->findByPk($id);

        if ($product == null) {
            $error = '操作异常';
            DB::getConnection()->rollBack();
            return false;
        }

        if ($product['status'] == Product::STATUS_DELETE) {
            $error = '商品已被删除';
            DB::getConnection()->rollBack();
            return false;
        }

        $row = DB::table(Product::tableName())
            ->where('id = ?', [$id])
            ->update(['status' => Product::STATUS_DELETE]);

        if ($row != 1) {
            $error = '商品删除失败';
            DB::getConnection()->rollBack();
            return false;
        }

        // 删除商品关联的库存信息
        $rows = DB::table(Sku::tableName())
            ->where('product_id = ?', [$id])
            ->update(['status' => Sku::STATUS_FOR_PRODUCT_DELETE, 'updated_at' => Carbon::now()]);

        if (!$rows) {
            $error = '商品库存删除失败';
            DB::getConnection()->rollBack();
            return false;
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 保存图片
     * @param $productId
     * @param string $file
     * @param int $type 图片类型 Image::TYPE_XXX
     * @return bool
     */
    public static function saveImage($productId, $file, $type)
    {
        if (empty($file)) {
            return false;
        }

        //如果是主图，删除其它主图
        if ($type == Image::TYPE_COVER) {
            DB::table(Image::tableName())
                ->where('product_id=? and type=?', [$productId, Image::TYPE_COVER])
                ->delete();
        }

        $data['file'] = $file;
        $data['product_id'] = $productId;
        $data['type'] = $type;
        $data['created_at'] = $data['updated_at'] = Carbon::now();

        return DB::table(Image::tableName())->insert($data);
    }

    /**
     * 保存图文详情
     * @param $productId
     * @param string $file
     * @return bool
     */
    public static function saveProductContent($productId, $file)
    {
        if (empty($file)) {
            return false;
        }

        $data = [
            'product_id' => $productId,
            'file' => $file,
        ];

        $data['created_at'] = $data['updated_at'] = Carbon::now();

        DB::table(ProductContent::tableName())->insert($data);

        return true;
    }

    /**
     * 为返回的商品处理信息
     *
     * 仅为前端接口返回商品数据处理
     *
     * 20181031 当商品只有一个属性的时候，不限制库存；当商品有多属性的时候，排除掉没有数量的库存，当都没有库存的时候，保留第一个库存信息
     *
     * @param $productList
     * @return array
     */
    public static function handleProductReturnList($productList)
    {
        //查询商品的封面图片信息
        $list = [];

        foreach ($productList as $key => $product) {

            /** @var Product $product */

            // 20181031 检测库存数量
            $tempSkuCount = DB::table(Sku::tableName())
                ->where('status = ?', [Sku::STATUS_DISPLAY])
                ->where('product_id=?', [$product['id']])
                ->count();

            if ($tempSkuCount <= 0) {
                continue;
            }

            // 检测商品是单属性还是多属性
            if ($tempSkuCount == 1) {
                // 单属性，直接查询库存
                $sku = DB::table(Sku::tableName())
                    ->asEntity(Sku::className())
                    ->where('status = ?', [Sku::STATUS_DISPLAY])
                    ->where('product_id=?', [$product['id']])
                    ->findOne();
            } else {
                // 多属性查找一条，有数量的库存
                $sku = DB::table(Sku::tableName())
                    ->asEntity(Sku::className())
                    ->where('status = ?', [Sku::STATUS_DISPLAY])
                    ->where('product_id=?', [$product['id']])
                    ->where('quantity > 0')
                    ->orderBy('id')
                    ->findOne();

                // 当没有有数量的库存的时候，返回第一条库存信息
                if ($sku == null) {
                    $sku = DB::table(Sku::tableName())
                        ->asEntity(Sku::className())
                        ->where('status = ?', [Sku::STATUS_DISPLAY])
                        ->where('product_id=?', [$product['id']])
                        ->orderBy('id')
                        ->findOne();
                }
            }

//            //库存
//            $sku = DB::table(Sku::tableName())
//                ->asEntity(Sku::className())
//                ->where('status = ?', [Sku::STATUS_DISPLAY])
//                ->where('product_id=?', [$product['id']])
//                ->orderBy('id')
//                ->findOne();

            if ($sku == null) {
                continue;
            }

            // 库存数量
            $quantity = 0;

            $quantityList = DB::table(Sku::tableName())
                ->where('status = ?', [Sku::STATUS_DISPLAY])
                ->where('product_id=?', [$product['id']])
                ->field(['quantity'])
                ->findAll();

            foreach ($quantityList as $quantityItem) {
                $quantity = (int)$quantity + (int)$quantityItem['quantity'];
            }

            //组装数据
            $list[$key] = [
                'product' => $product,
                'skuInfo' => $sku,
                'skuId' => $sku['id'],
                'name' => $product['name'],
                'original_price' => $sku['original_price'],
                'price' => $sku['price'],
//                'sale_num' => $product['sale_num'],
                'sale_num' => $product['basic_sale_num'] + $product['sale_num'], // 返回出去的是 基础销量 + 实际销量
                'image' => $product->getCoverImageUrl(),
                'bulk_sku_info' => $sku->bulkSkuInfo(), // 团购商品对应的库存信息
                'tag_info' => $product->tagInfo(), // 标签 信息
                'sell_point' => $product['sell_point'], // 卖点

                'quantity' => $quantity, // 剩余数量
            ];

            // 将秒杀信息放到数组中
            $sku = $list[$key]['skuInfo'];
            $product = $list[$key]['product'];

            $timelimit = null;
            $timelimitSkuInfo = null;

            if ($sku) {
                $timelimitSkuInfo = $sku->effectTimelimit();
            }

            if ($product) {
                $timelimit = $product->effectTimelimit;
            }

            $list[$key]['timelimit'] = $timelimit;
            $list[$key]['timelimit_sku'] = $timelimitSkuInfo;

        }

        $list = array_values($list);

        return $list;
    }

    /**
     * 根据库存ID获取库存和商品信息
     * @param $skuId
     * @param string $error
     * @param string $errorCode
     * @return array|bool
     */
    public static function productAndSkuBySkuId($skuId, &$error = '', &$errorCode = '')
    {
        $sku = DB::table(Sku::tableName())
            ->asEntity(Sku::className())
            ->where('status = ?', [Sku::STATUS_DISPLAY])
            ->findByPk($skuId);
        if ($sku == null) {
            $error = '数据信息不存在';
            $errorCode = 'no sku';
            return false;
        }

        /** @var Product $product */
        $product = DB::table(Product::tableName())
            ->asEntity(Product::className())
            ->where('status=?', [Product::STATUS_DISPLAY])
            ->findByPk($sku['product_id']);

        if ($product == null) {
            $error = '商品信息不存在';
            $errorCode = 'no product';
            return false;
        }

        return [
            'sku' => $sku,
            'product' => $product,
        ];
    }

}