-- =====================================================================
-- booking 预约上门服务系统 · 数据库脚本 02 · 服务与技师域
-- 承接：01_system.sql
-- 金额字段一律 INT 单位「分」（如 price=12800 即 ¥128.00），对齐《数据库规范》
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 服务分类（保洁/家电清洗/收纳/专项清洁…）
-- 索引理由：uk_name 分类名唯一；idx_sort 后台按排序值展示
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service_category` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '分类名称',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序值（越大越前）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`, `deleted_at`) COMMENT '分类名唯一，防重复录入',
  KEY `idx_sort` (`sort`) COMMENT '前台按排序值展示分类'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服务分类';

-- ---------------------------------------------------------------------
-- 服务项目（分类/价格/时长/图片/说明/销量）
-- 索引理由：idx_category_status 前台按分类+上架筛选；uk_name 同名服务防重复
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '服务名称（如 日常保洁 2 小时）',
  `category_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类 ID（逻辑外键 service_category.id）',
  `category` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '分类名（冗余，分类改名后订单仍可追溯）',
  `price` INT NOT NULL DEFAULT 0 COMMENT '单价，单位分（12800=¥128）',
  `duration` INT NOT NULL DEFAULT 0 COMMENT '服务时长（分钟）',
  `unit` VARCHAR(10) NOT NULL DEFAULT '次' COMMENT '计价单位：次 / 台 / 小时 / 平米',
  `covers` JSON NULL COMMENT '封面图 URL 列表',
  `detail` TEXT NULL COMMENT '服务详情（富文本 HTML）',
  `description` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '服务简介（列表页一句话）',
  `sales` INT NOT NULL DEFAULT 0 COMMENT '累计销量',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 上架 0 下架',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`, `deleted_at`) COMMENT '服务名唯一（含软删位）',
  KEY `idx_category_status` (`category_id`, `status`) COMMENT '前台按分类筛选+仅看上架，覆盖列表主查询'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服务项目';

-- ---------------------------------------------------------------------
-- 规格与加价项（材料费/配件费/楼层里程加价）
-- 索引理由：idx_service 按服务取规格列表
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service_spec` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `service_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '服务项目 ID（0=通用规格）',
  `service` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '服务名称（冗余展示）',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '规格/加价项名称',
  `type` VARCHAR(20) NOT NULL DEFAULT '规格' COMMENT '类型：规格 / 加价项',
  `price` INT NOT NULL DEFAULT 0 COMMENT '加价金额，单位分（可为 0）',
  `duration` INT NOT NULL DEFAULT 0 COMMENT '追加时长（分钟）',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序值',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_service` (`service_id`) COMMENT '按服务取规格/加价项列表',
  KEY `idx_type` (`type`) COMMENT '下单页按类型分组展示'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服务规格与加价项';

-- ---------------------------------------------------------------------
-- 服务区域（地图围栏/上门范围/超区提示/远程费）
-- 索引理由：idx_status 按启用状态取围栏做下单校验
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `service_area` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '区域名称',
  `cities` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '覆盖城市（逗号分隔）',
  `districts` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '覆盖行政区（逗号分隔）',
  `start_price` INT NOT NULL DEFAULT 0 COMMENT '起服价，单位分',
  `remote_fee` INT NOT NULL DEFAULT 0 COMMENT '超区远程费，单位分',
  `radius` INT NOT NULL DEFAULT 0 COMMENT '围栏半径（公里）',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序值',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`) COMMENT '下单校验按启用区域做围栏判断'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服务区域（上门范围）';

-- ---------------------------------------------------------------------
-- 时段差价与节假日价目（高峰加价/节日价；授权版+）
-- 计价模式：mode=倍率（value 存百分比 120=1.2 倍）/ 固定金额（value 存分）
-- 索引理由：idx_scope 按适用范围+日期类型取生效规则，priority 大者优先
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `price_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '规则名称（如 晚高峰加价）',
  `scope` VARCHAR(20) NOT NULL DEFAULT '全部服务' COMMENT '适用范围：全部服务 / 指定服务',
  `service_ids` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '指定服务 ID（逗号分隔，scope=指定服务时生效）',
  `date_type` VARCHAR(20) NOT NULL DEFAULT '每日' COMMENT '日期类型：每日 / 周末 / 节假日 / 指定日期',
  `date_range` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '指定日期范围（YYYY-MM-DD~YYYY-MM-DD）',
  `time_range` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '时段（HH:mm-HH:mm）',
  `mode` VARCHAR(20) NOT NULL DEFAULT '倍率' COMMENT '计价模式：倍率 / 固定金额',
  `value` INT NOT NULL DEFAULT 100 COMMENT '倍率百分比（120=1.2倍）或固定加价金额（分）',
  `priority` INT NOT NULL DEFAULT 0 COMMENT '优先级（大者优先叠加判定）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_scope` (`scope`, `date_type`, `status`) COMMENT '下单计价按范围+日期类型取生效规则'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='时段差价与节假日价目';

