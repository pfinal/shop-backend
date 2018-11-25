<?php

namespace AdminBundle\Controller;

use Leaf\Json;
use Leaf\Request;
use Service\UEditorUploadTrait;

/**
 * UEditor 辅助类
 */
class UeditorController
{
    use UEditorUploadTrait;

    /**
     * 百度UE编辑器上传入口
     */
    public function upload(Request $request)
    {
        return Json::encode($this->editorUpload($request));
    }
}