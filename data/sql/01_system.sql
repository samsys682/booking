-- =====================================================================
-- booking 预约上门服务系统 · 数据库脚本 01 · 系统域
-- 依据：《开发规范》数据库设计规范（snake_case / id+created_at+updated_at+deleted_at /
--       金额 int 单位「分」/ 枚举配注释 / 索引写设计理由）
-- 软删约定：deleted_at BIGINT NOT NULL DEFAULT 0（0=未删除，删除写 Unix 时间戳）。
--           不用 DATETIME NULL 是为了让唯一索引包含 deleted_at 时仍能生效
--           （MySQL 唯一索引对 NULL 不去重，DATETIME NULL 会导致软删后同名重建冲突——
--           规范风险清单「软删除与唯一索引冲突」的指定解法）。
-- 执行顺序：01_system → 02_service → 03_trade → 04_member → 05_seed
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 管理员账号（老板/店长/前台/财务，细粒度 RBAC 依附 role）
-- 索引理由：登录按 account 查询（uk_account），按门店筛选列表（idx_store）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '姓名',
  `account` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '登录账号',
  `password` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '密码（password_hash）',
  `role_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '角色 ID（逻辑外键 role.id）',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '手机号',
  `store_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属门店（0=总部/不限）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `last_login_at` DATETIME NULL DEFAULT NULL COMMENT '最近登录时间',
  `last_login_ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '最近登录 IP',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account` (`account`, `deleted_at`) COMMENT '登录账号唯一（含软删位防冲突）',
  KEY `idx_store` (`store_id`) COMMENT '按门店筛选管理员列表'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员账号';

-- ---------------------------------------------------------------------
-- 角色（老板/店长/前台/财务/自定义）
-- 索引理由：code 唯一用于授权卡密与初始化脚本引用
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `role` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '角色名称',
  `code` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '角色编码（owner/manager/front/finance/custom）',
  `desc` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '角色说明',
  `is_system` TINYINT NOT NULL DEFAULT 0 COMMENT '是否内置角色：1 是 0 否（内置禁删）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`, `deleted_at`) COMMENT '角色编码唯一，供权限初始化与 API 引用'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色';

-- ---------------------------------------------------------------------
-- 角色权限（RBAC 角色-权限码关联）
-- 索引理由：按角色取权限集合（idx_role）；(role_id, perm) 唯一防重复授权
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `role_permission` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `role_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '角色 ID',
  `perm` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '权限码（如 booking.view / order.edit / finance.export）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_perm` (`role_id`, `perm`, `deleted_at`) COMMENT '同一角色同一权限码只授权一次',
  KEY `idx_role` (`role_id`) COMMENT '按角色批量取权限'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色权限关联';

-- ---------------------------------------------------------------------
-- 系统设置（键值对，含预约规则/取消政策/限额配置/品牌权益）
-- 限额配置（stores/technicians/monthly_orders/wechat_messages）落此表，
-- 《功能矩阵》附三：限额外置配置、禁止写死代码。
-- 索引理由：uk_key 按键读取配置；idx_group 后台分组展示
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `setting` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `group` VARCHAR(50) NOT NULL DEFAULT 'basic' COMMENT '配置分组：basic 基础 / booking 预约规则 / quota 限额 / brand 品牌 / notify 通知',
  `key` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '配置键',
  `value` TEXT NULL COMMENT '配置值（复杂结构存 JSON）',
  `type` VARCHAR(20) NOT NULL DEFAULT 'text' COMMENT '值类型：text 文本 / number 数字 / bool 开关 / json JSON',
  `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '配置说明（后台表单 hint）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`, `deleted_at`) COMMENT '配置键唯一，读取按 key 精确定位',
  KEY `idx_group` (`group`) COMMENT '后台按分组拉取配置表单'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置（含限额/预约规则/品牌配置）';

