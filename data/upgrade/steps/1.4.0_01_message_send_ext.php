<?php
// =====================================================================
// 在线升级步骤 · 1.4.0_01 · message_send_log 增加 ext 列
//
// 背景：真实微信推送需要记录接口回执（msgid / errcode / 响应摘要），
//       供后台「消息发送记录」追溯「发出去了但客户没收到」这类问题。
//       旧表只有 status/reason，无法承载外部回执。
//
// 幂等：先查 information_schema，列已存在则跳过（升级可反复执行不报错）。
// 返回 callable，由 UpgradeService 调用；抛异常即视为该步骤失败。
// =====================================================================

return function () {
    $cols = \think\facade\Db::query(
        "SELECT COUNT(*) AS c FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'message_send_log' AND column_name = 'ext'"
    );
    if ((int)($cols[0]['c'] ?? 0) === 0) {
        \think\facade\Db::query(
            "ALTER TABLE `message_send_log` "
            . "ADD COLUMN `ext` VARCHAR(255) NOT NULL DEFAULT '' "
            . "COMMENT '外部回执（微信 msgid / 接口响应摘要）' AFTER `reason`"
        );
    }
};
