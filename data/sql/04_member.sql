-- =====================================================================
-- booking 预约上门服务系统 · 数据库脚本 04 · 会员与卡券储值域
-- 承接：03_trade.sql
-- 会员注册登录以微信一键授权为主（不依赖短信验证码，通道定案 2026-10-03）
-- RFM 分层字段并入 member 主表（会员档案与 RFM 一体，避免双表join）
-- 金额一律 INT 分
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 会员（档案/地址簿/服务历史统计/RFM 分层，开源版含档案、RFM 为授权版+）
-- 索引理由：uk_phone 手机号唯一（登录凭据）；idx_rfm_tag 沉睡识别营销筛选；
--           idx_last_order 复购/流失统计
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `member` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '姓名',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '手机号（微信授权回填，登录凭据）',
  `avatar` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '头像 URL',
  `wechat_openid` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '微信 openid（小程序/公众号授权）',
  `level` VARCHAR(20) NOT NULL DEFAULT '普通会员' COMMENT '等级：普通会员 / 白银会员 / 黄金会员',
  `gender` TINYINT NOT NULL DEFAULT 0 COMMENT '性别：0 未知 1 男 2 女',
  `birthday` DATE NULL DEFAULT NULL COMMENT '生日（生日营销触发）',
  `store_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '归属门店',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '商家备注',
  `r_score` INT NOT NULL DEFAULT 0 COMMENT 'RFM-R 最近消费评分（授权版+）',
  `f_score` INT NOT NULL DEFAULT 0 COMMENT 'RFM-F 消费频次评分（授权版+）',
  `m_score` INT NOT NULL DEFAULT 0 COMMENT 'RFM-M 消费金额评分（授权版+）',
  `rfm_tag` VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'RFM 标签：重要价值 / 重要保持 / 沉睡 / 流失',
  `total_amount` INT NOT NULL DEFAULT 0 COMMENT '累计消费金额，单位分',
  `total_orders` INT NOT NULL DEFAULT 0 COMMENT '累计订单数',
  `last_order_at` DATETIME NULL DEFAULT NULL COMMENT '最近下单时间（沉睡识别）',
`inviter_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '邀请人会员 ID（推荐有礼，仅注册建档时绑定归属）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 正常 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_phone` (`phone`, `deleted_at`) COMMENT '手机号唯一，登录与找回凭据',
  KEY `idx_rfm_tag` (`rfm_tag`) COMMENT '自动化营销按 RFM 标签圈人（沉睡唤醒）',
  KEY `idx_last_order` (`last_order_at`) COMMENT '复购率/流失率统计与沉睡识别',
  KEY `idx_inviter` (`inviter_id`) COMMENT '推荐有礼查邀请人下线与统计'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员（档案+RFM）';

-- ---------------------------------------------------------------------
-- 会员地址簿（上门地址多条，下单选用）
-- 索引理由：idx_member 按会员取地址簿
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `member_address` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员 ID',
  `contact` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '联系人',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `address` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '详细地址',
  `lng` DECIMAL(10,6) NOT NULL DEFAULT 0 COMMENT '经度',
  `lat` DECIMAL(10,6) NOT NULL DEFAULT 0 COMMENT '纬度',
  `is_default` TINYINT NOT NULL DEFAULT 0 COMMENT '是否默认地址：1 是 0 否',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_member` (`member_id`) COMMENT '按会员取地址簿列表'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员地址簿';

-- ---------------------------------------------------------------------
-- 会员标签（RFM 自动打标/自定义标签；授权版+）
-- 索引理由：idx_kind 按类型筛选标签；uk_name 标签名唯一
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `member_tag` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '标签名称',
  `kind` VARCHAR(20) NOT NULL DEFAULT '自定义' COMMENT '类型：RFM 自动 / 自定义',
  `rule` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '打标规则（如 90 天未消费）',
  `color` VARCHAR(20) NOT NULL DEFAULT 'primary' COMMENT '标签色：primary / success / warning / danger / info',
  `members` INT NOT NULL DEFAULT 0 COMMENT '已打标会员数',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`, `deleted_at`) COMMENT '标签名唯一',
  KEY `idx_kind` (`kind`) COMMENT '按类型筛选标签列表'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员标签';

-- ---------------------------------------------------------------------
-- 会员-标签关联（多对多）
-- 索引理由：uk_pair 防重复打标；idx_member 取会员标签
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `member_tag_rel` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员 ID',
  `tag_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '标签 ID',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pair` (`member_id`, `tag_id`, `deleted_at`) COMMENT '同一会员同一标签只打一次',
  KEY `idx_member` (`member_id`) COMMENT '取会员标签集合'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员标签关联';

