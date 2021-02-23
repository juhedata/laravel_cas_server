<?php
/**
 * Created by PhpStorm.
 * User: chenyihong
 * Date: 16/8/1
 * Time: 14:52
 */

namespace JuheData\CAS\Http\Controllers;

use Illuminate\Support\Str;
use JuheData\CAS\Contracts\TicketLocker;
use JuheData\CAS\Repositories\PGTicketRepository;
use JuheData\CAS\Repositories\TicketRepository;
use JuheData\CAS\Exceptions\CAS\CasException;
use JuheData\CAS\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JuheData\CAS\Responses\JsonAuthenticationFailureResponse;
use JuheData\CAS\Responses\JsonAuthenticationSuccessResponse;
use JuheData\CAS\Responses\JsonProxyFailureResponse;
use JuheData\CAS\Responses\JsonProxySuccessResponse;
use JuheData\CAS\Responses\XmlAuthenticationFailureResponse;
use JuheData\CAS\Responses\XmlAuthenticationSuccessResponse;
use JuheData\CAS\Responses\XmlProxyFailureResponse;
use JuheData\CAS\Responses\XmlProxySuccessResponse;
use JuheData\CAS\Services\PGTCaller;
use JuheData\CAS\Services\TicketGenerator;
use SimpleXMLElement;

class ValidateController extends Controller
{
    /**
     * @var TicketLocker
     */
    protected $ticketLocker;
    /**
     * @var TicketRepository
     */
    protected $ticketRepository;

    /**
     * @var PGTicketRepository
     */
    protected $pgTicketRepository;

    /**
     * @var TicketGenerator
     */
    protected $ticketGenerator;

    /**
     * @var PGTCaller
     */
    protected $pgtCaller;

    /**
     * ValidateController constructor.
     * @param TicketLocker $ticketLocker
     * @param TicketRepository $ticketRepository
     * @param PGTicketRepository $pgTicketRepository
     * @param TicketGenerator $ticketGenerator
     * @param PGTCaller $pgtCaller
     */
    public function __construct(
        TicketLocker $ticketLocker,
        TicketRepository $ticketRepository,
        PGTicketRepository $pgTicketRepository,
        TicketGenerator $ticketGenerator,
        PGTCaller $pgtCaller
    ) {
        $this->ticketLocker = $ticketLocker;
        $this->ticketRepository = $ticketRepository;
        $this->pgTicketRepository = $pgTicketRepository;
        $this->ticketGenerator = $ticketGenerator;
        $this->pgtCaller = $pgtCaller;
    }

    public function v1ValidateAction(Request $request)
    {
        $service = $request->get('service', '');
        $ticket = $request->get('ticket', '');
        if (empty($service) || empty($ticket)) {
            return new Response('no');
        }

        // 锁定有效ticket：如果有特殊需求可以自定义处理LockTicket->acquireLock
        if (!$this->lockTicket($ticket)) {
            return new Response('no');
        }

        // 查询Ticket是否存在
        $record = $this->ticketRepository->getByTicket($ticket);
        if (!$record || $record->service_url != $service) {
            $this->unlockTicket($ticket);

            return new Response('no');
        }

        // 删除Ticket，使当前ticket失效
        $this->ticketRepository->invalidTicket($record);

        // 释放ticket
        $this->unlockTicket($ticket);

        // 校验通过
        return new Response('yes');
    }

    public function v2ServiceValidateAction(Request $request)
    {
        return $this->casValidate($request, true, false);
    }

    public function v3ServiceValidateAction(Request $request)
    {
        return $this->casValidate($request, true, false);
    }

    public function v2ProxyValidateAction(Request $request)
    {
        return $this->casValidate($request, config('cas.returnArr', false), true);
    }

    public function v3ProxyValidateAction(Request $request)
    {
        return $this->casValidate($request, true, true);
    }

    public function proxyAction(Request $request)
    {
        $pgt = $request->get('pgt', '');
        $target = $request->get('targetService', '');
        $format = strtoupper($request->get('format', 'XML'));

        if (empty($pgt) || empty($target)) {
            return $this->proxyFailureResponse(
                CasException::INVALID_REQUEST,
                'param pgt and targetService can not be empty',
                $format
            );
        }

        $record = $this->pgTicketRepository->getByTicket($pgt);
        try {
            if (!$record) {
                throw new CasException(CasException::INVALID_TICKET, 'ticket is not valid');
            }
            $proxies = $record->proxies;
            array_unshift($proxies, $record->pgt_url);
            $ticket = $this->ticketRepository->applyTicket($record->user, $target, $proxies);
        } catch (CasException $e) {
            return $this->proxyFailureResponse($e->getCasErrorCode(), $e->getMessage(), $format);
        }

        return $this->proxySuccessResponse($ticket->ticket, $format);
    }

    /**
     * 只校验service主体部分，不校验query参数部分，
     *
     * @param $recordService
     * @param $service
     * @return bool
     */
    protected function checkService($recordService, $service)
    {
        $recordService = explode('?', $recordService);
        $service = explode('?', $service);
        return strtolower(trim($recordService[0])) == strtolower(trim($service[0]));
    }