-- ---------------------------------------------------------------------
-- 操作日志/审计（谁/何时/对什么/做了什么，敏感操作留痕）
-- 保留时长按版本：开源 7 天 / 授权、源码 1 年（清理任务按 setting 配置执行）
-- 索引理由：按操作人查（idx_admin）、按时间范围清理与查询（idx_created）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `admin_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人（admin_account.id）',
  `admin_name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '操作人姓名（冗余，账号删除后仍可追溯）',
  `module` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '模块：order/member/service/finance/system 等',
  `action` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '动作：create/update/delete/login/export/refund 等',
  `target` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '操作对象（表名+ID 或单号）',
  `content` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '变更摘要（改动前后关键字段）',
  `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '操作 IP',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`, `created_at`) COMMENT '按操作人+时间倒序查历史',
  KEY `idx_created` (`created_at`) COMMENT '按保留策略批量清理（开源 7 天/授权 1 年）'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志/审计';

-- ---------------------------------------------------------------------
-- 数据备份记录（备份文件落 data/backups，不入代码仓库）
-- 索引理由：按时间倒序展示（idx_at）；keep=1 的备份自动清理任务跳过
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `backup` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '备份文件名（backup_manual_*.sql.gz）',
  `kind` VARCHAR(20) NOT NULL DEFAULT '全量' COMMENT '备份类型：全量 / 增量',
  `size` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '文件大小（如 18.6 MB）',
  `tables` INT NOT NULL DEFAULT 0 COMMENT '包含表数量',
  `at` DATETIME NULL DEFAULT NULL COMMENT '备份时间',
  `operator` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '操作人',
  `status` VARCHAR(20) NOT NULL DEFAULT '成功' COMMENT '执行结果：成功 / 失败',
  `keep` TINYINT NOT NULL DEFAULT 0 COMMENT '长期保留：1 保留（清理任务跳过）0 按策略清理',
  `note` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注（失败原因/手动备份标记）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_at` (`at`) COMMENT '按备份时间倒序展示与按保留天数清理'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据备份记录';

-- ---------------------------------------------------------------------
-- 版本升级记录（在线升级/补录，失败自动回滚）
-- 索引理由：按升级时间倒序展示（idx_at）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `upgrade_record` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `from_version` VARCHAR(15) NOT NULL DEFAULT '' COMMENT '原版本（v1.2.0）',
  `to_version` VARCHAR(15) NOT NULL DEFAULT '' COMMENT '目标版本（v1.3.0）',
  `at` DATETIME NULL DEFAULT NULL COMMENT '升级时间',
  `operator` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '操作人',
  `duration` VARCHAR(15) NOT NULL DEFAULT '' COMMENT '耗时（如 2 分 14 秒）',
  `status` VARCHAR(20) NOT NULL DEFAULT '成功' COMMENT '升级结果：成功 / 失败',
  `note` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '变更说明（失败原因记此处）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_at` (`at`) COMMENT '按升级时间倒序展示'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='版本升级记录';

-- ---------------------------------------------------------------------
-- 安全事件（一键报警/超时滞留预警/行程分享/隐私号通话，上门安全留痕）
-- 索引理由：按时间倒序展示（idx_at）；按技师追溯（idx_tech）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `security_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `kind` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '事件类型：一键报警 / 超时滞留预警 / 行程分享 / 隐私号通话',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联订单号',
  `technician` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '技师姓名',
  `customer` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '客户姓名',
  `at` DATETIME NULL DEFAULT NULL COMMENT '发生时间',
  `detail` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '事件详情',
  `status` VARCHAR(20) NOT NULL DEFAULT '正常' COMMENT '处理状态：正常 / 已处理',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_at` (`at`) COMMENT '按时间倒序展示与统计',
  KEY `idx_tech` (`technician`) COMMENT '按技师追溯安全记录'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='上门安全事件';

-- ---------------------------------------------------------------------
-- 门店（多门店为授权版 3 店/源码版不限，开源版 1 店——限额读 setting.quota）
-- 索引理由：code 唯一（总部固定 HQ，禁删逻辑在应用层）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `store` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '门店名称',
  `code` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '门店编码（总部=HQ）',
  `manager` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '负责人',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `address` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '门店地址',
  `cross_store` TINYINT NOT NULL DEFAULT 0 COMMENT '次卡/储值跨店通兑：1 是 0 否',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 营业 0 停业',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`, `deleted_at`) COMMENT '门店编码唯一，供订单/技师归属引用'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店';

