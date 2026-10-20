<?php
// =====================================================================
// booking 后端 · 消息通知服务（微信通道，2026-10-03 定案：不提供短信通道）
// 职责：模板匹配 → 通道开关 → 版本配额 → 变量渲染 → 真实投递 → 落发送日志 → 模板计数
//
// 设计说明：
//   1) 通道开关读 setting notify 组：小程序订阅消息 ← wechat_mini_enabled，
//      公众号模板消息 ← wechat_mp_enabled。
//   2) 配额复用 QuotaService（wechat_messages_max）；2026-10-10 产品决策为全版本不限条数，
//      但保留断言链路，便于将来按版本收紧。
//   3) 未发送也落一条 status='跳过'/'失败' 的记录（含原因），后台「消息发送记录」可追溯
//      「客户为什么没收到」；配额只统计 status='成功' 的行（见 QuotaService::usedOf）。
//   4) 真实投递：deliver() 调 WechatNotifyService 直连微信接口（小程序订阅消息/公众号模板消息）。
//      · 未配置微信参数 → 回落「日志模式」（保持既有行为，不阻塞业务）
//      · 版本门禁 message.wechat（授权版）未过 → 同样回落日志模式
//      · 投递失败 → status='失败'，原因写 reason，微信回执写 ext（列由在线升级引入）
// =====================================================================

namespace app\service;

use think\facade\Db;

class MessageService
{
    /** 通道 → 开关 setting 键（setting 组 notify） */
    const CHANNEL_SWITCH = [
        '小程序订阅消息' => 'wechat_mini_enabled',
        '公众号模板消息' => 'wechat_mp_enabled',
    ];

    /** 发送状态 */
    const STATUS_OK   = '成功';
    const STATUS_SKIP = '跳过';
    const STATUS_FAIL = '失败';

    /** message_send_log.ext 列存在性缓存（在线升级引入；未升级则降级不写） */
    private static ?bool $hasExt = null;

    /**
     * 按触发点发送订单消息
     * @param string $trigger 触发点（对齐 message_template.trigger，如「订单创建成功」）
     * @param array $order 订单行（用于渲染变量与关联 order_no）
     * @param array $vars 额外渲染变量（覆盖同名默认变量，如 有效时间/预计到达时间）
     * @return array {sent:bool, reason:string, log_id:int, template_id:int, content:string, channel:string, mode?:string}
     */
    public function send(string $trigger, array $order = [], array $vars = []): array
    {
        try {
            $tpl = Db::name('message_template')
                ->where('trigger', $trigger)
                ->where('status', 1)
                ->where('deleted_at', 0)
                ->order('id', 'asc')
                ->find();
        } catch (\Throwable $e) {
            return $this->result(false, '消息模板表不可用：' . $e->getMessage(), 0, 0, '', '');
        }
        if (!$tpl) {
            return $this->result(false, '未配置「' . $trigger . '」消息模板', 0, 0, '', '');
        }
        $channel = (string)$tpl['channel'];

        // ① 通道开关（小程序/公众号各自独立）
        $switchKey = self::CHANNEL_SWITCH[$channel] ?? '';
        if ($switchKey !== '' && !$this->settingBool($switchKey, true)) {
            return $this->log($tpl, $order, $vars, self::STATUS_SKIP, '通道已关闭：' . $channel . '（setting ' . $switchKey . '）');
        }

        // ② 版本配额
        try {
            (new QuotaService())->assert('wechat_messages_max');
        } catch (\RuntimeException $e) {
            return $this->log($tpl, $order, $vars, self::STATUS_SKIP, $e->getMessage());
        }

        // ③ 渲染 + 真实投递 + 落日志
        return $this->log($tpl, $order, $vars, self::STATUS_OK, '');
    }

    /**
     * 渲染变量表（{{变量名}} → 值）
     * @param array $order 订单行
     * @param array $vars  额外变量（覆盖同名默认值）
     * @return array<string,string>
     */
    public function variables(array $order = [], array $vars = []): array
    {
        $map = [
            '订单号'       => (string)($order['order_no'] ?? ''),
            '服务项目'     => (string)($order['service'] ?? ''),
            '服务时间'     => (string)($order['start_at'] ?? ''),
            '技师姓名'     => (string)($order['technician'] ?? ''),
            '客户姓名'     => (string)($order['customer'] ?? ''),
            '客户电话'     => (string)($order['phone'] ?? ''),
            '服务地址'     => (string)($order['address'] ?? ''),
            '订单金额'     => isset($order['actual']) ? fen_to_yuan((int)$order['actual']) : '',
            '预计到达时间' => (string)($order['start_at'] ?? ''),
            '门店名称'     => $this->setting('site_name', ''),
            '客服电话'     => $this->setting('service_phone', ''),
            '品牌名称'     => $this->setting('brand_name', '') ?: $this->setting('site_name', ''),
        ];
        foreach ($vars as $k => $v) {
            if (is_scalar($v)) {
                $map[(string)$k] = (string)$v;
            }
        }
        return $map;
    }

    /**
     * 渲染模板内容（{{变量}} 替换；未命中变量原样保留，便于后台发现漏配）
     * @param string $content
     * @param array $order
     * @param array $vars
     * @return string
     */
    public function render(string $content, array $order = [], array $vars = []): string
    {
        foreach ($this->variables($order, $vars) as $k => $v) {
            $content = str_replace('{{' . $k . '}}', $v, $content);
        }
        return $content;
    }

