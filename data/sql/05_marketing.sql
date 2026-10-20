-- =====================================================================
-- booking 预约上门服务系统 · 数据库脚本 05 · 营销与客户运营域
-- 承接：04_member.sql
-- 营销套件（拼团/秒杀/积分/推荐有礼）为授权版+；评价基础功能全版本
-- 并发防线：营销活动库存扣减走 UPDATE ... WHERE stock > 0 原子操作，禁止读改写
-- 金额一律 INT 分
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 营销活动（拼团/秒杀/限时折扣；授权版+）
-- 索引理由：idx_status_time 前台取进行中活动；并发库存以 stock>0 条件更新
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `marketing_activity` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '活动名称',
  `kind` VARCHAR(20) NOT NULL DEFAULT '限时折扣' COMMENT '活动类型：拼团 / 秒杀 / 限时折扣',
  `target` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '适用对象（服务名称）',
  `service_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '适用服务 ID',
  `price` INT NOT NULL DEFAULT 0 COMMENT '活动价，单位分',
  `origin_price` INT NOT NULL DEFAULT 0 COMMENT '原价，单位分',
  `group_size` INT NOT NULL DEFAULT 0 COMMENT '拼团人数（拼团活动）',
  `sold` INT NOT NULL DEFAULT 0 COMMENT '已售数量',
  `stock` INT NOT NULL DEFAULT 0 COMMENT '库存（扣减走 stock>0 原子更新防超卖）',
  `start_at` DATETIME NULL DEFAULT NULL COMMENT '开始时间',
  `end_at` DATETIME NULL DEFAULT NULL COMMENT '结束时间',
  `status` VARCHAR(20) NOT NULL DEFAULT '未开始' COMMENT '状态：未开始 / 进行中 / 已结束',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_status_time` (`status`, `start_at`, `end_at`) COMMENT '前台取进行中活动、后台活动列表'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='营销活动';

-- ---------------------------------------------------------------------
-- 推荐有礼规则（老带新奖励/留资转化；授权版+）
-- 索引理由：uk_name 规则名唯一；idx_status 取生效规则发奖
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `referral_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '规则名称',
  `inviter_reward` INT NOT NULL DEFAULT 0 COMMENT '邀请人奖励（积分）',
  `invitee_reward` INT NOT NULL DEFAULT 0 COMMENT '被邀请人奖励（积分）',
  `condition` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '触发条件（如 被邀请人完成首单）',
  `issued` INT NOT NULL DEFAULT 0 COMMENT '累计发放奖励次数',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`, `deleted_at`) COMMENT '规则名唯一',
  KEY `idx_status` (`status`) COMMENT '取生效规则发奖'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推荐有礼规则';

-- ---------------------------------------------------------------------
-- 推荐关系与奖励流水
-- 索引理由：idx_inviter 查邀请人下线；uk_invitee 被邀请人只归属一个邀请人
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `referral_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `inviter_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '邀请人会员 ID',
  `inviter` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '邀请人姓名',
  `invitee_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '被邀请人会员 ID',
  `invitee` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '被邀请人姓名',
  `reward` INT NOT NULL DEFAULT 0 COMMENT '发放奖励积分',
  `status` VARCHAR(20) NOT NULL DEFAULT '待发放' COMMENT '状态：待发放 / 已发放 / 已退回',
  `detail` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '奖励说明',
  `at` DATETIME NULL DEFAULT NULL COMMENT '发生时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_inviter` (`inviter_id`) COMMENT '查邀请人下线与奖励统计',
  UNIQUE KEY `uk_invitee` (`invitee_id`, `deleted_at`) COMMENT '被邀请人只归属一个邀请人（防重复邀请刷奖）'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推荐关系与奖励';

-- ---------------------------------------------------------------------
-- 积分规则（获取/抵扣/兑换；授权版+）
-- 索引理由：idx_kind 按类型取规则；uk_name 规则名唯一
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `point_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '规则名称（如 消费 1 元积 1 分）',
  `kind` VARCHAR(20) NOT NULL DEFAULT '获取' COMMENT '类型：获取 / 抵扣 / 兑换',
  `rule` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '规则说明',
  `limit` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '限额说明（如 每单最多抵扣 20%）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`, `deleted_at`) COMMENT '规则名唯一',
  KEY `idx_kind` (`kind`, `status`) COMMENT '按类型取生效规则'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分规则';

