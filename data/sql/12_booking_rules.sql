-- =====================================================================
-- 12_booking_rules.sql · 预约规则域（爽约限制落地 + 门店营业时间）
-- 依据 docs/预约系统修改.md §阶段4「预约规则不全：提前预约时长/取消政策/爽约限制未成体系」
--   爽约限制此前只有 setting noshow_limit（无任何消费点）与前端面板（写幻影键），
--   本文件补齐数据模型，配合 OrderService::markNoshow / assertMemberCanBook 生效。
-- 幂等：全部 ALTER 走 InstallController::execSqlFile 的 benign 错误码白名单（1060 重复列）
-- =====================================================================

-- 会员爽约计数与下单冻结
--   noshow_count 为「月内」口径：跨月首次爽约时由 OrderService 重置为 1（noshow_month 记 YYYY-MM）
--   frozen_until 达上限后按 setting noshow_freeze_days 设置，NULL=未冻结
ALTER TABLE `member`
  ADD COLUMN `noshow_count` INT NOT NULL DEFAULT 0 COMMENT '本月爽约次数（客户未到场，跨月自动重置）' AFTER `last_order_at`,
  ADD COLUMN `noshow_month` VARCHAR(7) NOT NULL DEFAULT '' COMMENT '爽约计数的统计月份 YYYY-MM' AFTER `noshow_count`,
  ADD COLUMN `frozen_until` DATETIME NULL DEFAULT NULL COMMENT '下单冻结截止时间（爽约超限后设置，NULL=未冻结）' AFTER `noshow_month`;

-- 门店营业时间（Settings「门店信息」面板与门店管理共用；此前面板写 setting store.hours 幻影键）
ALTER TABLE `store`
  ADD COLUMN `hours` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '营业时间 HH:mm-HH:mm' AFTER `address`;