    /**
     * 落发送日志 + 成功时递增模板 sent 计数
     * 成功态先经 deliver() 真实投递；投递失败则降级为 '失败' 并记原因/回执。
     * @param array $tpl
     * @param array $order
     * @param array $vars
     * @param string $status 成功 / 跳过
     * @param string $reason 跳过原因
     * @return array
     */
    private function log(array $tpl, array $order, array $vars, string $status, string $reason): array
    {
        $content = $this->render((string)$tpl['content'], $order, $vars);
        $target = (string)($vars['target'] ?? $order['phone'] ?? $order['customer'] ?? '');
        $orderNo = (string)($order['order_no'] ?? ($vars['order_no'] ?? ''));
        $mode = 'log';
        $ext = '';
        // 真实投递（仅「成功」态需要外发；跳过态不外发）
        if ($status === self::STATUS_OK) {
            $res = $this->deliver($tpl, $content, $target, $order);
            $mode = (string)($res['mode'] ?? 'log');
            $ext = (string)($res['ext'] ?? '');
            if (empty($res['ok'])) {
                $status = self::STATUS_FAIL;
                $reason = (string)($res['reason'] ?? '投递失败');
            }
        }
        $logId = 0;
        try {
            $row = [
                'template_id' => (int)$tpl['id'],
                'template'    => (string)$tpl['name'],
                'channel'     => (string)$tpl['channel'],
                'target'      => mb_substr($target, 0, 50),
                'order_no'    => $orderNo,
                'content'     => mb_substr($content, 0, 500),
                'status'      => $status,
                'reason'      => mb_substr($reason, 0, 255),
                'at'          => date('Y-m-d H:i:s'),
                'created_at'  => date('Y-m-d H:i:s'),
            ];
            if ($this->hasExtColumn()) {
                $row['ext'] = mb_substr($ext, 0, 255);
            }
            $logId = (int)Db::name('message_send_log')->insertGetId($row);
            if ($status === self::STATUS_OK) {
                Db::name('message_template')->where('id', (int)$tpl['id'])->inc('sent')->update([
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            // 落日志失败不影响业务主流程
        }
        return $this->result(
            $status === self::STATUS_OK,
            $reason,
            $logId,
            (int)$tpl['id'],
            $content,
            (string)$tpl['channel'],
            $mode
        );
    }

    /**
     * 真实投递出口（微信接口）
     * 降级链：未配置微信参数 / 版本门禁未过 → 回落「日志模式」（ok=true, mode=log，保持既有行为）。
     * @param array  $tpl
     * @param string $content 已渲染文本（供回执摘要）
     * @param string $target  发送对象（手机号/姓名，仅日志用）
     * @param array  $order   订单行（解析 openid 与字段映射）
     * @return array {ok:bool, reason:string, mode:string, ext:string}
     */
    public function deliver(array $tpl, string $content, string $target, array $order = []): array
    {
        $wx = new WechatNotifyService();
        if (!$wx->configured()) {
            // 未配置微信参数：保持日志模式（历史行为），不视为失败
            return ['ok' => true, 'reason' => '', 'mode' => 'log', 'ext' => ''];
        }
        // 版本门禁：真实推送为授权版功能；未升级回落日志模式
        $gate = new EditionGate();
        if (!$gate->assertFeature('message.wechat')) {
            $deny = $gate->lastDeny();
            return ['ok' => true, 'reason' => '', 'mode' => 'log', 'ext' => (string)($deny['tip'] ?? '')];
        }
        return $wx->send($tpl, $content, $order);
    }

    private function result(bool $sent, string $reason, int $logId, int $tplId, string $content, string $channel, string $mode = 'log'): array
    {
        return [
            'sent'        => $sent,
            'reason'      => $reason,
            'log_id'      => $logId,
            'template_id' => $tplId,
            'content'     => $content,
            'channel'     => $channel,
            'mode'        => $mode,
        ];
    }

    /** message_send_log.ext 列是否存在（在线升级引入；未升级则降级不写该列） */
    private function hasExtColumn(): bool
    {
        if (self::$hasExt !== null) {
            return self::$hasExt;
        }
        try {
            $rows = Db::query(
                "SELECT COUNT(*) AS c FROM information_schema.columns "
                . "WHERE table_schema = DATABASE() AND table_name = 'message_send_log' AND column_name = 'ext'"
            );
            self::$hasExt = (int)($rows[0]['c'] ?? 0) > 0;
        } catch (\Throwable $e) {
            self::$hasExt = false;
        }
        return self::$hasExt;
    }

    /** 读 setting 布尔（缺省返回 $default） */
    private function settingBool(string $key, bool $default = false): bool
    {
        $raw = $this->setting($key, $default ? '1' : '0');
        return in_array($raw, ['1', 'true', 'on', 'yes'], true);
    }

    /** 读 setting 值（带默认值，表不可用时不抛） */
    private function setting(string $key, string $default = ''): string
    {
        try {
            $value = Db::name('setting')->where('key', $key)->where('deleted_at', 0)->value('value');
            return $value === null ? $default : (string)$value;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
