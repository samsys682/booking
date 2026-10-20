-- =====================================================================
-- 17_service_agreement.sql · 电子服务协议（缺口补齐批次一，附四 #6）
-- 背景：
--   上门服务（按摩推拿/宠物洗护等）存在人身安全与免责风险，竞品均有
--   「下单前电子签约/知情同意书」能力。此前系统无协议模型，下单无留证，
--   出现纠纷时商家无任何已告知证据。
-- 变更：
--   新增 service_agreements 协议表（富文本 + 适用范围 + 版本号）；
--   新增 agreement_signs 签署流水表（只增不改：签署快照 + 一次性 token +
--   下单回填订单号），订单创建时校验「存在适用协议必须持有效签署 token」。
-- 说明：
--   重复执行报 duplicate key name（1061）属正常（InstallController 已含 1061）。
--   顺带补 technician_service.idx_service 索引（服务→技师反向视图反查路径，
--   修复原表只有 idx_tech 正向索引的缺口）。
-- =====================================================================

-- ---------------------------------------------------------------------
-- 服务协议（授权版+：开源版无配置入口，EditionGate service-agreements=pro）
-- 索引理由：uk_name 协议名唯一；idx_status 下单校验「启用协议」高频查询
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service_agreement` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '协议名称（如 上门服务知情同意书）',
  `content` MEDIUMTEXT NULL COMMENT '协议内容（富文本 HTML，顾客端下单前展示）',
  `scope` VARCHAR(20) NOT NULL DEFAULT '全部服务' COMMENT '适用范围：全部服务 / 指定服务',
  `service_ids` JSON NULL COMMENT '适用服务 ID 列表（scope=指定服务时生效）',
  `version` INT NOT NULL DEFAULT 1 COMMENT '协议版本号（签署快照记录版本，改内容需升版本）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`, `deleted_at`) COMMENT '协议名唯一（含软删位）',
  KEY `idx_status` (`status`, `deleted_at`) COMMENT '下单校验适用协议（启用态高频查询）'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='电子服务协议';

-- ---------------------------------------------------------------------
-- 协议签署流水（只增不改：留痕表，禁止 update 内容语义——仅 used/order_no 消费回写）
-- 索引理由：uk_sign_token 一次性凭证幂等；idx_member 会员签署历史
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `agreement_sign` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `sign_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '签署流水号（SG+yyyyMMddHHmmss+随机，幂等键）',
  `agreement_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '协议 ID',
  `agreement_name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '协议名称（签署快照）',
  `agreement_version` INT NOT NULL DEFAULT 1 COMMENT '协议版本号（签署快照）',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '签署会员 ID',
  `member` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '会员姓名（快照）',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '会员手机号（快照）',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联订单号（下单消费时回填，空=已签未下单）',
  `sign_token` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '一次性签署凭证（下单时校验，用后作废）',
  `used` TINYINT NOT NULL DEFAULT 0 COMMENT 'token 是否已消费：1 已用于下单 0 未使用',
  `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '签署时 IP（留证）',
  `at` DATETIME NULL DEFAULT NULL COMMENT '签署时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sign_no` (`sign_no`, `deleted_at`) COMMENT '签署流水号唯一，重复提交幂等',
  UNIQUE KEY `uk_sign_token` (`sign_token`, `deleted_at`) COMMENT '签署凭证唯一（一次性）',
  KEY `idx_member` (`member_id`, `created_at`) COMMENT '会员签署历史查询',
  KEY `idx_agreement` (`agreement_id`) COMMENT '按协议统计签署量'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='电子服务协议签署流水';

-- ---------------------------------------------------------------------
-- technician_service 补反向索引（服务→技师反查；原表仅有 idx_tech 正向）
-- 重复执行报 duplicate key name（1061）属正常
-- ---------------------------------------------------------------------
ALTER TABLE `technician_service` ADD INDEX `idx_service` (`service_id`) COMMENT '服务→技师反向视图反查';