-- ---------------------------------------------------------------------
-- 可购卡种（会员卡模板：管理员在后台维护，用户端「购买新卡」据此列出）
-- 取代历史前端 mock（user/src/mock/data.js 的 cardOffers），成为唯一事实源
-- 索引理由：idx_status 取「上架 + 排序」的卡种列表
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `card_type` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '卡种名称（如 保洁次卡 10 次）',
  `type` VARCHAR(20) NOT NULL DEFAULT '次卡' COMMENT '卡类型：次卡 / 时长卡',
  `cycle` VARCHAR(20) NOT NULL DEFAULT 'once' COMMENT '周期：once 单次 / monthly 月卡 / quarterly 季卡 / yearly 年卡',
  `total` INT NOT NULL DEFAULT 0 COMMENT '总次数（时长卡存总分钟）',
  `amount` INT NOT NULL DEFAULT 0 COMMENT '购卡金额，单位分',
  `days` INT NOT NULL DEFAULT 365 COMMENT '有效天数（自购卡起算，写卡 expires）',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 上架 0 下架',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`, `sort`) COMMENT '取上架卡种列表'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='可购卡种（会员卡模板）';

-- ---------------------------------------------------------------------
-- 会员卡账户（次卡/时长卡；授权版+；跨店通兑读 store.cross_store）
-- 索引理由：idx_member 查会员名下卡；idx_status 卡到期提醒
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `member_card` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `card_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '卡号（MC+序号）',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员 ID',
  `member` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '会员姓名（冗余展示）',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '会员手机号（冗余展示）',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '卡名称（如 保洁次卡 10 次）',
  `type` VARCHAR(20) NOT NULL DEFAULT '次卡' COMMENT '卡类型：次卡 / 时长卡',
  `cycle` VARCHAR(20) NOT NULL DEFAULT 'once' COMMENT '周期：once 单次 / monthly 月卡 / quarterly 季卡 / yearly 年卡',
  `total` INT NOT NULL DEFAULT 0 COMMENT '总次数（时长卡存总分钟）',
  `used` INT NOT NULL DEFAULT 0 COMMENT '已用次数（时长卡存已用分钟）',
  `amount` INT NOT NULL DEFAULT 0 COMMENT '购卡金额，单位分',
  `cross_store` TINYINT NOT NULL DEFAULT 0 COMMENT '跨店通用：1 是 0 否',
  `expires` DATE NULL DEFAULT NULL COMMENT '有效期至',
  `status` VARCHAR(20) NOT NULL DEFAULT '使用中' COMMENT '状态：使用中 / 已用完 / 已过期 / 已退卡',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card_no` (`card_no`, `deleted_at`) COMMENT '卡号唯一',
  KEY `idx_member` (`member_id`) COMMENT '查会员名下会员卡',
  KEY `idx_status` (`status`, `expires`) COMMENT '卡到期提醒与状态筛选'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员卡（次卡/时长卡）';

-- ---------------------------------------------------------------------
-- 会员卡流水（开卡/核销/退卡；核销关联订单可追溯）
-- 索引理由：idx_card 按卡取流水
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `member_card_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `card_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员卡 ID',
  `card_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '卡号',
  `action` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '动作：开卡 / 核销 / 退卡 / 赠送',
  `times` INT NOT NULL DEFAULT 0 COMMENT '变动次数（核销 1 次）',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联订单号（核销时）',
  `detail` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '流水说明',
  `at` DATETIME NULL DEFAULT NULL COMMENT '发生时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_card` (`card_id`, `at`) COMMENT '按卡取流水明细'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员卡流水';

-- ---------------------------------------------------------------------
-- 储值卡账户（余额/等级；授权版+）
-- 索引理由：idx_member 查会员储值账户；uk_member 一会员一账户
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stored_account` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `account_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '账户号（SV+序号）',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员 ID',
  `member` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '会员姓名（冗余展示）',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '会员手机号（冗余展示）',
  `level` VARCHAR(20) NOT NULL DEFAULT '普通会员' COMMENT '储值等级：普通会员 / 白银会员 / 黄金会员',
  `balance` INT NOT NULL DEFAULT 0 COMMENT '当前余额，单位分',
  `total_recharge` INT NOT NULL DEFAULT 0 COMMENT '累计充值，单位分',
  `total_consume` INT NOT NULL DEFAULT 0 COMMENT '累计消费，单位分',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member` (`member_id`, `deleted_at`) COMMENT '一会员一储值账户',
  KEY `idx_account` (`account_no`) COMMENT '按账户号查流水'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='储值卡账户';

-- ---------------------------------------------------------------------
-- 充值套餐（商家在后台「套餐设置」维护，会员端充值页按此展示）
-- 索引理由：idx_status 取生效套餐展示
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `recharge_plan` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `money` INT NOT NULL DEFAULT 0 COMMENT '充值金额，单位分',
  `gift` INT NOT NULL DEFAULT 0 COMMENT '赠送金额，单位分',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序值',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 上架 0 下架',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`, `sort`) COMMENT '取生效套餐按序展示'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值套餐';

-- ---------------------------------------------------------------------
-- 储值流水（充值/赠送/消费扣款/退款；余额变动守恒可追溯）
-- 索引理由：idx_account 按账户取流水；idx_created 对账按时间核对
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stored_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `account_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '储值账户 ID',
  `account_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '账户号',
  `action` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '动作：充值 / 赠送 / 消费扣款 / 退款',
  `amount` INT NOT NULL DEFAULT 0 COMMENT '变动金额，单位分（正入负出）',
  `balance` INT NOT NULL DEFAULT 0 COMMENT '变动后余额，单位分',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联订单号（消费扣款/退款）',
  `detail` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '流水说明',
  `at` DATETIME NULL DEFAULT NULL COMMENT '发生时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_account` (`account_id`, `at`) COMMENT '按账户取流水明细',
  KEY `idx_created` (`at`) COMMENT '按时间核对余额守恒（充值-消费=余额）'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='储值流水';

