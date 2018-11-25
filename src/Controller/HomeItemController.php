<?php

namespace AdminBundle\Controller;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidDateException;
use Entity\HomeItem;
use Leaf\Application;
use Leaf\DB;
use Leaf\Json;
use Leaf\Request;
use Leaf\Session;
use Leaf\Url;
use Leaf\Validator;
use Leaf\View;
use Leaf\Redirect;
use Service\UploadTrait;

class HomeItemController
{
    use UploadTrait;

    /**
     * 列表
     * @Route admin/home-item
     */
    public function index(Request $request)
    {
        $tag = (int)$request->get('tag');

        $tagResult = self::checkTag($tag);

        //查询条件
        $condition = [];
        $params = [];
        $search = $request->get('HomeItem');

        if (!empty($search['id'])) {
            $condition[] = 'id = :id';
            $params[':id'] = $search['id'];
        }

        //数据
        $dataProvider = DB::table(HomeItem::tableName())
            ->asEntity(HomeItem::className())
            ->where(implode(' and ', $condition), $params)
            ->where('tag = ?', [$tag])
            ->orderBy($request->get('sort', 'id desc'))
            ->paginate();

        //视图
        return View::render('@AdminBundle/home-item/index.twig', [
            'dataProvider' => $dataProvider,
            'tagString' => $tagResult['tagString'],
            'tag' => $tag,
        ]);
    }

    /**
     * 新增
     * @Route admin/home-item/create
     */
    public function create(Request $request)
    {
        $tag = (int)$request->get('tag');

        $tagResult = self::checkTag($tag);

        $entity = new HomeItem();
        $entity->loadDefaultValues();

        //保存
        $error = '';
        if ($request->isMethod('POST')) {

            $data = $request->get('HomeItem');
            $fileKey = $request->get('fileKey');

            if (self::createAction($tag, $data, $fileKey, $error)) {
                Session::setFlash('message', '添加成功');
                return Redirect::to(Url::to('admin/home-item', ['tag' => $tag]));
            }
        }

        //视图
        return View::render('@AdminBundle/home-item/create.twig', [
            'entity' => $entity,
            'error' => $error,

            'tagString' => $tagResult['tagString'],
            'tag' => $tag,
        ]);
    }

    /**
     * 添加操作
     * @param $tag
     * @param $data
     * @param $fileKey
     * @param string $error
     * @return bool
     */
    private function createAction($tag, $data, $fileKey, &$error = '')
    {
        if (!Validator::validate($data, self::getRules('create'), HomeItem::labels())) {
            $error = Validator::getFirstError();
            return false;
        }

        // 图片
        $file = self::moveFromTemp($fileKey);

        $data['pic'] = $file;

        // 验证  名称、图片必须有一个有值
        if ((!$data['name']) && (!$data['pic'])) {
            $error = '名称和图片不能全为空';
            return false;
        }

        $data['tag'] = $tag;

        $data['created_at'] = $data['updated_at'] = Carbon::now();

        DB::table(HomeItem::tableName())->insert($data);

        return true;
    }

    /**
     * 更新
     * @Route admin/home-item/update
     */
    public function update(Request $request, Application $app)
    {
        $tag = (int)$request->get('tag');

        $tagResult = self::checkTag($tag);

        //查询
        $entity = DB::table(HomeItem::tableName())
            ->asEntity(HomeItem::className())
            ->where('tag = ?', [$tag])
            ->findByPkOrFail($request->get('id'));

        //保存
        $error = '';
        if ($request->isMethod('POST')) {

            $data = $request->get('HomeItem');
            $fileKey = $request->get('fileKey');

            if (self::updateAction($entity->id, $data, $fileKey, $error)) {
                Session::setFlash('message', '保存成功');
                return Redirect::to(Url::to('admin/home-item', ['tag' => $tag]));
            }
        }

        //视图
        return View::render('@AdminBundle/home-item/update.twig', [
            'entity' => $entity,
            'error' => $error,

            'tagString' => $tagResult['tagString'],
            'tag' => $tag,
        ]);
    }

    /**
     * 更新操作
     * @param $id
     * @param $data
     * @param $fileKey
     * @param string $error
     * @return bool
     */
    private function updateAction($id, $data, $fileKey, &$error = '')
    {
        //验证
        if (!Validator::validate($data, self::getRules('update'))) {
            $error = Validator::getFirstError();
            return false;
        }

        $entity = DB::table(HomeItem::tableName())->findByPk($id);

        if ($entity == null) {
            $error = '数据有误';
            return false;
        }

        // 图片
        $file = self::moveFromTemp($fileKey);

        if ($file) {
            $data['pic'] = $file;
        } else {
            $data['pic'] = $entity['pic'];
        }

        // 验证  名称、图片必须有一个有值
        if ((!$data['name']) && (!$data['pic'])) {
            $error = '名称和图片不能全为空';
            return false;
        }

        $data['updated_at'] = Carbon::now();

        $row = DB::table(HomeItem::tableName())->wherePk($id)->update($data);

        if ($row != 1) {
            $error = '保存失败';
            return false;
        }

        return true;
    }

    /**
     * 删除
     * @Route admin/home-item/delete
     * @Method post
     */
    public function delete(Request $request)
    {
        $tag = (int)$request->get('tag');

        $tagResult = self::checkTag($tag);

        $result = DB::table(HomeItem::tableName())
            ->where('tag = ?', [$tag])
            ->wherePk($request->get('id'))
            ->delete();

        if ($result) {
            Session::setFlash('message', '删除成功');
        } else {
            Session::setFlash('message', '删除失败');
        }

        return Redirect::back();
    }

    /**
     * 验证规则
     * @param string $scene create|update
     * @return array
     */
    protected function getRules($scene)
    {
        $rules = [
            [['name', 'url',], 'trim'],
            [['name',], 'string', 'length' => [0, 100]],
            [['url',], 'string', 'length' => [0, 200]],
        ];

        return $rules;
    }

    /**
     * 验证标识
     * @param $tag
     * @return array
     */
    private function checkTag($tag)
    {
        $tag = (int)$tag;
        $tagList = HomeItem::tagList();

        if (!array_key_exists($tag, $tagList)) {
            throw new InvalidDateException('tag', $tag);
        }

        return [
            'tagList' => $tagList,
            'tagString' => $tagList[$tag],
        ];
    }

    /**
     * @Route admin/home-item/upload
     */
    public function upload()
    {
        return Json::render($this->uploadToTemp('file'));
    }

}
