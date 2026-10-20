-- =====================================================================
-- booking · 路途费/打车费（18_travel_fee.sql）
-- 版本：v1.20 ｜ 2026-10-20
-- 作用：《功能矩阵》附五「打车费/路途费：按距离分段加收」（授权版+）。
--       ① service_area 补「区域中心坐标 + 免费半径 + 每公里费 + 封顶」——路费按
--          客户下单坐标到区域中心的实际距离(haversine)分段加收；
--       ② booking 补 travel_fee 追溯列（含在原价 total 内，同 surcharge 口径）。
-- 开源版不计路费（EditionGate travel.fee=pro），符合附五「开源版固定不计路费」。
--
-- 导入：新装按 InstallController::SQL_FILES 顺序自动导入；升级库先备份。
--       ALTER 报 duplicate column(1060) 属正常（已存在），BENIGN_SQL_ERRORS 放行。
-- 回滚：ALTER TABLE `service_area` DROP COLUMN `center_lng`, DROP COLUMN `center_lat`,
--         DROP COLUMN `free_km`, DROP COLUMN `per_km_fen`, DROP COLUMN `travel_cap_fen`;
--       ALTER TABLE `booking` DROP COLUMN `travel_fee`;
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `service_area`
  ADD COLUMN `center_lng` DECIMAL(10,6) NOT NULL DEFAULT 0 COMMENT '区域中心经度（路费距离起点；0=未配则不参与路费计算）' AFTER `districts`,
  ADD COLUMN `center_lat` DECIMAL(10,6) NOT NULL DEFAULT 0 COMMENT '区域中心纬度' AFTER `center_lng`,
  ADD COLUMN `free_km` INT NOT NULL DEFAULT 0 COMMENT '免费半径（公里），超出部分计费' AFTER `radius`,
  ADD COLUMN `per_km_fen` INT NOT NULL DEFAULT 0 COMMENT '超免费半径每公里加收，单位分' AFTER `free_km`,
  ADD COLUMN `travel_cap_fen` INT NOT NULL DEFAULT 0 COMMENT '路费封顶，单位分（0=不封顶）' AFTER `per_km_fen`;

ALTER TABLE `booking`
  ADD COLUMN `travel_fee` INT NOT NULL DEFAULT 0 COMMENT '路途费/打车费，单位分（含在 total 原价内，按距离分段加收，授权版+）' AFTER `surcharge`;
