<?php

namespace App\Services\CAS;

use Illuminate\Foundation\Auth\ThrottlesLogins;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use JuheData\CAS\Contracts\Interactions\UserLogin as UserLoginContract;

class UserLoginExample implements UserLoginContract
{
    use ThrottlesLogins;
    protected $userName = 'uid';

    /**
     * 登录页面
     *
     * @param Request $request
     * @param array $errors
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|\Symfony\Component\HttpFoundation\Response
     */
    public function showLoginPage(Request $request, array $errors = [])
    {
        $post_login_url = route('cas.login.post');

        if ($request->getQueryString()) {
            $post_login_url .= '?' . $request->getQueryString();
        }

        $data = [
            'post_login_url' => $post_login_url,
            'sysErrors' => $errors
        ];

        //配置登录表单校验错误
        return view('auth.login')
            ->with($data)
            ->withErrors($errors);
    }

    /**
     * 登录处理
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Auth\Authenticatable|\JuheData\CAS\Contracts\Models\UserModel|null
     */
    public function login(Request $request)
    {
        $request->offsetSet($this->username(), $request->username);

        if ($this->attemptLogin($request)) {
            $request->session()->regenerate();
            $this->clearLoginAttempts($request);
            return $this->guard()->user();
        }

        // 如果登录尝试失败，我们将增加登录尝试次数，并将用户重定向回登录表单。
        // 当然，当这个用户超过他们的最大尝试次数时，他们将被锁定。
        $this->incrementLoginAttempts($request);

        return null;
    }

    /**
     * 获取当前登录用户
     *
     * @param Request $request
     * @return \JuheData\CAS\Contracts\Models\UserModel|mixed|null
     */
    public function getCurrentUser(Request $request)
    {
        return $request->user();
    }

    /**
     * 用户登录校验失败，跳转失败页面，或者跳转至登录页面，错误提示
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View|\Symfony\Component\HttpFoundation\Response
     */
    public function showAuthenticateFailed(Request $request)
    {
        // TODO: Implement showAuthenticateFailed() method.
        return $this->showLoginPage($request);
    }

    /**
     * 登录用户信息有误，跳转到登录预警页面
     *
     * @param Request $request
     * @param string $jumpUrl
     * @param string $service
     * @return \Symfony\Component\HttpFoundation\Response|void
     */
    public function showLoginWarnPage(Request $request, $jumpUrl, $service)
    {
        // TODO: Implement showLoginWarnPage() method.
    }

    /**
     * 跳转到首页，这里首页指向登录页面
     *
     * @param array $errors
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|\Symfony\Component\HttpFoundation\Response
     */
    public function redirectToHome(array $errors = [])
    {
        return redirect('/');
    }

    /**
     * 登出
     *
     * @param Request $request
     * @return string|void
     */
    public function logout(Request $request)
    {
        session(['saml.slo' => true]);
        $this->guard()->logout();

        $request->session()->invalidate();

        return '';
    }

    public function showLoggedOut(Request $request)
    {
        // TODO: Implement showLoggedOut() method.
        if (!($service = $request->get('service'))) {
            return redirect('/');
        }
        return view('error')->withError('非法请求地址:' . $service);
    }


    /**
     * Attempt to log the user into the application.
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    protected function attemptLogin(Request $request)
    {
        return $this->guard()->attempt(
            $this->credentials($request), $request->filled('remember')
        );
    }

    /**
     * Get the needed authorization credentials from the request.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    protected function credentials(Request $request)
    {
        return $request->only($this->username(), 'password');
    }

    /**
     * 自定义用户表的用户名称
     *
     * @return string
     */
    public function username()
    {
        return $this->userName;
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard()
    {
        return Auth::guard();
    }
}
