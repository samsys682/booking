<?php
// =====================================================================
// booking 后端 · 微信小程序登录服务（缺口补齐批次四，功能矩阵 §九）
//
//   code2session(js_code)   GET  /sns/jscode2session         → openid
//   phoneByCode(phone_code) POST /wxa/business/getuserphonenumber → 手机号快捷授权
//
// 凭证单源复用 notify 组同一组键（wechat_notify_appid/secret，secret 经
// PayCrypto AES-256-GCM 加密存储）；gateway 可经 setting wechat_notify_gateway
// 覆盖 —— 与 WechatNotifyService 同一读取口径，便于对接测试桩。
// 登录功能本身全版本可用（开源版会员也要能登录）；配置入口沿用通知配置
// 的版本门禁（message.wechat=pro），未配置时由调用方降级提示。
// =====================================================================

namespace app\service;

use app\service\pay\PayCrypto;

class WechatAuthService
{
    /** jscode2session 常见错误码 → 商家可读文案（其余透传 errmsg） */
    const CODE_ERRORS = [
        40029 => 'js_code 无效，请重新调起微信登录',
        40163 => 'code 已被使用，请重新调起微信登录',
        45011 => '操作过于频繁，请稍后重试',
        40226 => '账号存在风险，微信已拦截本次登录',
        -1    => '微信系统繁忙，请稍后重试',
    ];

    private WechatNotifyService $notify;

    public function __construct()
    {
        $this->notify = new WechatNotifyService();
    }

    /** 凭证是否完整（appid + secret，单源复用 notify 组键） */
    public function configured(): bool
    {
        return $this->notify->configured();
    }

    /**
     * 小程序登录凭证换 openid
     * @param string $jsCode wx.login 返回的 code（微信侧一次性）
     * @return array {openid:string, session_key:string, unionid?:string}
     * @throws \RuntimeException 凭证缺失 / 网络失败 / 微信错误码（文案可直接出参提示）
     */
    public function code2session(string $jsCode): array
    {
        $raw = $this->config();
        $url = $raw['gateway'] . '/sns/jscode2session?appid=' . urlencode($raw['appid'])
            . '&secret=' . urlencode($raw['secret'])
            . '&js_code=' . urlencode($jsCode)
            . '&grant_type=authorization_code';
        $json = $this->request('GET', $url, null);
        $errcode = (int)($json['errcode'] ?? 0);
        if ($errcode !== 0 || empty($json['openid'])) {
            $msg = self::CODE_ERRORS[$errcode]
                ?? ('微信登录失败：' . (string)($json['errmsg'] ?? ('errcode ' . $errcode)));
            throw new \RuntimeException($msg, $errcode ?: 500);
        }
        return $json;
    }

    /**
     * 手机号快捷授权（新接口 phone_code 换手机号，用于与老手机号会员合并）
     * @param string $phoneCode 小程序 getPhoneNumber 事件返回的 code
     * @return string 纯手机号
     * @throws \RuntimeException 换取失败（调用方可静默降级为纯 openid 登录）
     */
    public function phoneByCode(string $phoneCode): string
    {
        $raw = $this->config();
        $token = $this->notify->accessToken();
        $url = $raw['gateway'] . '/wxa/business/getuserphonenumber?access_token=' . urlencode($token);
        $json = $this->request('POST', $url, json_encode(['code' => $phoneCode], JSON_UNESCAPED_UNICODE));
        $errcode = (int)($json['errcode'] ?? 0);
        $phone = (string)($json['phone_info']['purePhoneNumber'] ?? ($json['phone_info']['phoneNumber'] ?? ''));
        if ($errcode !== 0 || $phone === '') {
            throw new \RuntimeException(
                '获取微信手机号失败：' . (string)($json['errmsg'] ?? ('errcode ' . $errcode)),
                $errcode ?: 500
            );
        }
        return $phone;
    }

    /** 配置读取（gateway 兜底默认官方网关；未配置时 gateway 仍返回，由调用方先判 configured） */
    private function config(): array
    {
        $raw = $this->notify->raw();
        if ($raw['appid'] === '' || $raw['secret'] === '') {
            throw new \RuntimeException('微信登录参数未配置（appid/secret），请联系商家管理员');
        }
        return $raw;
    }

    /**
     * 统一 curl 请求（与 WechatNotifyService::request 同口径；该类方法为 private 无法复用）
     * @return array 解析后的 JSON
     * @throws \RuntimeException 网络异常 / 非 JSON 响应
     */
    private function request(string $method, string $url, ?string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('服务器未安装 curl 扩展');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
        ]);
        $text = curl_exec($ch);
        if ($text === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('微信接口连接失败：' . $err);
        }
        curl_close($ch);
        $json = json_decode((string)$text, true);
        if (!is_array($json)) {
            throw new \RuntimeException('微信接口响应异常');
        }
        return $json;
    }
}
