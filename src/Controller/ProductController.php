<?php

namespace AdminBundle\Controller;

use AdminBundle\Service\ProductService;
use Carbon\Carbon;
use Entity\Brand;
use Entity\Category;
use Entity\Image;
use Entity\Mix;
use Entity\Product;
use Entity\ProductContent;
use Entity\ProductRegion;
use Entity\Sku;
use Entity\Tag;
use Entity\TagProduct;
use Leaf\DB;
use Leaf\Exception\HttpException;
use Leaf\Application;
use Leaf\Json;
use Leaf\Pagination;
use Leaf\Request;
use Leaf\Session;
use Leaf\Util;
use Leaf\View;
use Leaf\Redirect;
use Service\ExpressService;
use Service\RegionService;
use Service\UploadTrait;

class ProductController
{
    use  UploadTrait;

    /**
     * 列表
     * @Route admin/product
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Search');

        if (!empty($search['name'])) {
            $condition[] = 'name like ?';
            $params[] = '%' . trim($search['name']) . '%';
        }

        if (!empty($search['status'])) {
            $condition[] = 'status = ?';
            $params[] = $search['status'];
        }

        if (!empty($search['brand_id'])) {
            $condition[] = 'brand_id = ?';
            $params[] = $search['brand_id'];
        }

        if (!empty($search['category_id'])) {
            $condition[] = 'category_id = ?';
            $params[] = $search['category_id'];
        }

        $condition = implode(' and ', $condition);

        //分页
        $page = new Pagination();

        //查询数据
        $list = ProductService::findList($page, $condition, $params, $request->get('sort', 'sort,online_at desc'));

        // 状态
        $statusList = Product::getStatus();

        // 分类
        $categoryList = DB::table(Category::tableName())
            ->asEntity(Category::className())
            ->where('status != ?', [Category::STATUS_DELETE])
            ->field(['id', 'name', 'path'])
            ->findAll();

        $categoryList = self::handleCategory($categoryList);
        $categoryList = Util::arrayColumn($categoryList, 'name', 'id');

        // 品牌
        $brandList = DB::table(Brand::tableName())
            ->where('status != ?', [Brand::STATUS_DELETE])
            ->field(['id', 'name'])
            ->findAll();
        $brandList = Util::arrayColumn($brandList, 'name', 'id');

//        // 运费
//        $expressList = DB::table(Express::tableName())
//            ->where('status != ?', [Express::STATUS_DELETE])
//            ->field(['id', 'name'])
//            ->findAll();
//        $expressList = Util::arrayColumn($expressList, 'name', 'id');

        //视图
        return View::render('@AdminBundle/product/index.twig', [
            'list' => $list,
            'page' => $page,

            // 搜索条件
            'statusList' => $statusList,
            'categoryList' => $categoryList,
            'brandList' => $brandList,
//            'expressList' => $expressList,
        ]);
    }

    /**
     * 新增
     * @Route admin/product/create
     */
    public function create(Request $request)
    {
        $entity = new Product();
        $entity->loadDefaultValues();

        //把entity里上架时间改成当前时间
        $entity->online_at = date('Y-m-d H:i:s', time());
        $entity->offline_at = Carbon::now()->addYear(10)->format('Y-m-d 00:00:00');

        //查询有哪些品牌
        $brandList = DB::table(Brand::tableName())->findAll();

        //组装数组 成 品牌id对应品牌名称
        $brandList = array_column($brandList, null, 'id');

        foreach ($brandList as $key => $value) {
            $brandList[$key] = $value['name'];
        }

        //查询有哪些分类
        $categoryList = DB::table(Category::tableName())
            ->where('status = ?', [Category::STATUS_DISPLAY])
            ->asEntity(Category::className())
            ->findAll();

        $categoryList1 = self::handleCategory($categoryList);

        //获得由哪些状态
        $status = Product::getStatus();

        //保存
        $error = '';
        if ($request->isMethod('post')) {
            $productId = self::actionCreate($request, $error);
            if ($productId) {
                Session::setFlash('message', '添加成功');
                return Redirect::to('admin/product/update?id=' . $productId);
            }
        }

        // 分组
        $mixList = DB::table(Mix::tableName())
            ->where('status = ?', [Mix::STATUS_NORMAL])
            ->field(['id', 'name'])
            ->findAll();
        $mixList = Util::arrayColumn($mixList, 'name', 'id');

        $expressList = ExpressService::findNormalList();
        $expressList = Util::arrayColumn($expressList, 'name', 'id');

        $contentShow = 1;

        if (isset(Application::$app['params']['admin.product.content']) && (Application::$app['params']['admin.product.content'] === false)) {
            $contentShow = 0;
        }

        //视图
        return View::render('@AdminBundle/product/create.twig', [
            'entity' => $entity,
            'error' => $error,
            'brandList' => $brandList,
            'categoryList' => $categoryList1,
            'status' => $status,
            'mixList' => $mixList,
            'expressList' => $expressList,

            'contentShow' => $contentShow,
        ]);
    }

