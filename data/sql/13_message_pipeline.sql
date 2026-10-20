-- =====================================================================
-- 13_message_pipeline.sql · 消息通知管线
-- 背景：message_template / message_send_log 此前无任何发送出口，
--       notify 组设置（wechat_mp_enabled / wechat_mini_enabled）无消费点。
--       本文件补齐「发送记录可追溯」所需字段，配合 MessageService 生效。
-- 幂等：ALTER 走 InstallController::execSqlFile 的 benign 错误码白名单（1060 重复列）
-- =====================================================================

-- 发送内容留痕：模板渲染后的实际文案（便于客户反馈「没收到」时逐条核对）
ALTER TABLE `message_send_log`
  ADD COLUMN `content` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '实际发送内容（模板渲染后）' AFTER `order_no`;

-- 触发点索引：后台按触发点补发/统计
ALTER TABLE `message_send_log`
  ADD KEY `idx_template_created` (`template_id`, `created_at`);