-- ---------------------------------------------------------------------
-- 技师（档案/技能标签/资质证书/服务范围）
-- 索引理由：uk_phone 手机号唯一（技师端登录）；idx_status 派单按在岗筛选
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `technician` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '姓名',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '手机号（技师端登录账号）',
  `password` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '登录密码（password_hash）',
  `avatar` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '头像 URL',
  `gallery` JSON NULL COMMENT '作品图列表',
  `skills` JSON NULL COMMENT '技能标签（["保洁","收纳"]）',
  `certs` JSON NULL COMMENT '资质证书（高空作业证等，[{name,expires}]）',
  `store_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属门店',
  `area_ids` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '可服务区域 ID（逗号分隔）',
  `rating` DECIMAL(2,1) NOT NULL DEFAULT 5.0 COMMENT '评分（1-5，评价均分）',
  `month_orders` INT NOT NULL DEFAULT 0 COMMENT '本月完成单量',
  `status` VARCHAR(20) NOT NULL DEFAULT '在岗' COMMENT '状态：在岗 / 服务中 / 休假 / 停用',
  `intro` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '个人简介（技师主页展示）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_phone` (`phone`, `deleted_at`) COMMENT '手机号唯一，技师端登录凭据',
  KEY `idx_status` (`status`, `store_id`) COMMENT '派单/预约页按在岗+门店筛选可约技师'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技师档案';

-- ---------------------------------------------------------------------
-- 技师自选项目（技师可接服务项目关联；竞品对标②「技师自选项目」全版本）
-- 索引理由：uk_pair 防重复关联；idx_tech 取技师可接项目
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `technician_service` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `technician_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '技师 ID',
  `service_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '服务项目 ID',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pair` (`technician_id`, `service_id`, `deleted_at`) COMMENT '同一技师同一项目只关联一次',
  KEY `idx_tech` (`technician_id`) COMMENT '取技师可接项目列表'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技师可接服务项目';

-- ---------------------------------------------------------------------
-- 排班与休息（班次模板/节假日/批量排班）
-- 索引理由：idx_tech_date 按技师+日期查排班（时段冲突校验核心索引）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schedule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `technician_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '技师 ID',
  `technician` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '技师姓名（冗余展示）',
  `date` DATE NOT NULL COMMENT '排班日期',
  `shift` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '班次（早班 09:00-13:00 / 全天 / 休息）',
  `start_at` DATETIME NULL DEFAULT NULL COMMENT '班次开始',
  `end_at` DATETIME NULL DEFAULT NULL COMMENT '班次结束',
  `is_rest` TINYINT NOT NULL DEFAULT 0 COMMENT '是否休息日：1 休息 0 排班',
  `status` VARCHAR(20) NOT NULL DEFAULT '已批准' COMMENT '审批状态：待审批 / 已批准 / 已驳回（请假申请用）',
  `note` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注（节假日/调休原因）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_tech_date` (`technician_id`, `date`) COMMENT '时段冲突校验与可约时段计算按技师+日期取班次'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='技师排班';

-- ---------------------------------------------------------------------
-- 考勤打卡与工时统计（授权版+）
-- 索引理由：idx_tech_date 查当日打卡；idx_date 工时统计按天聚合
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `technician_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '技师 ID',
  `technician` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '技师姓名（冗余展示）',
  `date` DATE NOT NULL COMMENT '考勤日期',
  `clock_in` VARCHAR(10) NOT NULL DEFAULT '' COMMENT '上班打卡 HH:mm',
  `clock_out` VARCHAR(10) NOT NULL DEFAULT '' COMMENT '下班打卡 HH:mm',
  `hours` DECIMAL(4,1) NOT NULL DEFAULT 0 COMMENT '工时（小时）',
  `orders` INT NOT NULL DEFAULT 0 COMMENT '当日完成单量',
  `status` VARCHAR(20) NOT NULL DEFAULT '正常' COMMENT '考勤状态：正常 / 迟到 / 早退 / 缺卡',
  `note` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_tech_date` (`technician_id`, `date`) COMMENT '查技师当日打卡记录',
  KEY `idx_date` (`date`) COMMENT '按天聚合工时与单量统计'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='考勤打卡';

-- ---------------------------------------------------------------------
-- 智能派单权重配置（距离/技能/负载/评分；授权版+，权重存配置而非写死）
-- 索引理由：idx_enabled 取生效权重参与派单评分
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dispatch_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `key` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '权重键：distance 距离 / skill 技能 / rating 评分 / load 负载 / complete 完成率',
  `label` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '权重名称',
  `weight` INT NOT NULL DEFAULT 0 COMMENT '权重百分比（distance=35 即 35%）',
  `desc` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '说明',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`, `deleted_at`) COMMENT '权重键唯一',
  KEY `idx_enabled` (`status`) COMMENT '取启用权重计算派单评分'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='智能派单权重';

-- ---------------------------------------------------------------------
-- 服务打卡与拍照留证（上门服务定位导航/打卡/拍照）
-- 索引理由：idx_order 按订单追溯打卡留证链
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_checkin` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `technician_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '技师 ID',
  `kind` VARCHAR(20) NOT NULL DEFAULT '到达打卡' COMMENT '类型：到达打卡 / 服务开始 / 服务完成 / 离开打卡',
  `lng` DECIMAL(10,6) NOT NULL DEFAULT 0 COMMENT '经度',
  `lat` DECIMAL(10,6) NOT NULL DEFAULT 0 COMMENT '纬度',
  `address` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '打卡地址（逆地理）',
  `images` JSON NULL COMMENT '拍照留证 URL 列表',
  `note` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `at` DATETIME NULL DEFAULT NULL COMMENT '打卡时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_no`) COMMENT '按订单追溯打卡与留证记录'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服务打卡与拍照留证';

SET FOREIGN_KEY_CHECKS = 1;
