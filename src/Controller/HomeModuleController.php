<?php

namespace AdminBundle\Controller;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidDateException;
use Entity\HomeModule;
use Entity\Mix;
use Leaf\Application;
use Leaf\DB;
use Leaf\Request;
use Leaf\Session;
use Leaf\Util;
use Leaf\Validator;
use Leaf\View;
use Leaf\Redirect;

/**
 * 首页配置项管理
 */
class HomeModuleController
{
    /**
     * 列表
     * @Route admin/home-module
     */
    public function index(Request $request)
    {
        $tagList = HomeModule::tagList();

        foreach ($tagList as $tag => $value) {
            $temp = DB::table(HomeModule::tableName())
                ->asEntity(HomeModule::className())
                ->where('tag = ?', [$tag])
                ->findOne();

            if ($temp == null) {
                $temp = new HomeModule();
                $temp->loadDefaultValues();
                $temp->tag = $tag;
            }

            $tagList[$tag] = $temp;
        }

        //视图
        return View::render('@AdminBundle/home-module/index.twig', [
            'list' => $tagList,
        ]);
    }

    /**
     * 更新
     * @Route admin/home-module/update
     */
    public function update(Request $request, Application $app)
    {
        $tag = (int)$request->get('tag');

        $tagList = HomeModule::tagList();
        if (!array_key_exists($tag, $tagList)) {
            throw new InvalidDateException('tag', $tag);
        }

        //查询
        $entity = DB::table(HomeModule::tableName())
            ->asEntity(HomeModule::className())
            ->where('tag = ?', [$tag])
            ->findOne();

        if ($entity == null) {
            $entity = new HomeModule();
            $entity->loadDefaultValues();
            $entity->tag = $tag;
        }

        //保存
        $error = '';
        if ($request->isMethod('POST')) {

            $data = $request->get('HomeModule');

            if (self::updateAction($entity, $data, $error)) {
                Session::setFlash('message', '保存成功');
                return Redirect::to('admin/home-module');
            }
        }

        // 分组数据
        $mixList = DB::table(Mix::tableName())
            ->where('status = ?', [Mix::STATUS_NORMAL])
            ->field(['id', 'name'])
            ->findAll();
        $mixList = Util::arrayColumn($mixList, 'name', 'id');

        // 数量的说明
        $quantityDesc = '数量由分组中的商品数量决定';
        if ($entity->tag == HomeModule::TAG_ONE) {
            $quantityDesc = '一行一个模块的商品数量最多只能展示20条';
        }

        //视图
        return View::render('@AdminBundle/home-module/update.twig', [
            'entity' => $entity,
            'error' => $error,

            'mixList' => $mixList,
            'quantityDesc' => $quantityDesc,
        ]);
    }

    /**
     * 更新操作
     * @param HomeModule $entity
     * @param $data
     * @param string $error
     * @return bool
     */
    private function updateAction(HomeModule $entity, $data, &$error = '')
    {
        //验证
        if (!Validator::validate($data, self::getRules('update'), HomeModule::labels())) {
            $error = Validator::getFirstError();
            return false;
        }

        // 限制，一行一个的最多展示20个
        if ($entity->tag == HomeModule::TAG_ONE) {
            if ($data['quantity'] > 20) {
                $error = '一行一个模块的商品数量最多只能展示20条';
                return false;
            }
        }

        // 有ID，更新；否则，新增
        if ($entity->id) {
            $data['updated_at'] = Carbon::now();

            //更新
            $row = DB::table(HomeModule::tableName())->wherePk($entity->id)->update($data);

            if ($row != 1) {
                $error = '保存失败';
                return false;
            }

            return true;
        }

        $data['tag'] = $entity->tag;
        $data['created_at'] = $data['updated_at'] = Carbon::now();

        DB::table(HomeModule::tableName())->insert($data);

        return true;
    }

    /**
     * 验证规则
     * @param string $scene create|update
     * @return array
     */
    protected function getRules($scene)
    {
        $rules = [
            [['group_id', 'quantity',], 'trim'],
            [['group_id', 'quantity',], 'required'],
            [['group_id', 'quantity',], 'integer'],
        ];

        return $rules;
    }

}
