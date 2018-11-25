<?php

use \Leaf\Route;

Route::group(['middleware' => ['auth', 'admin', 'csrf']], function () {

    Route::annotation('AdminBundle\Controller\ProductController');
    Route::annotation('AdminBundle\Controller\UserController');
    Route::annotation('AdminBundle\Controller\OrderController');
    Route::annotation('AdminBundle\Controller\CategoryController');
    Route::annotation('AdminBundle\Controller\BrandController');
    Route::annotation('AdminBundle\Controller\CarrierController');
    Route::annotation('AdminBundle\Controller\ReturnOrderController');
    Route::annotation('AdminBundle\Controller\BulkController');
    Route::annotation('AdminBundle\Controller\TimelimitController');
    Route::annotation('AdminBundle\Controller\HomeController');
    Route::annotation('AdminBundle\Controller\HomeItemController');
    Route::annotation('AdminBundle\Controller\HomeModuleController');
    Route::annotation('AdminBundle\Controller\MixController'); 
    Route::annotation('AdminBundle\Controller\TagController'); 
    Route::annotation('AdminBundle\Controller\CommentController'); 
    Route::annotation('AdminBundle\Controller\SpellOrderController');
    Route::annotation('AdminBundle\Controller\ConfigController'); 
    Route::annotation('AdminBundle\Controller\ExpressController');
    Route::annotation('AdminBundle\Controller\MessageController');
    Route::annotation('AdminBundle\Controller\PushController');
    Route::any('admin/password/modify', 'AdminBundle\Controller\AuthController@modify');
});

Route::any('ueditor', 'AdminBundle\Controller\UeditorController@upload');
Route::any('admin', 'AdminBundle\Controller\AuthController@home', ['auth', 'admin']);
Route::any('/', function () {
    return \Leaf\Redirect::to('admin');
}, ['auth', 'admin']);

Route::any('admin/login', 'AdminBundle\Controller\AuthController@login');
Route::any('admin/logout', 'AdminBundle\Controller\AuthController@logout');
Route::any('admin/password/forgot', 'AdminBundle\Controller\AuthController@forgot');
Route::any('admin/password/reset', 'AdminBundle\Controller\AuthController@reset');