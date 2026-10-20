-- =====================================================================
-- booking · 营销执行引擎（10_marketing_engine.sql）
-- 版本：v1.18 ｜ 2026-10-10
-- 作用：补齐「营销空壳」——活动参与下单计价、拼团、分销提现的执行载体
-- 口径来源：《功能矩阵》五章（会员与营销）+ 《开发计划》§2.6（付费转化设计）
--
-- 导入方式：
--   新装：安装向导按 01→06→09→10 顺序自动导入（InstallController::SQL_FILES）
--   升级：执行本文件前先备份；重复执行 CREATE 语句幂等，ALTER 语句若报
--         duplicate column 属正常（字段已存在），可忽略后继续
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. 订单表补活动追溯字段（活动价/拼团团单归属，订单可溯源到活动）
-- ---------------------------------------------------------------------
ALTER TABLE `booking`
  ADD COLUMN `activity_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '参与营销活动 ID（0=未参与）' AFTER `coupon_code`,
  ADD COLUMN `activity_name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '活动名称（冗余，活动改名后订单可追溯）' AFTER `activity_id`,
  ADD COLUMN `group_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '拼团团单 ID（0=非拼团）' AFTER `activity_name`;

-- ---------------------------------------------------------------------
-- 2. 活动表补限购与成团计数
-- ---------------------------------------------------------------------
ALTER TABLE `marketing_activity`
  ADD COLUMN `per_limit` INT NOT NULL DEFAULT 0 COMMENT '每人限购数量（0=不限）' AFTER `stock`,
  ADD COLUMN `joined` INT NOT NULL DEFAULT 0 COMMENT '已参团/已购人数（限购与成团判定）' AFTER `per_limit`;

-- ---------------------------------------------------------------------
-- 3. 拼团团单（开团/成团/失败退款的执行载体）
-- 索引理由：uk_group_no 团号唯一（参团幂等键）；idx_activity 按活动取团单；
--           idx_status_expire 超时未成团扫描（任务层）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `marketing_group` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `group_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '团号（GP+yyyyMMdd+序号）',
  `activity_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '活动 ID',
  `activity_name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '活动名称（冗余展示）',
  `leader_member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '团长会员 ID',
  `leader_name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '团长姓名（冗余展示）',
  `size` INT NOT NULL DEFAULT 0 COMMENT '成团所需人数',
  `joined` INT NOT NULL DEFAULT 1 COMMENT '当前参团人数（含团长）',
  `status` VARCHAR(20) NOT NULL DEFAULT '拼团中' COMMENT '状态：拼团中 / 已成团 / 已失败',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '团长订单号',
  `expire_at` DATETIME NULL DEFAULT NULL COMMENT '拼团截止 time（超时未成团自动失败退款）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_no` (`group_no`, `deleted_at`) COMMENT '团号唯一',
  KEY `idx_activity` (`activity_id`, `status`) COMMENT '按活动取进行中团单',
  KEY `idx_status_expire` (`status`, `expire_at`) COMMENT '超时未成团扫描'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拼团团单';

-- ---------------------------------------------------------------------
-- 4. 拼团参团记录（一单一记，成团/失败据此批量处理）
-- 索引理由：uk_group_member 同一团内同一会员只参一次（防重复参团）；
--           idx_order 按订单反查团单（退款联动）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `marketing_group_member` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `group_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '团单 ID',
  `group_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '团号（冗余）',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '参团会员 ID',
  `member` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '会员姓名（冗余展示）',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '参团订单号',
  `is_leader` TINYINT NOT NULL DEFAULT 0 COMMENT '是否团长（1=团长）',
  `status` VARCHAR(20) NOT NULL DEFAULT '已参团' COMMENT '状态：已参团 / 已成团 / 已退款',
  `joined_at` DATETIME NULL DEFAULT NULL COMMENT '参团时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_member` (`group_id`, `member_id`, `deleted_at`) COMMENT '同一团单同一会员仅一次',
  KEY `idx_order` (`order_no`) COMMENT '按订单反查团单'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拼团参团记录';

-- ---------------------------------------------------------------------
-- 5. 分销提现单（分销佣金 pending → withdrawn 的执行载体，留痕可审）
-- 索引理由：idx_distributor 按推广员取提现记录；idx_status 待审核列表
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `distribution_withdraw` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `distributor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '推广员 ID',
  `distributor` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '推广员姓名（冗余展示）',
  `amount` INT NOT NULL DEFAULT 0 COMMENT '提现金额，单位分（不得超过 pending）',
  `status` VARCHAR(20) NOT NULL DEFAULT '待审核' COMMENT '状态：待审核 / 已打款 / 已驳回',
  `channel` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '打款渠道：微信 / 支付宝 / 线下',
  `apply_at` DATETIME NULL DEFAULT NULL COMMENT '申请时间',
  `audit_at` DATETIME NULL DEFAULT NULL COMMENT '审核时间',
  `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注（驳回原因/打款流水）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_distributor` (`distributor_id`, `status`) COMMENT '按推广员取提现记录',
  KEY `idx_status` (`status`, `apply_at`) COMMENT '待审核提现列表'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分销提现单';

-- ---------------------------------------------------------------------
-- 6. 配置项种子（加购开关与活动总开关；限额外置，代码不写死）
-- ---------------------------------------------------------------------
INSERT INTO `setting` (`group`, `key`, `value`, `type`, `remark`) VALUES
('addon', 'addon.distribution', '0', 'number', '分销引擎加购开关（1=已加购解锁，三版本默认 0）'),
('marketing', 'marketing.activity_enabled', '1', 'number', '营销活动总开关（1=启用；关闭后下单不参与活动价）'),
('marketing', 'marketing.group_expire_hours', '24', 'number', '拼团有效期（小时，超时未成团自动失败退款）')
ON DUPLICATE KEY UPDATE `remark` = VALUES(`remark`);
