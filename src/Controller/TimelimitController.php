<?php

namespace AdminBundle\Controller;

use Carbon\Carbon;
use Entity\Product;
use Entity\Timelimit;
use Entity\TimelimitSku;
use Leaf\Application;
use Leaf\DB;
use Leaf\Json;
use Leaf\Request;
use Leaf\Session;
use Leaf\Util;
use Leaf\Validator;
use Leaf\View;
use Leaf\Redirect;

/**
 * 限时秒杀管理
 * @author  curd generator
 * @since   1.0
 */
class TimelimitController
{
    /**
     * 列表
     * @Route admin/timelimit
     */
    public function index(Request $request)
    {
        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('Timelimit');

        if (!empty($search['id'])) {
            $condition[] = 'id = :id';
            $params[':id'] = $search['id'];
        }

        //数据
        $dataProvider = Timelimit::where(implode(' and ', $condition), $params)
            ->orderBy($request->get('sort', 'id desc'))
            ->where('status!=?', Timelimit::STATUS_DEL)
            ->paginate();

        //视图
        return View::render('@AdminBundle/timelimit/index.twig', [
            'dataProvider' => $dataProvider,
        ]);
    }


    /**
     * 更新
     * @Route admin/timelimit/update
     */
    public function update(Request $request, Application $app)
    {
        $productId = (int)$request->get('productId');

        //产品
        $product = Product::with(['timelimit', 'skuList.timelimit'])->findByPkOrFail($productId);

        $propertyList = $product->getPropertyList(); // 商品分类涉及的属性数据
        $skuList = $product->getSkuList(); // 商品库存信息

        if (count($skuList) <= 0) {
            Session::setFlash('message', '请先将商品信息填写完整');
            return Redirect::back();
        }

        if ($product->timelimit == null) {
            $product->timelimit = new Timelimit();
            $product->timelimit->loadDefaultValues();
            $product->timelimit->product_id = $productId;
            $product->timelimit->status = Timelimit::STATUS_NO;
            $product->timelimit->begin = time();
            $product->timelimit->duration_second = 24 * 60 * 60;
        }

        //界面需要时间格式
        $product->timelimit->begin = date('Y-m-d H:i:s', $product->timelimit->begin);

        //秒转为小时 (界面上是小时)
        $product->timelimit->duration_hour = $product->timelimit->duration_second / 60 / 60;
        $skuList = $product->getSkuList(); // 商品库存信息

        //视图
        return View::render('@AdminBundle/timelimit/update.twig', [
            'product' => $product,
            'entity' => $product->timelimit,
            'durationList' => $this->getDurationList(),
            'propertyList' => $propertyList,
            'skuList' => $skuList,
        ]);
    }

    private function getDurationList()
    {
//        [
//          0 => "0:00"
//          1800 => "0:30"
//          3600 => "1:00"
//          5400 => "1:30"
//          ...
//    ]
        $arr = [];
        for ($i = 0; $i < 48; $i++) {

            if ($i > 0 && $i < 12) { //跳过0:30到5:30  没必要把开始时间设在这个点，减少下拉列表中的数据
                continue;
            }

            $arr[$i * 60 * 30] = intval($i / 2) . ':' . ($i % 2 == 0 ? '00' : '30');
        }

        return $arr;
    }

    /**
     * 删除
     * @Route admin/timelimit/save
     * @Method post
     */
    public function save(Request $request)
    {
        $timelimit = $request->get('Timelimit');
        $timelimit['duration_second'] = $timelimit['duration_hour'] * 60 * 60;
        unset($timelimit['duration_hour']);
        //转为时间戳
        $timelimit['begin'] = strtotime($timelimit['begin']);

        if (!Validator::validate($timelimit, self::getRules())) {
            return Json::renderWithFalse(Validator::getFirstError());
        }

        DB::getConnection()->beginTransaction();

        $exists = Timelimit::where('product_id=? and status!=?', [$timelimit['product_id'], Timelimit::STATUS_DEL])->lockForUpdate()->findOne();
        if ($exists == null) {

            $timelimit['created_at'] = Carbon::now();
            $id = Timelimit::insertGetId($timelimit);

        } else {

            $id = $exists->id;
            $timelimit['updated_at'] = Carbon::now();

            Timelimit::wherePk($id)
                ->update($timelimit);
        }

        if ($timelimit['status'] == Timelimit::STATUS_YES) {
            foreach ($request->get('Sku') as $skuId => $item) {

                if ($item['price'] <= 0) {
                    DB::getConnection()->rollBack();
                    Session::setFlash('message', '单价有误');
                    return Redirect::back();
                }

                $data = [];
                $data['timelimit_id'] = $id;
                $data['product_id'] = $timelimit['product_id'];
                $data['price'] = Util::calc($item['price'], 0, '+');
                $data['sku_id'] = $skuId;


                $one = TimelimitSku::where([
                    'timelimit_id' => $data['timelimit_id'],
                    'sku_id' => $data['sku_id'],
                    'status' => TimelimitSku::STATUS_YES,
                ])->findOne();

                if ($one) {
                    $data['updated_at'] = Carbon::now();
                    TimelimitSku::wherePk($one->id)->update($data);
                } else {
                    TimelimitSku::insert($data);
                }
            }
        }

        DB::getConnection()->commit();

        Session::setFlash('message', '操作成功');

        return Redirect::back();
    }


    /**
     * 删除
     * @Route admin/timelimit/delete
     * @Method post
     */
    public function delete(Request $request)
    {
        $result = Timelimit::wherePk($request->get('id'))->update(['status' => Timelimit::STATUS_DEL, 'updated_at' => Carbon::now()]);

        if ($result) {
            Session::setFlash('message', '删除成功');
        } else {
            Session::setFlash('message', '删除失败');
        }

        return Redirect::back();
    }

    /**
     * 验证规则
     * @return array
     */
    protected function getRules()
    {
        $rules = [
            [['product_id', 'begin', 'duration_second', 'status',], 'required'],
            [['product_id', 'begin', 'duration_second', 'status',], 'integer'],
        ];

        return $rules;
    }

}
