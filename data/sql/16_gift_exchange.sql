-- =====================================================================
-- 16_gift_exchange.sql · 积分商城兑换单（缺口盘点报告 P2 #10）
-- 背景：
--   积分商城此前只有「扣积分 + 扣库存 + point_log 流水」的即时动作，
--   兑换后无收货信息、无发货状态、无物流单号——商家无法履约，
--   会员无法查询「我的兑换」，兑换不成闭环（等于积分黑洞）。
-- 变更：
--   新增 gift_exchange 兑换单表：一次兑换一条单据，记录礼品快照、
--   消耗积分、收货信息与履约状态（待发货→已发货→已完成 / 已取消）。
--   point_log 仍为积分守恒唯一事实来源（兑换扣分、取消退分均落流水）。
-- 说明：
--   重复执行报 duplicate key name 属正常（InstallController 已含 1061）。
-- =====================================================================

-- ---------------------------------------------------------------------
-- 兑换单（授权版+：积分商城兑换后的履约载体）
-- 索引理由：idx_member 会员端「我的兑换」按时间倒序；idx_status 后台待发货列表
-- deleted_at 与全库统一（BIGINT 时间戳，0=未删）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gift_exchange` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `exchange_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '兑换单号（GE+yyyyMMdd+序号，幂等键）',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员 ID',
  `member` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '会员姓名（冗余展示）',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '会员手机号（冗余展示）',
  `gift_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '礼品 ID',
  `gift_name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '礼品名称（下单快照）',
  `points` INT NOT NULL DEFAULT 0 COMMENT '消耗积分合计，单位分（单价×数量）',
  `qty` INT NOT NULL DEFAULT 1 COMMENT '兑换数量',
  `contact` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '收货人',
  `contact_phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '收货电话',
  `address` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '收货地址（到店自提可空）',
  `status` VARCHAR(20) NOT NULL DEFAULT '待发货' COMMENT '状态：待发货 / 已发货 / 已完成 / 已取消',
  `express_no` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '物流单号（发货回填）',
  `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注（发货/取消说明）',
  `at` DATETIME NULL DEFAULT NULL COMMENT '兑换时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exchange_no` (`exchange_no`, `deleted_at`) COMMENT '兑换单号唯一，重复提交幂等',
  KEY `idx_member` (`member_id`, `created_at`) COMMENT '会员端我的兑换记录',
  KEY `idx_status` (`status`, `created_at`) COMMENT '后台待发货列表'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分商城兑换单';
