-- =====================================================================
-- booking · 价格规则引擎（11_pricing_engine.sql）
-- 版本：v1.19 ｜ 2026-10-10
-- 作用：补齐「价格空壳」——① price_rule（时段差价 / 节假日价目）此前后台可配、
--       后端零消费，加价金额无处落库；② technician_service 无定价列，技师级
--       服务定价（后台已可填）落库即丢。本文件为两处补字段。
-- 口径来源：《功能矩阵》预约规则（时段差价与节假日价目）+ 技师（技师自选项目）
--
-- 导入方式：
--   新装：安装向导按 01→06→09→10→11 顺序自动导入（InstallController::SQL_FILES）
--   升级：执行本文件前先备份；ALTER 语句若报 duplicate column 属正常
--         （字段已存在），可忽略后继续——安装器 BENIGN_SQL_ERRORS 已放行 1060
-- 回滚：ALTER TABLE `booking` DROP COLUMN `price_rule`, DROP COLUMN `surcharge`;
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 订单表补加价追溯字段
--   surcharge 计入原价 total 内（total = 服务价 + surcharge），营销优惠在其后叠加
--   price_rule 记录命中的规则名称，便于对账与客服追溯
-- ---------------------------------------------------------------------
ALTER TABLE `booking`
  ADD COLUMN `surcharge` INT NOT NULL DEFAULT 0 COMMENT '时段/节假日加价，单位分（price_rule 叠加所得，含在原价 total 内）' AFTER `total`,
  ADD COLUMN `price_rule` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '命中的价格规则名称（多条逗号分隔，便于对账追溯）' AFTER `surcharge`;

-- ---------------------------------------------------------------------
-- 技师可接服务项目补定价列（技师级服务定价）
--   同一服务项目，不同技师可定不同价；未设置（0）时沿用服务项目标准价。
--   下单/加钟时由 OrderService::calcAmount 按「指定技师 + 该项目」取价覆盖。
-- ---------------------------------------------------------------------
ALTER TABLE `technician_service`
  ADD COLUMN `price` INT NOT NULL DEFAULT 0 COMMENT '技师该项目定价，单位分（0=沿用服务项目标准价）' AFTER `service_id`;