    /**
     * 处理分类数据
     * @param $categoryList
     * @return array
     */
    private function handleCategory($categoryList)
    {
        $categoryList1 = [];
        foreach ($categoryList as $k => $val) {
            $categoryList1[$val->path . $val->id . ','] = $val;
            $count = count(explode(',', $val->path)) - 2;
            $categoryList1[$val->path . $val->id . ',']['name'] = str_repeat("　", $count) . $categoryList1[$val->path . $val->id . ',']['name'];
        }
        ksort($categoryList1);

        return $categoryList1;
    }

    /**
     * 执行 新增 商品
     * @param Request $request
     * @param string $error
     * @return bool|int  失败返回false  成功返回商品ID
     */
    private function actionCreate(Request $request, &$error = '')
    {
        $mixIds = $request->get('MixIds');

        DB::getConnection()->beginTransaction();

        $productId = ProductService::create($request->get('Product'), $mixIds, $error);

        if (!$productId) {
            DB::getConnection()->rollBack();
            return false;
        }

        //保存细节图片
        $fileKeys = $request->get('fileKeys');
        if (!empty($fileKeys)) {
            foreach ($fileKeys as $fileKey) {
                $file = self::moveFromTemp($fileKey);
                ProductService::saveImage($productId, $file, Image::TYPE_DETAIL);
            }
        }

        //保存主图
        $file = self::moveFromTemp($request->get('coverFileKey'));
        ProductService::saveImage($productId, $file, Image::TYPE_COVER);

        // 保存图文详情
        $contentImgFileKeys = $request->get('contentImgFileKeys', []);
        if ($contentImgFileKeys) {
            foreach ($contentImgFileKeys as $item) {
                $file = self::moveFromTemp($item);
                if (!ProductService::saveProductContent($productId, $file)) {
                    $error = '图文详情保存失败';
                    DB::getConnection()->rollBack();
                    return false;
                }
            }
        }

        DB::getConnection()->commit();
        return $productId;
    }

