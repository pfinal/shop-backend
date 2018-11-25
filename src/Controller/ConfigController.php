<?php

namespace AdminBundle\Controller;

use Leaf\Application;
use Carbon\Carbon;
use Entity\Config;
use Leaf\DB;
use Leaf\Json;
use Leaf\Request;
use Leaf\Session;
use Leaf\Url;
use Leaf\Validator;
use Leaf\View;
use Leaf\Redirect;
use Service\UploadTrait;

class ConfigController
{
    use UploadTrait;

    /**
     * 列表
     * @Route admin/config
     */
    public function index(Request $request)
    {
        $basicTypeList = Config::basicList();

        //保存
        $error = '';
        if ($request->isMethod('post')) {
            if (self::saveBasicList($basicTypeList, $request->get('Config', []), $error)) {
                Session::setFlash('message', '保存成功');
                return Redirect::to(Url::to('admin/config'));
            }
        }

        $list = [];

        foreach ($basicTypeList as $type => $typeName) {
            $info = DB::table(Config::tableName())
                ->asEntity(Config::className())
                ->where('type = ?', [$type])
                ->findOne();

            if ($info == null) {
                $info = new Config();
                $info->loadDefaultValues();
                $info->type = $type;
            }

            $list[] = [
                'entity' => $info,
                'type_info' => [
                    'type' => $type,
                    'name' => $typeName,
                ],
            ];
        }

        $typeList = Config::typeList();

        //视图
        return View::render('@AdminBundle/config/index.twig', [
            'typeList' => $typeList,
            'error' => $error,

            'list' => $list,
        ]);
    }

    /**
     * 保存基础配置
     * @param $basicTypeList
     * @param $data
     * @param string $error
     * @return bool
     */
    private function saveBasicList($basicTypeList, $data, &$error = '')
    {
        $rule = [
            [['content',], 'trim'],
            [['content',], 'required'],
            [['content',], 'string', 'length' => [1, 255]],
        ];
        DB::getConnection()->beginTransaction();

        foreach ($basicTypeList as $type => $typeName) {
            if (!array_key_exists($type, $data)) {
                $error = '[' . $typeName . ']有误';
                DB::getConnection()->rollBack();
                return false;
            }

            $actionData = [
                'content' => $data[$type],
            ];

            if (!Validator::validate($actionData, $rule, ['content' => $typeName])) {
                $error = Validator::getFirstError();
                DB::getConnection()->rollBack();
                return false;
            }

            // 检测数据是否存在
            // 存在：更新；否则：新增
            $entity = DB::table(Config::tableName())
                ->lockForUpdate()
                ->where('type = ?', [$type])
                ->findOne();

            if ($entity == null) {
                $actionData['type'] = $type;
                $actionData['created_at'] = $actionData['updated_at'] = Carbon::now();
                DB::table(Config::tableName())
                    ->insert($actionData);
            } else {
                $actionData['updated_at'] = Carbon::now();

                DB::table(Config::tableName())->wherePk($entity['id'])->update($actionData);
            }
        }

        DB::getConnection()->commit();
        return true;
    }

    /**
     * 列表
     * @Route admin/config/type
     */
    public function type(Request $request)
    {
        $type = $request->get('type');

        $typeList = Config::typeList();

        if (!array_key_exists($type, $typeList)) {
            throw new \InvalidArgumentException('参数有误');
        }

        //保存
        $error = '';
        if ($request->isMethod('post')) {

            if (self::actionSave($type, $request, $error)) {
                Session::setFlash('message', '保存成功');
                return Redirect::to(Url::to('admin/config/type', ['type' => $type]));
            }
        }

        $entity = DB::table(Config::tableName())
            ->asEntity(Config::className())
            ->where('type = ?', [$type])
            ->findOne();

        if ($entity == null) {
            $entity = new Config();
            $entity->loadDefaultValues();
        }

        //视图
        return View::render('@AdminBundle/config/type.twig', [
            'entity' => $entity,
            'typeList' => $typeList,
            'type' => $type,
            'error' => $error,
        ]);
    }

    /**
     * 保存配置
     * @param $type
     * @param Request $request
     * @param string $error
     * @return bool
     */
    private function actionSave($type, Request $request, &$error = '')
    {
        $fileKey = $request->get('fileKey');

        // 获取对应的数据
        $entity = DB::table(Config::tableName())
            ->asEntity(Config::className())
            ->where('type = ?', [$type])
            ->findOne();

        // 当未传入图片时，判断
        // 新增则必须上传图片
        // 更新，未上传则表示不修改
        if (!$fileKey) {
            // 当为新增的时候，判断必须上传图片
            if ($entity == null) {
                $error = '请上传图片';
                return false;
            }

            return true;
        }

        $file = $this->moveFromTemp($fileKey);

        if (!$file) {
            $error = '图片上传失败';
            return false;
        }

        $data = [
            'file' => $file,
        ];

        // 新增或更新

        if ($entity == null) {
            $data['type'] = $type;
            $data['created_at'] = $data['updated_at'] = Carbon::now();

            DB::table(Config::tableName())->insert($data);
        } else {
            $data['updated_at'] = Carbon::now();
            if (DB::table(Config::tableName())->wherePk($entity['id'])->update($data) != 1) {
                $error = '保存失败';
                return false;
            }
        }

        return true;
    }

    /**
     * @Route admin/config/upload
     */
    public function upload(Application $app)
    {
        //上传到临时目录
        return Json::render($this->uploadToTemp('file'));
    }

}