-- ---------------------------------------------------------------------
-- 积分流水（获取/抵扣/过期；balance 必须与流水守恒）
-- 索引理由：idx_member 查会员积分流水；idx_created 积分过期清理按时间
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `point_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员 ID',
  `member` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '会员姓名（冗余展示）',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '会员手机号（冗余展示）',
  `action` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '动作：获取 / 抵扣 / 过期 / 兑换',
  `points` INT NOT NULL DEFAULT 0 COMMENT '变动积分（正入负出）',
  `balance` INT NOT NULL DEFAULT 0 COMMENT '变动后积分余额',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联订单号',
  `detail` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '流水说明',
  `at` DATETIME NULL DEFAULT NULL COMMENT '发生时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_member` (`member_id`, `at`) COMMENT '按会员取积分流水',
  KEY `idx_created` (`at`) COMMENT '积分过期批量处理按时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分流水';

-- ---------------------------------------------------------------------
-- 评价（评分/图文/商家回复/隐藏；基础功能全版本）
-- 索引理由：uk_order 一单一评；idx_status 待回复列表
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `review` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员 ID',
  `customer` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '客户姓名',
  `service` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '服务名称（冗余展示）',
  `technician_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '技师 ID',
  `technician` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '技师姓名（冗余展示）',
  `rating` TINYINT NOT NULL DEFAULT 5 COMMENT '评分 1-5',
  `content` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '评价内容',
  `images` JSON NULL COMMENT '晒图 URL 列表',
  `reply` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '商家回复',
  `status` VARCHAR(20) NOT NULL DEFAULT '待回复' COMMENT '状态：待回复 / 已回复 / 已隐藏',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order` (`order_no`, `deleted_at`) COMMENT '一单一评防重复评价',
  KEY `idx_status` (`status`, `created_at`) COMMENT '待回复评价列表',
  KEY `idx_tech` (`technician_id`, `rating`) COMMENT '技师评分聚合（技师主页展示）'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评价';

-- ---------------------------------------------------------------------
-- 评价有礼（评价返券/返积分；授权版+）
-- 索引理由：idx_status 取生效规则自动发奖
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `review_reward` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '规则名称（如 五星评价返 5 元券）',
  `condition` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '奖励条件（如 评分≥4 且带图）',
  `reward` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '奖励内容（券/积分）',
  `issued` INT NOT NULL DEFAULT 0 COMMENT '累计发放次数',
  `cost` INT NOT NULL DEFAULT 0 COMMENT '累计成本，单位分',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`, `deleted_at`) COMMENT '规则名唯一',
  KEY `idx_status` (`status`) COMMENT '取生效规则自动发奖'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评价有礼';

-- ---------------------------------------------------------------------
-- 分销推广员（二级分销/佣金结算；加购菜单「分销引擎」）
-- 索引理由：uk_phone 推广员唯一；idx_level 按等级查佣金政策
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `distributor` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '推广员姓名',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '手机号',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联会员 ID',
  `level` VARCHAR(20) NOT NULL DEFAULT '一级分销' COMMENT '等级：一级分销 / 二级分销',
  `team` INT NOT NULL DEFAULT 0 COMMENT '团队人数',
  `orders` INT NOT NULL DEFAULT 0 COMMENT '推广订单数',
  `commission` INT NOT NULL DEFAULT 0 COMMENT '累计佣金，单位分',
  `pending` INT NOT NULL DEFAULT 0 COMMENT '待结算佣金，单位分',
  `withdrawn` INT NOT NULL DEFAULT 0 COMMENT '已提现佣金，单位分',
  `rule` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '分佣规则说明',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_phone` (`phone`, `deleted_at`) COMMENT '推广员手机号唯一',
  KEY `idx_level` (`level`, `status`) COMMENT '按等级查佣金政策'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分销推广员';