    /**
     * 更新
     * @Route admin/product/update
     */
    public function update(Request $request, Application $app)
    {
        $id = (int)$request->get('id');

        //查询
        if (($entity = ProductService::findOne($id)) === null) {
            throw new HttpException(500, '操作需要的数据不存在');
        }

        if ($entity['status'] == Product::STATUS_DELETE) {
            throw new HttpException(500, '操作需要的数据已被删除');
        }

        //主图
        $coverImage = DB::table(Image::tableName())
            ->asEntity(Image::className())
            ->where('product_id=? and type=?', [$id, Image::TYPE_COVER])
            ->findOne();

        //细节图
        $imageList = $entity->detailImageList();

        // 图文详情
        $contentList = $entity->contentList();

        //查询有哪些品牌
        $brandList = DB::table(Brand::tableName())->findAll();
        //组装数组 成 品牌id对应品牌名称
        $brandList = array_column($brandList, null, 'id');
        foreach ($brandList as $key => $value) {
            $brandList[$key] = $value['name'];
        }

        //获得由哪些状态
        $status = Product::getStatus();

        //保存
        $error = '';
        if ($request->isMethod('post')) {
            if (self::actionUpdate($id, $entity, $imageList, $contentList, $request, $error)) {
                Session::setFlash('message', '修改成功');
                return Redirect::to('admin/product');
            }
        }

        //查看其可以有属性
        $product = DB::table(Product::tableName())->asEntity(Product::className())->findByPk($id);

        $propertyList = $product->getPropertyList();

        //查询实际有的属性
        $skuList = $product->getSkuList();

        $arr = [];
        foreach ($skuList as $key => $sku) {
            if ($key == 0) {
                $arr['color'][] = $sku['color'];
                $arr['size'][] = $sku['size'];
                $arr['version'][] = $sku['version'];
                continue;
            }
            if (!in_array($sku['color'], $arr['color'])) {
                $arr['color'][] = $sku['color'];
            }
            if (!in_array($sku['size'], $arr['size'])) {
                $arr['size'][] = $sku['size'];
            }
            if (!in_array($sku['version'], $arr['version'])) {
                $arr['version'][] = $sku['version'];
            }
        }


        //没有属性时，查询库存
        $oneSku = [];
        if (count($propertyList) == 0) {
            $oneSku = DB::table(Sku::tableName())
                ->asEntity(Sku::className())
                ->where('status=?', [Sku::STATUS_DISPLAY])
                ->where('product_id=?', [$id])
                ->findOne();

            if ($oneSku == null) {
                $oneSku = new Sku();
                $oneSku->loadDefaultValues();
            }

        }

        // 分组
        $mixList = DB::table(Mix::tableName())
            ->where('status = ?', [Mix::STATUS_NORMAL])
            ->field(['id', 'name'])
            ->findAll();
        $mixList = Util::arrayColumn($mixList, 'name', 'id');

        // 标签
        $tagList = DB::table(Tag::tableName())
            ->where('status = ?', [Tag::STATUS_NORMAL])
            ->field(['id', 'name'])
            ->findAll();
        $tagList = Util::arrayColumn($tagList, 'name', 'id');

        // 地区
        $regionList = RegionService::findParentList();

        $expressList = ExpressService::findNormalList();
        $expressList = Util::arrayColumn($expressList, 'name', 'id');

        $contentShow = 1;

        if (isset(Application::$app['params']['admin.product.content']) && (Application::$app['params']['admin.product.content'] === false)) {
            $contentShow = 0;
        }

        // 和商品相同属性的分类数据
        $categoryList1 = self::handleCategoryListForUpdate($product['category_id']);

        //视图
        return View::render('@AdminBundle/product/update.twig', [
            'entity' => $entity,
            'error' => $error,
            'brandList' => $brandList,
            'categoryList' => $categoryList1,
            'status' => $status,
            'propertyList' => $propertyList,
            'imageList' => $imageList,
            'propertyInfo' => $arr,
            'skuList' => $skuList,
            'sku' => $oneSku,      //用于商品没有属性，只有一个sku的情况
            'coverImage' => $coverImage,
            'mixList' => $mixList,
            'tagList' => $tagList,
            'regionList' => $regionList,
            'contentList' => $contentList, // 商品的图文详情

            'expressList' => $expressList, // 运费模板

            'contentShow' => $contentShow,
        ]);
    }

    /**
     * 为商品更新处理分类数据
     * @param $categoryId
     * @return array
     */
    private function handleCategoryListForUpdate($categoryId)
    {
        // 商品的分类
        $productCategory = DB::table(Category::tableName())
            ->findByPkOrFail($categoryId);

        $productCategoryProperty = $productCategory['property'];
        $productCategoryProperty = trim($productCategoryProperty);

        $tempCategoryList = DB::table(Category::tableName())
            ->where('status = ?', [Category::STATUS_DISPLAY])
            ->asEntity(Category::className())
            ->findAll();

        $categoryList = [];

        if (!$productCategoryProperty) {
            foreach ($tempCategoryList as $value) {
                if ($value['property'] && ($value['property'] != null)) {
                    continue;
                }

                $categoryList[] = $value;
            }
        } else {
            $productCategoryPropertyList = json_decode($productCategoryProperty, 'true');
            $productCategoryPropertyKeys = array_keys($productCategoryPropertyList);

            foreach ($tempCategoryList as $value) {
                $value['property'] = trim($value['property']);
                if (!$value['property']) {
                    continue;
                }

                if ($value['property'] == null) {
                    continue;
                }

                $tempPropertyKeys = array_keys(json_decode($value['property'], true));

                $tempArr1 = array_diff($productCategoryPropertyKeys, $tempPropertyKeys);
                $tempArr2 = array_diff($tempPropertyKeys, $productCategoryPropertyKeys);

                if ((count($tempArr1) != 0) || (count($tempArr2) != 0)) {
                    continue;
                }

                $categoryList[] = $value;
            }
        }

        // 将当前的分类加进去
        $categoryList = Util::arrayColumn($categoryList, null, 'id');
        if (!array_key_exists($categoryId, $categoryList)) {
            $categoryList[$categoryId] = $productCategory;
        }
        $categoryList = array_values($categoryList);

        $categoryList1 = [];
        foreach ($categoryList as $k => $val) {
            $categoryList1[$val['path'] . $val['id'] . ','] = $val;
            $count = count(explode(',', $val['path'])) - 2;
            $categoryList1[$val['path'] . $val['id'] . ',']['name'] = str_repeat("　", $count) . $categoryList1[$val['path'] . $val['id'] . ',']['name'];
        }
        ksort($categoryList1);

        return $categoryList1;
    }