-- ---------------------------------------------------------------------
-- 优惠券模板（满减/折扣/新客券；发放统计）
-- 索引理由：idx_status 发券页取生效券模板；uk_name 券名唯一
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coupon` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `code` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '券模板编码（CP+序号）',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '券名称（如 新人立减 30 元）',
  `type` VARCHAR(20) NOT NULL DEFAULT '满减' COMMENT '券类型：满减 / 折扣 / 新客券',
  `value` INT NOT NULL DEFAULT 0 COMMENT '优惠值：满减存金额（分）；折扣存百分比（88=8.8折）',
  `threshold` INT NOT NULL DEFAULT 0 COMMENT '使用门槛金额，单位分（0=不限）',
  `total` INT NOT NULL DEFAULT 0 COMMENT '发放总量',
  `sent` INT NOT NULL DEFAULT 0 COMMENT '已发放',
  `used` INT NOT NULL DEFAULT 0 COMMENT '已核销',
  `expires` DATE NULL DEFAULT NULL COMMENT '有效期至',
  `status` VARCHAR(20) NOT NULL DEFAULT '发放中' COMMENT '状态：发放中 / 已停发 / 已结束',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`, `deleted_at`) COMMENT '券模板编码唯一',
  UNIQUE KEY `uk_name` (`name`, `deleted_at`) COMMENT '券名称唯一防重复创建',
  KEY `idx_status` (`status`, `expires`) COMMENT '发券页取生效券模板'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='优惠券模板';

-- ---------------------------------------------------------------------
-- 券码实例（一券一码，发放/领取生成，核销后关订单）
-- 索引理由：uk_code 券码唯一（核销幂等键）；idx_member 查会员名下券
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coupon_instance` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `code` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '券码（CPN-XXXX-XXXX，核销幂等键）',
  `coupon_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '券模板 ID',
  `coupon_name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '券名称（冗余展示）',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '持有会员 ID',
  `member` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '会员姓名（冗余展示）',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '会员手机号（冗余展示）',
  `source` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '来源：新客自动发 / 社群发码 / 活动领取 / 后台发放',
  `at` DATETIME NULL DEFAULT NULL COMMENT '发放/领取时间',
  `status` VARCHAR(20) NOT NULL DEFAULT '未使用' COMMENT '状态：未使用 / 已使用 / 已过期 / 已作废',
  `used_at` DATETIME NULL DEFAULT NULL COMMENT '核销时间',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '核销订单号',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`, `deleted_at`) COMMENT '券码唯一=核销幂等键',
  KEY `idx_member` (`member_id`, `status`) COMMENT '查会员名下券（下单选券）',
  KEY `idx_coupon` (`coupon_id`, `status`) COMMENT '券模板发放/核销统计'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='券码实例';

-- ---------------------------------------------------------------------
-- 优惠券流水（发放/领取/核销/作废）
-- 索引理由：idx_coupon 按券模板取流水
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `coupon_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `coupon_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '券模板 ID',
  `coupon` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '券名称（冗余展示）',
  `action` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '动作：发放 / 领取 / 核销 / 作废',
  `count` INT NOT NULL DEFAULT 1 COMMENT '涉及数量',
  `detail` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '流水说明',
  `at` DATETIME NULL DEFAULT NULL COMMENT '发生时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_coupon` (`coupon_id`, `at`) COMMENT '按券模板取流水明细'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='优惠券流水';

-- ---------------------------------------------------------------------
-- 次卡/时长卡商品（售卖卡项；与 member_card 开卡账户区分：此处为卡商品）
-- 索引理由：idx_status 售卖页取上架卡商品；uk_name 卡名唯一
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `timesheet_card` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '卡名称（如 保洁次卡 10 次）',
  `service_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '适用服务（0=全部）',
  `service` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '服务名称（冗余展示）',
  `times` INT NOT NULL DEFAULT 0 COMMENT '包含次数（时长卡存分钟）',
  `price` INT NOT NULL DEFAULT 0 COMMENT '售价，单位分',
  `origin_price` INT NOT NULL DEFAULT 0 COMMENT '原价，单位分',
  `valid` INT NOT NULL DEFAULT 0 COMMENT '有效期（天）',
  `cross_store` TINYINT NOT NULL DEFAULT 0 COMMENT '跨店通用：1 是 0 否',
  `sold` INT NOT NULL DEFAULT 0 COMMENT '已售数量',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 在售 0 停售',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`, `deleted_at`) COMMENT '卡名称唯一',
  KEY `idx_status` (`status`) COMMENT '售卖页取在售卡商品'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='次卡/时长卡商品';

SET FOREIGN_KEY_CHECKS = 1;