    /**
     * @param Request $request
     * @param bool $returnAttr
     * @param bool $allowProxy
     * @return Response
     */
    protected function casValidate(Request $request, $returnAttr, $allowProxy)
    {
        // service
        $service = $request->get('service', '');

        // ticket
        $ticket = $request->get('ticket', '');

        // 返回数据格式:XML、JSON；默认XML
        $format = strtoupper($request->get('format', 'XML'));

        // 抛出异常，缺少必要参数
        if (empty($service) || empty($ticket)) {
            return $this->authFailureResponse(
                CasException::INVALID_REQUEST,
                'param service and ticket can not be empty',
                $format
            );
        }

        // 锁定Ticket
        if (!$this->lockTicket($ticket)) {
            return $this->authFailureResponse(CasException::INTERNAL_ERROR, 'try to lock ticket failed', $format);
        }

        // 获取Ticket记录
        $record = $this->ticketRepository->getByTicket($ticket);
        try {
            // ticket处理
            if (!$record || (!$allowProxy && $record->isProxy())) {
                throw new CasException(CasException::INVALID_TICKET, 'ticket is not valid');
            }

            // service 处理: 在进行登录的时候回携带service,登录成功后，service会和发放的Ticket存放到一起；
            // 等ticket进行校验的时候，同时会校验service和存储的service是否一致
            // 这里进行特殊处理了，不校验service的query参数部分，只校验url主体path
            if (!$service || !trim($record->service_url) || !$this->checkService($record->service_url, $service)) {
                throw new CasException(CasException::INVALID_SERVICE,
                    'service is not valid [recordServiceUrl:' . $record->service_url . '::postServiceUrl:' . $service);
            }
        } catch (CasException $e) {
            // 校验异常，且ticket记录存在，删除该Ticket信息
            $record instanceof Ticket && $this->ticketRepository->invalidTicket($record);
            $this->unlockTicket($ticket);
            // 校验失败
            return $this->authFailureResponse($e->getCasErrorCode(), $e->getMessage(), $format);
        }

        // 代理
        $proxies = [];
        if ($record->isProxy()) {
            $proxies = $record->proxies;
        }

        // 用户信息
        $user = $record->user;

        // Ticket删除，解锁
        $this->ticketRepository->invalidTicket($record);
        $this->unlockTicket($ticket);

        // 代理登录校验：例如A、B都是同过cas登录，如果A已登录访问B的受保护资源，可以不需要在B进行再次登录校验
        $iou = null;
        $pgtUrl = $request->get('pgtUrl', '');
        if ($pgtUrl) {
            try {
                // 这里先等等
                $pgTicket = $this->pgTicketRepository->applyTicket($user, $pgtUrl, $proxies);
                $iou = $this->ticketGenerator->generateOne(config('cas.pg_ticket_iou_len', 64), 'PGTIOU-');
                if (!$this->pgtCaller->call($pgtUrl, $pgTicket->ticket, $iou)) {
                    $iou = null;
                }
            } catch (CasException $e) {
                $iou = null;
            }
        }

        // 校验通过，返回登录用户信息
        $attr = $returnAttr ? $record->user->getCASAttributes() : [];

        return $this->authSuccessResponse($record->user->getName(), $format, $attr, $proxies, $iou);
    }

    /**
     * @param string $username
     * @param string $format
     * @param array $attributes
     * @param array $proxies
     * @param string|null $pgt
     * @return Response
     */
    protected function authSuccessResponse($username, $format, $attributes, $proxies = [], $pgt = null)
    {
        if (strtoupper($format) === 'JSON') {
            $resp = app(JsonAuthenticationSuccessResponse::class);
        } else {
            $resp = app(XmlAuthenticationSuccessResponse::class);
        }
        $resp->setUser($username);
        if (!empty($attributes)) {
            $resp->setAttributes($attributes);
        }
        if (!empty($proxies)) {
            $resp->setProxies($proxies);
        }

        if (is_string($pgt)) {
            $resp->setProxyGrantingTicket($pgt);
        }

        return $resp->toResponse();
    }

    /**
     * @param string $code
     * @param string $description
     * @param string $format
     * @return Response
     */
    protected function authFailureResponse($code, $description, $format)
    {
        if (strtoupper($format) === 'JSON') {
            $resp = app(JsonAuthenticationFailureResponse::class);
        } else {
            $resp = app(XmlAuthenticationFailureResponse::class);
        }
        $resp->setFailure($code, $description);

        return $resp->toResponse();
    }

    /**
     * @param string $ticket
     * @param string $format
     * @return Response
     */
    protected function proxySuccessResponse($ticket, $format)
    {
        if (strtoupper($format) === 'JSON') {
            $resp = app(JsonProxySuccessResponse::class);
        } else {
            $resp = app(XmlProxySuccessResponse::class);
        }
        $resp->setProxyTicket($ticket);

        return $resp->toResponse();
    }

    /**
     * @param string $code
     * @param string $description
     * @param string $format
     * @return Response
     */
    protected function proxyFailureResponse($code, $description, $format)
    {
        if (strtoupper($format) === 'JSON') {
            $resp = app(JsonProxyFailureResponse::class);
        } else {
            $resp = app(XmlProxyFailureResponse::class);
        }
        $resp->setFailure($code, $description);

        return $resp->toResponse();
    }

    /**
     * @param string $ticket
     * @return bool
     */
    protected function lockTicket($ticket)
    {
        return $this->ticketLocker->acquireLock($ticket, config('cas.lock_timeout'));
    }

    /**
     * @param string $ticket
     * @return bool
     */
    protected function unlockTicket($ticket)
    {
        return $this->ticketLocker->releaseLock($ticket);
    }
}