-- ---------------------------------------------------------------------
-- 消息模板（微信两通道：小程序订阅消息/公众号模板消息；短信按产品决策全线不提供）
-- 微信通知不限条数（2026-10-10 决策）；发送量按月统计，发送前由 QuotaService 校验其他限额（读 setting.quota）
-- 索引理由：按通道+场景筛选（idx_channel_scene）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `message_template` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '模板名称（如 预约成功通知）',
  `channel` VARCHAR(30) NOT NULL DEFAULT '小程序订阅消息' COMMENT '发送通道：小程序订阅消息 / 公众号模板消息（短信不提供）',
  `trigger` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '触发场景（如 订单创建成功）',
  `scene` VARCHAR(20) NOT NULL DEFAULT '订单' COMMENT '业务分类：订单 / 营销',
  `content` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '模板内容（{{变量名}} 占位，发送时按订单数据填充）',
  `wx_template_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '微信模板 ID（订阅消息/模板消息，空=未接入真实推送）',
  `wx_data_map` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '微信字段映射 JSON：{"thing1":"服务项目","time2":"服务时间"}',
  `sent` INT NOT NULL DEFAULT 0 COMMENT '累计已发送条数',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_channel_scene` (`channel`, `scene`) COMMENT '后台按通道与业务分类筛选模板'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信消息模板';

-- ---------------------------------------------------------------------
-- 消息发送记录（失败原因留痕；月度条数统计供限额校验）
-- 索引理由：idx_created 按月统计条数（限额）与查询；idx_status 查失败记录重发
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `message_send_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `template_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '模板 ID',
  `template` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '模板名称（冗余，模板删除后可追溯）',
  `channel` VARCHAR(30) NOT NULL DEFAULT '小程序订阅消息' COMMENT '发送通道',
  `target` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '接收人（昵称/手机号）',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联订单号',
  `status` VARCHAR(20) NOT NULL DEFAULT '成功' COMMENT '发送状态：成功 / 跳过（未外发） / 失败（外发被拒）',
  `reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '跳过/失败原因',
  `ext` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '外部回执（微信 msgid / 接口响应摘要）',
  `at` DATETIME NULL DEFAULT NULL COMMENT '发送时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`) COMMENT '按月 COUNT 统计微信通知发送量（不限条数）',
  KEY `idx_status` (`status`) COMMENT '查失败记录批量重发'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息发送记录';

-- ---------------------------------------------------------------------
-- 授权卡密（OA licenses，见《开发计划》§1.10；版本：opensource/pro/source）
-- 索引理由：uk_card_no 卡密唯一激活一次；idx_edition 查当前授权
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `license` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `card_no` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '卡号（OA licenses 发行；card_key 绝不落库）',
  `edition` VARCHAR(20) NOT NULL DEFAULT 'opensource' COMMENT '版本：opensource 开源版 / pro 授权版 / source 源码版',
  `buyer` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '购买人/企业',
  `oa_license_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'OA licenses 卡密 ID',
  `device_id` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '稳定设备指纹（域名+首管理员+库名哈希，验证绑定，禁前端传）',
  `oa_token` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'OA 续验 token（verify 返回，不透明）',
  `features` TEXT NULL COMMENT 'OA features 模块开关（JSON 数组）',
  `last_verified_at` DATETIME NULL DEFAULT NULL COMMENT '末次验卡/续验成功时间（断网宽限 7 天判断依据）',
  `activated_at` DATETIME NULL DEFAULT NULL COMMENT '激活时间',
  `expires_at` DATETIME NULL DEFAULT NULL COMMENT '到期时间（源码版买断可为空）',
  `status` VARCHAR(20) NOT NULL DEFAULT '未激活' COMMENT '状态：未激活 / 生效中 / 已过期 / 已禁用',
  `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card_no` (`card_no`, `deleted_at`) COMMENT '卡号唯一，防止重复激活',
  KEY `idx_edition` (`edition`, `status`) COMMENT '查当前生效授权与版本权益'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授权卡密';

SET FOREIGN_KEY_CHECKS = 1;
