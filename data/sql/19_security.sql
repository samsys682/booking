-- =====================================================================
-- booking · 登录与账号安全（19_security.sql）
-- 版本：v1.21 ｜ 2026-10-20
-- 作用：《功能矩阵》附四#7「账号安全：密码策略 / 异地提醒 / 二次验证 / 失败锁定」。
--       ① admin_account 补「失败次数 + 锁定期止」两列——连续登录失败达阈值临时锁定；
--       ② setting(group=security) 补 5 个可配项——密码长度/字符类别数、
--          锁定阈值/时长、异地提醒开关（SecurityService 读取，禁止写死代码）。
-- 说明：二次验证（sensitive-verify）地基已在 AuthController 存在，本次不重复建表；
--       异地登录提醒复用 audit_log 留痕（IP 网段变化写一条，不阻断）。
--
-- 导入：新装按 InstallController::SQL_FILES 顺序自动导入；升级库先备份。
--       ALTER 报 duplicate column(1060)、INSERT 报 duplicate key(1062) 均属正常，BENIGN_SQL_ERRORS 放行。
-- 回滚：ALTER TABLE `admin_account` DROP COLUMN `fail_count`, DROP COLUMN `locked_until`;
--       DELETE FROM `setting` WHERE `group` = 'security';
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `admin_account`
  ADD COLUMN `fail_count` INT NOT NULL DEFAULT 0 COMMENT '连续登录失败次数（登录成功清零）' AFTER `last_login_ip`,
  ADD COLUMN `locked_until` DATETIME NULL DEFAULT NULL COMMENT '登录锁定期止（NULL 或已过期=未锁定）' AFTER `fail_count`;

-- 账号安全策略（group=security；后台「系统设置 → 账号安全」可改，SecurityService::config 读取）
INSERT IGNORE INTO `setting` (`group`, `key`, `value`, `type`, `remark`) VALUES
('security', 'pwd_min_length', '8', 'number', '密码最短长度（位）'),
('security', 'pwd_char_types', '1', 'number', '密码需包含的字符类别数（1=仅长度；2/3/4=小写·大写·数字·符号中至少几类）'),
('security', 'login_lock_threshold', '5', 'number', '连续登录失败达到此次数即锁定账号（0=不启用锁定）'),
('security', 'login_lock_minutes', '15', 'number', '账号锁定时长（分钟）'),
('security', 'login_remote_alert', '1', 'bool', '异地登录提醒开关（IP 网段变化写审计日志，不阻断登录）');
