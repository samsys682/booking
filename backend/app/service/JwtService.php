<?php
// =====================================================================
// booking 后端 · JWT 服务（HS256，自实现不引三方依赖）
// 接口规范：token 鉴权；payload 仅存 aid/role/iat/exp，不放敏感信息
// =====================================================================

namespace app\service;

class JwtService
{
    /** @var array 解码后缓存 */
    private $cache = [];

    /**
     * 签发 token
     * @param array $payload 载荷（内部自动补 iat/exp）
     * @param int   $ttl     有效期秒，0 取配置默认
     * @return string
     */
    public function encode(array $payload, int $ttl = 0): string
    {
        $ttl = $ttl > 0 ? $ttl : (int)env('security.jwt_ttl', 7200);
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttl;

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = [
            $this->b64url(json_encode($header, JSON_UNESCAPED_UNICODE)),
            $this->b64url(json_encode($payload, JSON_UNESCAPED_UNICODE)),
        ];
        $signing = implode('.', $segments);
        $segments[] = $this->b64url(hash_hmac('sha256', $signing, $this->secret(), true));

        return implode('.', $segments);
    }

    /**
     * 校验并解析 token
     * @param string $token
     * @return array payload
     * @throws \RuntimeException 无效/过期时抛出
     */
    public function decode(string $token): array
    {
        if (isset($this->cache[$token])) {
            return $this->cache[$token];
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('token 格式无效');
        }
        list($h64, $p64, $s64) = $parts;
        $signing = $h64 . '.' . $p64;

        // 签名比对用 hash_equals 防时序攻击
        $expect = $this->b64url(hash_hmac('sha256', $signing, $this->secret(), true));
        if (!hash_equals($expect, $s64)) {
            throw new \RuntimeException('token 签名无效');
        }
        $payload = json_decode($this->unb64url($p64), true);
        if (!is_array($payload)) {
            throw new \RuntimeException('token 载荷无效');
        }
        if (empty($payload['exp']) || (int)$payload['exp'] < time()) {
            throw new \RuntimeException('token 已过期，请重新登录');
        }
        $this->cache[$token] = $payload;
        return $payload;
    }

    /**
     * 签名密钥（安装向导随机生成写入 .env）
     * 安全红线：密钥缺失直接拒绝签发/校验，禁止退化为可推导的派生密钥
     * （否则任何人都可伪造登录凭证冒充任意账号，等于不设防）
     * @return string
     * @throws \RuntimeException 密钥未配置
     */
    private function secret(): string
    {
        $secret = (string)env('security.jwt_secret', '');
        if ($secret === '') {
            throw new \RuntimeException('安全密钥未配置（security.jwt_secret），系统拒绝签发登录凭证，请检查 .env 配置');
        }
        return $secret;
    }

    /**
     * base64url 编码
     * @param string $data
     * @return string
     */
    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * base64url 解码
     * @param string $data
     * @return string
     */
    private function unb64url(string $data): string
    {
        return (string)base64_decode(strtr($data, '-_', '+/'));
    }
}
