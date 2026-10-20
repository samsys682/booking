-- =====================================================================
-- booking · 09 授权域 OA 验卡升级（v1.14，存量库执行；全新安装走 01 建表已含新列）
-- 变更：license 表新增 OA 验卡相关列（oa_license_id / device_id / oa_token /
--       features / last_verified_at）；card_key 仍不落库（验卡请求后即弃）
-- 执行：mysql -u<user> -p <db> -e "source 09_license_oa.sql"
-- 回滚：见文件尾注释（新列均可安全 DROP，不影响既有数据）
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `license`
  ADD COLUMN `oa_license_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'OA licenses 卡密 ID' AFTER `buyer`,
  ADD COLUMN `device_id` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '稳定设备指纹（域名+首管理员+库名哈希，验证绑定，禁前端传）' AFTER `oa_license_id`,
  ADD COLUMN `oa_token` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'OA 续验 token（verify 返回，不透明）' AFTER `device_id`,
  ADD COLUMN `features` TEXT NULL COMMENT 'OA features 模块开关（JSON 数组）' AFTER `oa_token`,
  ADD COLUMN `last_verified_at` DATETIME NULL DEFAULT NULL COMMENT '末次验卡/续验成功时间（断网宽限 7 天判断依据）' AFTER `features`;

-- 回滚脚本（如需）：
-- ALTER TABLE `license`
--   DROP COLUMN `last_verified_at`, DROP COLUMN `features`, DROP COLUMN `oa_token`,
--   DROP COLUMN `device_id`, DROP COLUMN `oa_license_id`;
