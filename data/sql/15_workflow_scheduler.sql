-- =====================================================================
-- 15_workflow_scheduler.sql · 客户运营工作流 + 自动化营销定时调度
-- 背景（缺口盘点报告 P2 #8/#9）：
--   · 回访任务 / 投诉工单此前仅「通用 CRUD」，无负责人指派、无状态流转约束、
--     无到期提醒落库；状态可由前端随意改（如 待受理 → 已完成 跳级）。
--   · 自动化营销仅有手动 run，无定时触发，且重复执行会反复给同一批人发积分。
-- 变更：
--   1) 新增 workflow_log 流转日志表：记录 create/assign/transition 的
--      前态→后态、负责人、备注、操作人，供工单时间线与审计追溯。
--   2) auto_marketing_rule 增 last_run_date：定时任务按「同日同规则只跑一次」
--      做幂等，避免 cron 高频触发导致重复触达。
-- 说明：
--   重复执行报 duplicate key name / duplicate column 属正常
--   （InstallController BENIGN_SQL_ERRORS 已含 1061 / 1060）。
-- =====================================================================

-- ---------------------------------------------------------------------
-- 工作流流转日志（回访任务 / 投诉工单共用；resource 区分业务对象）
-- 索引理由：idx_resource_biz 工单详情按对象取时间线；idx_created 审计按时间检索
-- deleted_at 与全库统一（BIGINT 时间戳，0=未删）：便于测试卫生与历史归档
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `workflow_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `resource` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '业务对象：follow-up-tasks / complaints',
  `biz_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '业务对象 ID',
  `action` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '动作：create / assign / transition',
  `from_status` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '流转前状态',
  `to_status` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '流转后状态',
  `owner` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '本次涉及负责人',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注（处理结果/指派说明）',
  `operator` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '操作人',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_resource_biz` (`resource`, `biz_id`) COMMENT '工单详情取流转时间线',
  KEY `idx_created` (`created_at`) COMMENT '审计按时间检索'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流流转日志';

-- ---------------------------------------------------------------------
-- 自动化营销规则增「最近执行日期」：定时调度同日幂等
-- ---------------------------------------------------------------------
ALTER TABLE `auto_marketing_rule`
  ADD COLUMN `last_run_date` VARCHAR(10) NOT NULL DEFAULT '' COMMENT '最近执行日期 YYYY-MM-DD（同日幂等）' AFTER `sent`;
