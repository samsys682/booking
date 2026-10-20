<?php
// =====================================================================
// booking 后端 · 用量限额服务（四杠杆之一）
// 《功能矩阵》附三：限额一律外置 setting.quota 组配置，禁止写死代码
// 《功能矩阵》附一：触达 warn_rate(80%) 后台黄条提醒，100% 引导升级不硬断
// 用量实时统计（不落计数器，天然无漂移）
// =====================================================================

namespace app\service;

use think\facade\Db;

class QuotaService
{
    /** 限额项 → 用量统计方法映射 */
    const KEYS = ['stores_max', 'technicians_max', 'monthly_orders_max', 'wechat_messages_max'];

    /**
     * 全量限额用量（后台黄条/仪表盘用）
     * @return array 每项 {key, label, used, max, percent, warn, allowed}
     */
    public function usage(): array
    {
        $result = [];
        foreach (self::KEYS as $key) {
            $result[] = $this->check($key);
        }
        return $result;
    }

    /**
     * 单项限额检查
     * @param string $key 限额键（stores_max / technicians_max / monthly_orders_max / wechat_messages_max）
     * @return array {key, label, used, max, percent, warn, allowed}
     */
    public function check(string $key): array
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException('未知限额项：' . $key);
        }
        $max = $this->maxOf($key);
        $used = $this->usedOf($key);
        $warnRate = (int)$this->setting('warn_rate', '80');
        if ($warnRate <= 0 || $warnRate > 100) {
            $warnRate = 80;
        }
        // -1 = 不限（授权/源码版）
        $unlimited = ($max < 0);
        $percent = ($unlimited || $max === 0) ? 0 : (int)round($used * 100 / $max);
        return [
            'key'      => $key,
            'label'    => $this->label($key),
            'used'     => $used,
            'max'      => $max,
            'percent'  => $percent,
            'warn'     => !$unlimited && $percent >= $warnRate && $used < $max,
            'allowed'  => $unlimited || $used < $max,
        ];
    }

    /**
     * 超限断言（创建动作前调用）
     * @param string $key
     * @throws \RuntimeException 超出上限抛出（调用方转 err 响应，引导升级不硬断业务线）
     * @return void
     */
    public function assert(string $key): void
    {
        $info = $this->check($key);
        if (!$info['allowed']) {
            throw new \RuntimeException($info['label'] . '已达版本上限 ' . $info['max'] . '，请升级版本');
        }
    }

    /**
     * 限额项中文名
     * @param string $key
     * @return string
     */
    private function label(string $key): string
    {
        $map = [
            'stores_max'          => '门店数',
            'technicians_max'     => '技师数',
            'monthly_orders_max'  => '月订单量',
            'wechat_messages_max' => '微信通知条数',
        ];
        return $map[$key] ?? $key;
    }

    /**
     * 读限额上限（版本杠杆动态生效）
     * 以 license 表实时状态（effectiveEdition）取版本配额为唯一事实来源：
     * 授权版到期自动回落开源版口径（封死“到期不降级”的续费漏洞），
     * 续费后立即恢复；不读 setting.quota 的静态快照（激活残留值会误导配额）。
     * @param string $key
     * @return int -1=不限
     */
    private function maxOf(string $key): int
    {
        $edition = (new LicenseService())->effectiveEdition();
        $quota = LicenseService::EDITION_QUOTA[$edition] ?? LicenseService::EDITION_QUOTA['open'];
        return (int)($quota[$key] ?? -1);
    }

    /**
     * 实时用量统计
     * @param string $key
     * @return int
     */
    private function usedOf(string $key): int
    {
        switch ($key) {
            case 'stores_max':
                return (int)Db::name('store')->where('deleted_at', 0)->count();
            case 'technicians_max':
                return (int)Db::name('technician')->where('deleted_at', 0)->count();
            case 'monthly_orders_max':
                return (int)Db::name('booking')
                    ->where('deleted_at', 0)
                    ->where('created_at', '>=', date('Y-m-01 00:00:00'))
                    ->count();
            case 'wechat_messages_max':
                // 只计成功发送（'跳过' 行用于追溯「为何没发出」，不应占用配额）
                return (int)Db::name('message_send_log')
                    ->where('deleted_at', 0)
                    ->where('status', '成功')
                    ->where('created_at', '>=', date('Y-m-01 00:00:00'))
                    ->count();
        }
        return 0;
    }

    /**
     * 读 setting 值（带默认值）
     * @param string $key
     * @param string $default
     * @return string
     */
    private function setting(string $key, string $default = ''): string
    {
        try {
            $value = Db::name('setting')->where('key', $key)->value('value');
            return $value === null ? $default : (string)$value;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
