<?php

namespace AdminBundle\Controller;

use Entity\User;
use Leaf\DB;
use Leaf\Redirect;
use Leaf\Request;
use Leaf\Session;
use Leaf\Validator;
use Leaf\View;
use Service\Auth;
use Service\AuthUser;

class AuthController
{
    use AuthUser;

    public function home()
    {

        return View::render('home.twig', [
            'SERVER_ADDR' => isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : ''
        ]);
    }

    /**
     * 修改密码
     */
    public function modify(Request $request)
    {
        $error = '';
        if ($request->isMethod('post')) {
            $data = $request->get('User');

            //规则
            $rules = [
                [['new_password', 'confirm_password'], 'required'],
                [['new_password', 'confirm_password'], 'string', 'length' => [0, 20]],
                ['new_password', 'compare', 'compareValue' => $data['confirm_password'], 'message' => '两次密码不一致'],
                [['old_password'], 'safe'],
            ];

            //备注
            $labels = [
                'old_password' => '原密码',
                'new_password' => '新密码',
                'confirm_password' => '确认密码'
            ];

            if (Validator::validate($data, $rules, $labels)) {

                $id = Auth::getId();

                $dealer = DB::table(User::tableName())
                    ->where('status = ?', [User::STATUS_ENABLE])
                    ->findByPk($id);

                $old_password = md5($data['old_password']);

                if ($dealer['password_hash'] != $old_password) {
                    $error = '原密码不正确';
                }

                if (empty($error)) {

                    $rowCount = DB::table(User::tableName())
                        ->wherePk($id)
                        ->update([
                            'password_hash' => md5($data['new_password']),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);

                    if ($rowCount > 0) {
                        Session::setFlash('message', '修改密码成功');
                        return Redirect::to('admin/password/modify');
                    } else {
                        $error = '修改密码失败';
                    }
                }


            } else {
                $error = Validator::getFirstError();
            }
        }

        return View::render('auth/password/modify.twig', [
            'error' => $error
        ]);
    }
}

