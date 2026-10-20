<?php
// =====================================================================
// 在线升级步骤 · 1.4.0_02 · message_template 增加微信模板映射列
//
// 背景：真实微信推送需要两项模板侧配置：
//   · wx_template_id  微信公众平台申请到的模板 ID（订阅消息/模板消息）
//   · wx_data_map     字段映射 JSON，如 {"thing1":"服务项目","time2":"服务时间"}
//                     把平台模板字段名 → 本系统变量名对上，才能组装 data 报文。
//
// 幂等：逐列查 information_schema，已存在则跳过。
// =====================================================================

use think\facade\Db;

return function () {
    $existing = [];
    foreach (Db::query(
        "SELECT column_name FROM information_schema.columns "
        . "WHERE table_schema = DATABASE() AND table_name = 'message_template'"
    ) as $c) {
        $existing[strtolower((string)$c['column_name'])] = true;
    }
    if (!isset($existing['wx_template_id'])) {
        Db::query(
            "ALTER TABLE `message_template` "
            . "ADD COLUMN `wx_template_id` VARCHAR(64) NOT NULL DEFAULT '' "
            . "COMMENT '微信模板 ID（订阅消息/模板消息，空=未接入）' AFTER `content`"
        );
    }
    if (!isset($existing['wx_data_map'])) {
        Db::query(
            "ALTER TABLE `message_template` "
            . "ADD COLUMN `wx_data_map` VARCHAR(500) NOT NULL DEFAULT '' "
            . "COMMENT '微信字段映射 JSON：{\"thing1\":\"服务项目\",\"time2\":\"服务时间\"}' AFTER `wx_template_id`"
        );
    }
};
