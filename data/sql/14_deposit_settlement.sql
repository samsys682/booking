-- =====================================================================
-- 14_deposit_settlement.sql · 定金尾款结算（deposit_enabled 开关生效）
-- 背景：deposit 表此前仅后台 CRUD 列表，deposit_enabled 开关无消费点。
--       本次让「定金/尾款」成为下单→收款→结清的真实业务链路：
--       下单落定金行 → 支付定金推进订单 → 落尾款行 → 支付尾款结清 → 取消/退款一并置已退款。
-- 变更：
--   1) deposit 增 (order_no, kind) 联合索引：payableFor / rowOf / settle 按订单+类型高频查询
-- 说明：
--   定金比例 setting 键 booking.deposit_rate 见 06_seed.sql（INSERT IGNORE，重跑安装器即可回填）
--   重复执行报 duplicate key name 属正常（InstallController BENIGN_SQL_ERRORS 已含 1061）
-- =====================================================================

ALTER TABLE `deposit`
  ADD KEY `idx_order_kind` (`order_no`, `kind`);