-- ---------------------------------------------------------------------
-- 分销订单佣金流水（二级分销分佣；防自购自返在应用层校验）
-- 索引理由：idx_distributor 查推广员佣金流水；uk_order_level 一单一级一记录
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `distribution_order` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `distributor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '推广员 ID',
  `distributor` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '推广员姓名（冗余展示）',
  `level` VARCHAR(20) NOT NULL DEFAULT '一级分销' COMMENT '分佣层级：一级分销 / 二级分销',
  `order_amount` INT NOT NULL DEFAULT 0 COMMENT '订单金额，单位分',
  `commission` INT NOT NULL DEFAULT 0 COMMENT '佣金金额，单位分',
  `status` VARCHAR(20) NOT NULL DEFAULT '待结算' COMMENT '状态：待结算 / 已结算 / 已退回',
  `at` DATETIME NULL DEFAULT NULL COMMENT '发生时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_distributor` (`distributor_id`, `at`) COMMENT '查推广员佣金流水',
  UNIQUE KEY `uk_order_level` (`order_no`, `distributor_id`, `level`, `deleted_at`) COMMENT '一单一级一记录，防重复分佣'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分销佣金流水';

-- ---------------------------------------------------------------------
-- 回访任务（服务后回访/投诉跟进/流失唤醒/会员到期提醒；授权版+）
-- 索引理由：idx_status_due 待办列表按状态+到期时间排序
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `follow_up_task` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联订单号',
  `customer` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '客户姓名',
  `technician` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '服务技师',
  `kind` VARCHAR(30) NOT NULL DEFAULT '服务后回访' COMMENT '任务类型：服务后回访 / 投诉跟进 / 流失唤醒 / 会员到期提醒',
  `due_at` DATETIME NULL DEFAULT NULL COMMENT '要求完成时间',
  `owner` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '负责人',
  `status` VARCHAR(20) NOT NULL DEFAULT '待处理' COMMENT '状态：待处理 / 处理中 / 已完成 / 已关闭',
  `result` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '处理结果',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_status_due` (`status`, `due_at`) COMMENT '待办任务列表按状态+到期时间排序'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='回访任务';

-- ---------------------------------------------------------------------
-- 投诉工单（受理/处理/归档；授权版+）
-- 索引理由：idx_status_created 工单列表按状态+时间；idx_order 按订单追溯
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联订单号',
  `customer` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '投诉客户',
  `target` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '被投诉对象（技师/门店）',
  `kind` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '投诉类型：服务质量 / 态度问题 / 超时 / 收费争议 / 其他',
  `level` VARCHAR(20) NOT NULL DEFAULT '一般' COMMENT '严重程度：严重 / 一般',
  `content` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '投诉内容',
  `owner` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '处理人',
  `resolution` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '处理结果',
  `status` VARCHAR(20) NOT NULL DEFAULT '待受理' COMMENT '状态：待受理 / 处理中 / 已完成',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_status_created` (`status`, `created_at`) COMMENT '工单列表按状态+时间',
  KEY `idx_order` (`order_no`) COMMENT '按订单追溯投诉记录'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='投诉工单';

-- ---------------------------------------------------------------------
-- 自动化营销规则（到期提醒/沉睡唤醒/生日营销；授权版+）
-- 索引理由：idx_trigger_status 定时任务取生效规则扫描
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auto_marketing_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '规则名称（如 90 天沉睡唤醒）',
  `trigger` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '触发条件：注册后 / 生日 / 会员到期 / 沉睡 N 天 / 服务完成',
  `condition` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '条件说明（如 沉睡 90 天且历史消费≥2 单）',
  `action` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '执行动作（发券/发微信消息/打标签）',
  `sent` INT NOT NULL DEFAULT 0 COMMENT '累计触达人数',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_trigger_status` (`trigger`, `status`) COMMENT '定时任务按触发条件取生效规则'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='自动化营销规则';

-- ---------------------------------------------------------------------
-- 积分商城礼品（会员用积分兑换，库存/已兑统计；兑换扣分走 point_log 守恒）
-- 索引理由：idx_status 会员端按上架状态取可兑换列表
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gift` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '礼品名称',
  `points` INT NOT NULL DEFAULT 0 COMMENT '兑换所需积分',
  `stock` INT NOT NULL DEFAULT 0 COMMENT '库存',
  `image` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '礼品图片 URL',
  `detail` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '礼品说明',
  `exchanged` INT NOT NULL DEFAULT 0 COMMENT '累计兑换份数',
  `limit_per_user` INT NOT NULL DEFAULT 0 COMMENT '每人限兑份数，0=不限',
  `end_at` DATETIME NULL DEFAULT NULL COMMENT '兑换截止时间，NULL=长期',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 上架 0 下架',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`, `points`) COMMENT '会员端按上架状态取可兑换列表'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分商城礼品';

SET FOREIGN_KEY_CHECKS = 1;
