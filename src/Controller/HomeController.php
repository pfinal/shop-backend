<?php

namespace AdminBundle\Controller;

use Entity\HomeItem;
use Leaf\Request;
use Leaf\View;

/**
 * 店铺装修
 */
class HomeController
{
    /**
     * 配置
     *
     * @Route admin/home
     */
    public function index(Request $request)
    {
        $tempData = HomeItem::tagList();

        $tagList = [];
        foreach ($tempData as $key => $item) {
            $tagList[] = ['tag' => $key, 'name' => $item,];
        }

        //视图
        return View::render('@AdminBundle/home/index.twig', [
            'tagList' => $tagList,
        ]);
    }

}
