<?php
// =====================================================================
// booking 后端 · 支付渠道密码学工具（微信 APIv3 / 支付宝 RSA2 共用）
//
// 口径：
//   · 私钥/公钥统一接受「裸 base64」或「完整 PEM」两种写法（商户后台粘贴习惯不一）
//   · 微信 V3 验签报文 = timestamp\n nonce\n body\n，签名算法 RSA-SHA256
//   · 微信 V3 回调解密 = AES-256-GCM（key=APIv3 密钥，nonce/associated_data 取自 resource）
//   · 支付宝签名 = 参数按 key 升序拼 k=v&（剔除 sign/sign_type）后 RSA2(SHA256withRSA)
// =====================================================================

namespace app\service\pay;

class PayCrypto
{
    /**
     * 裸 base64 密钥 → PEM（已是 PEM 则原样返回）
     * @param string $key
     * @param string $type PRIVATE|PUBLIC|CERTIFICATE
     * @return string
     */
    public static function pemify(string $key, string $type = 'PRIVATE'): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        // 支持 \n 转义写法（.env / 表单里常见）
        if (strpos($key, '\\n') !== false && strpos($key, '-----BEGIN') === false) {
            $key = str_replace('\\n', "\n", $key);
        }
        if (strpos($key, '-----BEGIN') !== false) {
            return $key;
        }
        $body = preg_replace('/\s+/', '', $key);
        $body = chunk_split($body, 64, "\n");
        $label = $type === 'PUBLIC' ? 'PUBLIC KEY' : ($type === 'CERTIFICATE' ? 'CERTIFICATE' : 'PRIVATE KEY');
        return "-----BEGIN {$label}-----\n" . $body . "-----END {$label}-----\n";
    }

    /**
     * RSA 签名（默认 SHA256withRSA = RSA2）
     * @param string $data 待签名字符串
     * @param string $privateKey 商户私钥（裸 base64 或 PEM）
     * @return string base64 签名
     * @throws \RuntimeException
     */
    public static function rsaSign(string $data, string $privateKey): string
    {
        $pem = self::pemify($privateKey, 'PRIVATE');
        if ($pem === '') {
            throw new \RuntimeException('支付私钥未配置');
        }
        $res = openssl_get_privatekey($pem);
        if ($res === false) {
            throw new \RuntimeException('支付私钥格式无效');
        }
        $signature = '';
        $ok = openssl_sign($data, $signature, $res, OPENSSL_ALGO_SHA256);
        // PHP 8：密钥对象自动释放，openssl_free_key 已废弃（调用会触发 deprecated 通知）
        if (!$ok) {
            throw new \RuntimeException('支付签名失败');
        }
        return base64_encode($signature);
    }

    /**
     * RSA 验签（RSA2）
     * @param string $data 被签名原文
     * @param string $signatureB64 base64 签名
     * @param string $publicKey 平台/支付宝公钥或证书（裸 base64 / PEM / X.509 证书）
     * @return bool
     */
    public static function rsaVerify(string $data, string $signatureB64, string $publicKey): bool
    {
        $key = trim($publicKey);
        if ($key === '') {
            return false;
        }
        $pem = self::pemify($key, strpos($key, 'CERTIFICATE') !== false ? 'CERTIFICATE' : 'PUBLIC');
        $res = openssl_get_publickey($pem);
        if ($res === false) {
            return false;
        }
        $ok = openssl_verify($data, base64_decode($signatureB64), $res, OPENSSL_ALGO_SHA256);
        // PHP 8：密钥对象自动释放，不再调用已废弃的 openssl_free_key
        return $ok === 1;
    }

    /**
     * AES-256-GCM 解密（微信 APIv3 回调 resource）
     * @param string $ciphertextB64 密文 base64（末 16 字节为 auth tag）
     * @param string $key APIv3 密钥（32 字节）
     * @param string $nonce 12 字节随机串
     * @param string $aad 附加认证数据
     * @return string 明文
     * @throws \RuntimeException
     */
    public static function aesGcmDecrypt(string $ciphertextB64, string $key, string $nonce, string $aad = ''): string
    {
        $raw = base64_decode($ciphertextB64);
        if (strlen($raw) <= 16) {
            throw new \RuntimeException('回调密文长度非法');
        }
        $tag = substr($raw, -16);
        $ciphertext = substr($raw, 0, -16);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        if ($plain === false) {
            throw new \RuntimeException('回调解密失败（密钥或密文不匹配）');
        }
        return $plain;
    }

    /**
     * 商户密钥对称加密（配置落库前加封；AES-256-GCM，密钥派生自 security.pay_config_key）
     * 输出格式：v1:base64(nonce(12) | tag(16) | ciphertext)
     * @param string $plain 明文（私钥/APIv3 密钥等）
     * @param string $key 主密钥（任意长度，内部 sha256 派生 32 字节）
     * @return string
     * @throws \RuntimeException
     */
    public static function encryptSecret(string $plain, string $key): string
    {
        if ($plain === '') {
            return '';
        }
        $k = hash('sha256', $key, true);
        $nonce = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ct === false) {
            throw new \RuntimeException('密钥加密失败');
        }
        return 'v1:' . base64_encode($nonce . $tag . $ct);
    }

    /**
     * 商户密钥解密（与 encryptSecret 配对；非 v1: 前缀视为历史明文，原样返回）
     * @param string $blob
     * @param string $key
     * @return string 解密失败返回空串（不抛异常，避免密钥轮换后接口 500）
     */
    public static function decryptSecret(string $blob, string $key): string
    {
        if ($blob === '') {
            return '';
        }
        if (strpos($blob, 'v1:') !== 0) {
            return $blob; // 兼容明文存储（升级前的历史配置）
        }
        $raw = base64_decode(substr($blob, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $k = hash('sha256', $key, true);
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? '' : $plain;
    }

    /**
     * 参数按 key 升序拼 k=v&（剔除空值与 sign/sign_type）——支付宝签名/验签口径
     * @param array $params
     * @return string
     */
    public static function sortQuery(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $pairs[] = $k . '=' . $v;
        }
        sort($pairs, SORT_STRING);
        return implode('&', $pairs);
    }
}
