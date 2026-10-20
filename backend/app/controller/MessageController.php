<?php
// =====================================================================
// booking 后端 · 消息通知控制器（后台手工补发 / 模板预览）
// 自动发送由 MessageService 在订单状态流转处触发（下单/接单/完成）；
// 本控制器提供运维与验证入口：按触发点补发、渲染预览。
// =====================================================================

namespace app\controller;

use app\service\AuditService;
use app\service\EditionGate;
use app\service\MessageService;
use app\service\WechatNotifyService;
use think\facade\Db;
use think\Request;
use think\Response;

class MessageController
{
    /**
     * 手工补发（按触发点 + 订单号；用于客户反馈没收到时重发）
     * POST /api/v1/messages/send  {trigger, order_no?}
     */
    public function send(Request $request): Response
    {
        $trigger = clean_input($request->post('trigger', ''), 50);
        if ($trigger === '') {
            return err(400, 'trigger（触发点）不能为空');
        }
        $orderNo = clean_input($request->post('order_no', ''), 32);
        $order = [];
        if ($orderNo !== '') {
            $order = Db::name('booking')->where('order_no', $orderNo)->where('deleted_at', 0)->find() ?: [];
            if (!$order) {
                return err(404, '订单不存在');
            }
        }
        $res = (new MessageService())->send($trigger, $order);
        (new AuditService())->log(
            'message.send',
            $orderNo !== '' ? $orderNo : $trigger,
            ($res['sent'] ? '补发成功' : '补发跳过：' . $res['reason']) . '（' . $trigger . '）',
            (int)($request->adminId ?? 0)
        );
        if (!$res['sent']) {
            // 跳过原因属可预期业务结果（通道关闭/未配模板/超配额），返回 200 由前端提示
            return ok($res, '未发送：' . $res['reason']);
        }
        return ok($res, '已发送');
    }

    /**
     * 渲染预览（不落日志、不计数；用于后台核对模板变量是否漏配）
     * GET /api/v1/messages/preview?trigger=订单创建成功&order_no=BKxxxx
     */
    public function preview(Request $request): Response
    {
        $trigger = clean_input($request->get('trigger', ''), 50);
        if ($trigger === '') {
            return err(400, 'trigger（触发点）不能为空');
        }
        $tpl = Db::name('message_template')
            ->where('trigger', $trigger)->where('deleted_at', 0)
            ->order('id', 'asc')->find();
        if (!$tpl) {
            return err(404, '未配置「' . $trigger . '」消息模板');
        }
        $orderNo = clean_input($request->get('order_no', ''), 32);
        $order = $orderNo !== ''
            ? (Db::name('booking')->where('order_no', $orderNo)->where('deleted_at', 0)->find() ?: [])
            : [];
        $svc = new MessageService();
        return ok([
            'template_id' => (int)$tpl['id'],
            'name'        => (string)$tpl['name'],
            'channel'     => (string)$tpl['channel'],
            'scene'       => (string)$tpl['scene'],
            'raw'         => (string)$tpl['content'],
            'rendered'    => $svc->render((string)$tpl['content'], $order),
        ]);
    }

    /**
     * 触发点清单（后台补发下拉；含模板配置状态与已发送计数）
     * GET /api/v1/messages/triggers
     */
    public function triggers(Request $request): Response
    {
        $rows = Db::name('message_template')
            ->where('deleted_at', 0)
            ->order('id', 'asc')
            ->field('id,name,channel,trigger,scene,status,sent')
            ->select()->toArray();
        $switch = MessageService::CHANNEL_SWITCH;
        foreach ($rows as &$r) {
            $key = $switch[(string)$r['channel']] ?? '';
            $enabled = true;
            if ($key !== '') {
                $val = Db::name('setting')->where('key', $key)->where('deleted_at', 0)->value('value');
                $enabled = in_array((string)$val, ['1', 'true', 'on', 'yes'], true);
            }
            $r['channel_enabled'] = $enabled;
            $r['available'] = ((int)$r['status'] === 1) && $enabled;
        }
        unset($r);
        return ok(['list' => $rows]);
    }

    /**
     * 微信通知配置（读放行，入口可见；写走 message.wechat 版本门禁）
     * GET  /api/v1/messages/notify-config
     */
    public function notifyConfig(Request $request): Response
    {
        return ok((new WechatNotifyService())->publicView());
    }

    /**
     * 保存微信通知配置（真实推送为授权版功能）
     * POST /api/v1/messages/notify-config  {appid?,secret?,gateway?,mini_page?,mp_url?}
     */
    public function notifyConfigSave(Request $request): Response
    {
        $gate = new EditionGate();
        if (!$gate->assertFeature('message.wechat')) {
            $deny = $gate->lastDeny();
            return err(403, $deny['tip'] ?: '微信消息推送为授权版功能', [
                'upgrade' => [
                    'edition'  => $deny['edition'],
                    'need'     => $deny['need'],
                    'needName' => $deny['needName'],
                    'subject'  => 'message.wechat',
                    'tip'      => $deny['tip'],
                ],
            ]);
        }
        $svc = new WechatNotifyService();
        $view = $svc->save($request->post());
        (new AuditService())->log(
            'message.notify-config',
            '',
            '更新微信通知配置（appid=' . ($view['appid'] !== '' ? '已设' : '空') . '，secret=' . ($view['has_secret'] ? '已设' : '空') . '）',
            (int)($request->adminId ?? 0)
        );
        return ok($view, '保存成功');
    }

    /**
     * 微信通知链路自检（配置完整性 + access_token 可获取性；不发送真实消息）
     * POST /api/v1/messages/notify-test
     */
    public function notifyTest(Request $request): Response
    {
        $svc = new WechatNotifyService();
        if (!$svc->configured()) {
            return ok(['configured' => false, 'ok' => false, 'detail' => '未配置 appid/secret，当前为日志模式'], '未配置微信参数');
        }
        try {
            $svc->accessToken();
            return ok(['configured' => true, 'ok' => true, 'detail' => 'access_token 获取成功，链路通畅'], '自检通过');
        } catch (\Throwable $e) {
            return ok(['configured' => true, 'ok' => false, 'detail' => $e->getMessage()], '自检失败');
        }
    }
}