    /**
     * 商品更新操作
     * @param $id
     * @param Product $entity
     * @param $imageList
     * @param $contentList
     * @param Request $request
     * @param string $error
     * @return bool
     */
    private function actionUpdate($id, Product $entity, $imageList, $contentList, Request $request, &$error = '')
    {
        $data = $request->get('Product');
        $mixIds = $request->get('MixIds');
        $imageNowIds = (array)$request->get('imageIds');
        $fileKeys = $request->get('fileKeys');
        $contentNowIds = (array)$request->get('contentImgIds', []);
        $contentImgFileKeys = (array)$request->get('contentImgFileKeys', []);

        DB::getConnection()->beginTransaction();

        if (!ProductService::update($entity->id, $data, $mixIds, $error)) {
            DB::getConnection()->rollBack();
            return false;
        }

        //处理被删除的图片
        $oldIds = Util::arrayColumn($imageList, 'id');
        $ids = array_diff($oldIds, $imageNowIds);
        DB::table(Image::tableName())
            ->where('product_id=?', $id)
            ->whereIn('id', $ids)
            ->delete();

        //保存新增图片
        if (!empty($fileKeys)) {
            foreach ($fileKeys as $fileKey) {
                $file = self::moveFromTemp($fileKey);
                ProductService::saveImage($entity->id, $file, Image::TYPE_DETAIL);
            }
        }

        //保存主图
        $file = self::moveFromTemp($request->get('coverFileKey'));
        ProductService::saveImage($id, $file, Image::TYPE_COVER);

        // 保存标签
        $tagId = (int)$request->get('TagId');
        DB::table(TagProduct::tableName())->where('product_id = ?', [$id])->delete();
        if ($tagId) {
            DB::table(TagProduct::tableName())
                ->insert(['product_id' => $id, 'tag_id' => $tagId]);
        }

        // 保存禁卖地区IDs
        $regionIds = $request->get('RegionIds', []);
        DB::table(ProductRegion::tableName())
            ->where('product_id = ?', [$id])
            ->delete();

        if ($regionIds) {
            foreach ($regionIds as $regionId) {
                $regionData = [
                    'product_id' => $id,
                    'region_id' => $regionId,
                ];
                $regionData['created_at'] = $regionData['updated_at'] = Carbon::now();

                DB::table(ProductRegion::tableName())->insert($regionData);
            }
        }

        // 保存新增的图文详情
        //处理被删除的图片
        $oldIds = Util::arrayColumn($contentList, 'id');
        $ids = array_diff($oldIds, $contentNowIds);
        DB::table(ProductContent::tableName())
            ->where('product_id=?', $id)
            ->whereIn('id', $ids)
            ->delete();

        //保存新增图片
        if (!empty($contentImgFileKeys)) {
            foreach ($contentImgFileKeys as $item) {
                $file = self::moveFromTemp($item);
                if (!ProductService::saveProductContent($entity->id, $file)) {
                    $error = '图文详情保存失败';
                    DB::getConnection()->rollBack();
                    return false;
                }
            }
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 删除
     * @Route admin/product/delete
     */
    public function delete(Request $request)
    {
        if (ProductService::delete($request->get('id'))) {
            Session::setFlash('message', '删除成功');
        } else {
            Session::setFlash('message', '删除失败');
        }
        return Redirect::back();
    }

    /**
     * @Route admin/product/upload
     */
    public function upload()
    {
        return Json::render($this->uploadToTemp('file'));
    }

}