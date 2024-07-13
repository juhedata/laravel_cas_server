<?php
/**
 * Created by PhpStorm.
 * User: chenyihong
 * Date: 16/8/1
 * Time: 14:50
 */

namespace JuheData\CAS\Http\Controllers;

use JuheData\CAS\Contracts\Interactions\UserLogin;
use JuheData\CAS\Contracts\Models\UserModel;
use JuheData\CAS\Events\CasUserLoginEvent;
use JuheData\CAS\Events\CasUserLogoutEvent;
use JuheData\CAS\Exceptions\CAS\CasException;
use Illuminate\Http\Request;
use JuheData\CAS\Repositories\PGTicketRepository;
use JuheData\CAS\Repositories\ServiceRepository;
use JuheData\CAS\Repositories\TicketRepository;
use function JuheData\CAS\cas_route;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Symfony\Component\HttpFoundation\Response;

class SecurityController extends Controller
{
    /**
     * cas_services 业务数据模型处理
     * @var ServiceRepository
     */
    protected $serviceRepository;

    /**
     * cas_tickets
     * @var TicketRepository
     */
    protected $ticketRepository;

    /**
     * cas_proxy_granting_tickets 代理
     * @var PGTicketRepository
     */
    protected $pgTicketRepository;
    /**
     * 登录业务处理
     * @var UserLogin
     */
    protected $loginInteraction;

    /**
     * 实例化时，注入参数
     *
     * SecurityController constructor.
     * @param ServiceRepository $serviceRepository
     * @param TicketRepository $ticketRepository
     * @param PGTicketRepository $pgTicketRepository
     * @param UserLogin $loginInteraction
     */
    public function __construct(
        ServiceRepository $serviceRepository,
        TicketRepository $ticketRepository,
        PGTicketRepository $pgTicketRepository,
        UserLogin $loginInteraction
    ) {
        $this->serviceRepository = $serviceRepository;
        $this->ticketRepository = $ticketRepository;
        $this->loginInteraction = $loginInteraction;
        $this->pgTicketRepository = $pgTicketRepository;
    }

    /**
     * 登录页面请求：get -> cas/login
     *
     * @param Request $request
     * @return RedirectResponse|Redirector|Response
     */
    public function showLogin(Request $request)
    {
        // 判断是否有service参数：登录成功后的跳转页面
        $service = $request->get('service', '');
        $errors = [];
        if (!empty($service)) {
            // 如果存在service,处理提取url的host,查询该host是否在cas_service表中，如果不在，则说明是非正常的登录请求
            if (!$this->serviceRepository->isUrlValid($service)) {
                $errors[] = (new CasException(CasException::INVALID_SERVICE))->getCasMsg();
            }
        }

        // 获取当前登录的用户：如果用户登录成功，且登录状态没有过期则$request->user()会有用户信息；
        // 具体如何判断和处理登录后的用户信息，需要自行处理；默认是使用laravel自带的登录鉴权模式
        $user = $this->loginInteraction->getCurrentUser($request);

        // 存在用户单点登录会话
        if ($user) {
            // 如果存在错误信息，则跳转到登录中心的首页，而不是从定向的登录请求地址
            if (!empty($errors)) {
                return $this->loginInteraction->redirectToHome($errors);
            }

            // 如果登录请求存在着警告或安全问题，则显示到登录中心的问题页面
            if ($request->get('warn') === 'true' && !empty($service)) {
                $query = $request->query->all();
                unset($query['warn']);
                $url = cas_route('login_page', $query);

                return $this->loginInteraction->showLoginWarnPage($request, $url, $service);
            }

            //登录鉴权成功
            return $this->authenticated($request, $user);

        }

        return $this->loginInteraction->showLoginPage($request, $errors);
    }

    /**
     * 表单登录请求 : post -> cas/login
     *
     * @param Request $request
     * @return RedirectResponse|Redirector|Response
     */
    public function login(Request $request)
    {
        // UserLogin service进行登录业务逻辑实现
        $user = $this->loginInteraction->login($request);
        if (is_null($user)) {
            return $this->loginInteraction->showAuthenticateFailed($request);
        }

        // 登录鉴权成功
        return $this->authenticated($request, $user);
    }

    /**
     * 登录鉴权成功
     *
     * @param Request $request
     * @param UserModel $user
     * @return RedirectResponse|Redirector|Response
     */
    public function authenticated(Request $request, UserModel $user)
    {

        // 处理登录成功重定向地址
        $serviceUrl = $request->get('service', '');
        if (!empty($serviceUrl)) {
            // 解析service的query参数
            $query = parse_url($serviceUrl, PHP_URL_QUERY);
            try {
                // 发放登录成功的授权ticket，存储service
                $ticket = $this->ticketRepository->applyTicket($user, $serviceUrl);
            } catch (CasException $e) {
                // 这里会添加一个Cas Login Event;如果有需要可以自行进行监听处理
                $request->offsetSet("user-tickes-msg",$e->getMessage());
                event(new CasUserLoginEvent($request, $user));
                // ticket发放异常，跳转登录首页
                return $this->loginInteraction->redirectToHome([$e->getCasMsg()]);
            }

            // 拼接service的query参数和授权Ticket；跳转到客户端进行ticket校验，然后获取用户信息
            $finalUrl = $serviceUrl . ($query ? '&' : '?') . 'ticket=' . $ticket->ticket;
            // 这里会添加一个Cas Login Event;如果有需要可以自行进行监听处理
            $request->offsetSet("user-tickes-msg",$ticket->ticket);
            event(new CasUserLoginEvent($request, $user));
            return redirect($finalUrl);
        }
        // 这里会添加一个Cas Login Event;如果有需要可以自行进行监听处理
        $request->offsetSet("user-tickes-msg",'service-is-empty');

        event(new CasUserLoginEvent($request, $user));
        return $this->loginInteraction->redirectToHome();
    }

    /**
     * 登出操作：get->cas/logout
     *
     * @param Request $request
     * @return RedirectResponse|Redirector|Response
     */
    public function logout(Request $request)
    {
        // 获取当前登录用户信息
        $user = $this->loginInteraction->getCurrentUser($request);
        if ($user) {
            // 退出
            $this->loginInteraction->logout($request);

            // 代理退出
            $this->pgTicketRepository->invalidTicketByUser($user);

            // 添加退出Event，可以自行监听该事件，进行特殊处理操作
            event(new CasUserLogoutEvent($request, $user));
        }
        $service = $request->get('service');
        // 处理退出客户端地址，如果存在则从定向到客户端
        if ($service && $this->serviceRepository->isUrlValid($service)) {
            return redirect($service);
        }

        // 返回登录中心的登出页面
        return $this->loginInteraction->showLoggedOut($request);
    }
}
