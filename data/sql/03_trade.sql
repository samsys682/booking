-- =====================================================================
-- booking 预约上门服务系统 · 数据库脚本 03 · 交易与财务域
-- 承接：02_service.sql
-- 订单状态机（OrderService 强校验，禁跳节点/禁终态回退；口径=业务错位整改方案 3.2）：
--   主流程：待支付 → 待派单 → 待接单 → 待服务 → 服务中 → 待确认 → 已完成 → 待评价 → 已评价
--   逆向：已取消（各阶段按预约规则）；售后中 → 已退款（审核驳回回原状态）
--   指定技师下单：待支付 → 待接单（跳过待派单）；拒单：待接单 → 回待派单
--   超期自动流转：待确认→已完成、已完成→待评价、待评价→已评价（系统执行）
--   统计口径：「已完成订单」= 已完成 + 待评价 + 已评价 三态合计
--   服务中 → 加钟（order_extend 追加时长与差价后重新计价）；服务中不允许直接取消，纠纷转售后
-- 金额一律 INT 分；金额只信服务端计算（《接口规范》），前端金额仅展示
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 预约订单（核心主单：预约下单/时段占用/支付/履约/售后一体）
-- 索引理由：uk_order_no 单号唯一（支付回调幂等键）；idx_member 查会员订单；
--           idx_tech_start 派单与时段冲突校验（技师+开始时间）；idx_status 列表筛选
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booking` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '订单号（BK+yyyyMMdd+序号，支付幂等键）',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员 ID',
  `customer` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '客户姓名',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '联系电话（展示脱敏）',
  `service_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '服务项目 ID',
  `service` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '服务名称（冗余，服务改名后订单可追溯）',
  `technician_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '技师 ID（0=待分配）',
  `technician` VARCHAR(50) NOT NULL DEFAULT '待分配' COMMENT '技师姓名（冗余展示）',
  `store_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '履约门店',
  `start_at` DATETIME NULL DEFAULT NULL COMMENT '预约开始时间',
  `end_at` DATETIME NULL DEFAULT NULL COMMENT '预约结束时间（含加钟后时长）',
  `duration` INT NOT NULL DEFAULT 0 COMMENT '服务时长（分钟，含加钟累计）',
  `address` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '上门地址',
  `lng` DECIMAL(10,6) NOT NULL DEFAULT 0 COMMENT '地址经度（围栏校验/派单距离）',
  `lat` DECIMAL(10,6) NOT NULL DEFAULT 0 COMMENT '地址纬度',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '下单备注',
  `total` INT NOT NULL DEFAULT 0 COMMENT '原价合计，单位分',
  `discount` INT NOT NULL DEFAULT 0 COMMENT '优惠金额，单位分（券/活动/会员价）',
  `actual` INT NOT NULL DEFAULT 0 COMMENT '应收金额，单位分（total-discount，服务端计算）',
  `paid` INT NOT NULL DEFAULT 0 COMMENT '已付金额，单位分（含定金）',
  `pay_channel` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '支付渠道：wechat 微信 / alipay 支付宝 / stored 储值 / offline 线下',
  `coupon_code` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '使用的券码（coupon_instance.code）',
  `status` VARCHAR(20) NOT NULL DEFAULT '待支付' COMMENT '状态：待支付/待派单/待接单/待服务/服务中/待确认/已完成/待评价/已评价/已取消/售后中/已退款',
  `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '取消原因',
  `pre_status` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '转售后前状态（售后驳回回退用）',
  `aftersale_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '转售后原因（纠纷/退款诉求等）',
  `aftersale_at` DATETIME NULL DEFAULT NULL COMMENT '转售后时间',
  `aftersale_result` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '售后处理结果（驳回记录）',
  `source` VARCHAR(20) NOT NULL DEFAULT '用户端' COMMENT '来源：用户端 / 后台 / 技师端',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`, `deleted_at`) COMMENT '订单号唯一，支付回调按单号幂等',
  KEY `idx_member` (`member_id`, `created_at`) COMMENT '会员订单列表与复购统计',
  KEY `idx_tech_start` (`technician_id`, `start_at`) COMMENT '时段冲突校验与技师日程核心索引',
  KEY `idx_status` (`status`, `start_at`) COMMENT '订单列表筛选+今日预约看板'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='预约订单（核心主单）';

-- ---------------------------------------------------------------------
-- 加钟记录（竞品对标① P0：服务中延长时长/加收差价，技师端确认后延单重新计价）
-- 索引理由：idx_order 按订单取加钟记录汇总时长与差价
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_extend` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `minutes` INT NOT NULL DEFAULT 0 COMMENT '加钟时长（分钟）',
  `price` INT NOT NULL DEFAULT 0 COMMENT '加钟差价，单位分',
  `reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '加钟原因（客户要求延长）',
  `operator` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '确认人（技师/后台）',
  `status` VARCHAR(20) NOT NULL DEFAULT '已生效' COMMENT '状态：待确认 / 已生效 / 已驳回',
  `at` DATETIME NULL DEFAULT NULL COMMENT '加钟时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_no`) COMMENT '按订单汇总加钟时长与差价（重新计价输入）'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单加钟记录';

-- ---------------------------------------------------------------------
-- 候补排队与放号（授权版+：满约排队/取消补位）
-- 索引理由：idx_slot 按日期+时段取候补队列；status 流转排队→补位→过号
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booking_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员 ID',
  `customer` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '客户姓名',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `service_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '意向服务 ID',
  `service` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '服务名称',
  `date` DATE NOT NULL COMMENT '意向日期',
  `time_slot` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '意向时段（10:00-12:00）',
  `seq` INT NOT NULL DEFAULT 0 COMMENT '排队序号',
  `status` VARCHAR(20) NOT NULL DEFAULT '排队中' COMMENT '状态：排队中 / 已补位 / 已过号 / 已取消',
  `note` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_slot` (`date`, `time_slot`, `status`) COMMENT '按日期+时段取候补队列做放号补位'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='候补排队';

-- ---------------------------------------------------------------------
-- 定金/尾款（授权版+：定金锁单/尾款结算）
-- 索引理由：idx_order 查订单定金尾款构成；idx_status 待收尾款提醒
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `deposit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `kind` VARCHAR(20) NOT NULL DEFAULT '定金' COMMENT '类型：定金 / 尾款',
  `amount` INT NOT NULL DEFAULT 0 COMMENT '金额，单位分',
  `pay_channel` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '支付渠道：wechat / alipay / stored / offline',
  `status` VARCHAR(20) NOT NULL DEFAULT '待支付' COMMENT '状态：待支付 / 已支付 / 已退款',
  `paid_at` DATETIME NULL DEFAULT NULL COMMENT '支付时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_no`) COMMENT '查订单定金/尾款构成',
  KEY `idx_status` (`status`, `created_at`) COMMENT '待收尾款列表与超时未付监控'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='定金/尾款';

-- ---------------------------------------------------------------------
-- 支付流水（第三方支付单据；回调幂等：uk_out_trade_no 天然去重）
-- 索引理由：uk_out_trade_no 第三方单号唯一防重复入账（回调幂等核心）；
--           idx_order 查订单支付流水
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pay_transaction` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '业务订单号',
  `out_trade_no` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '第三方支付单号（微信/支付宝）',
  `channel` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '支付渠道：wechat / alipay / stored / offline',
  `kind` VARCHAR(20) NOT NULL DEFAULT '订单支付' COMMENT '业务类型：订单支付 / 定金 / 尾款 / 会员卡 / 储值充值',
  `amount` INT NOT NULL DEFAULT 0 COMMENT '支付金额，单位分',
  `status` VARCHAR(20) NOT NULL DEFAULT '待支付' COMMENT '状态：待支付 / 支付成功 / 支付失败 / 已退款',
  `paid_at` DATETIME NULL DEFAULT NULL COMMENT '支付成功时间',
  `raw` JSON NULL COMMENT '回调原始报文（验签与对账留痕）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_out_trade_no` (`out_trade_no`, `deleted_at`) COMMENT '第三方单号唯一=回调幂等键，重复回调直接返回成功',
  KEY `idx_order` (`order_no`) COMMENT '查订单支付流水'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付流水';

-- ---------------------------------------------------------------------
-- 退款单（授权版+：退款申请/审核/原路退回）
-- 索引理由：uk_refund_no 退款单号唯一（退款请求幂等）；idx_status 审核列表
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `refund` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `refund_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '退款单号（RD+yyyyMMdd+序号）',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '原订单号',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员 ID',
  `member` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '会员姓名（冗余展示）',
  `amount` INT NOT NULL DEFAULT 0 COMMENT '退款金额，单位分',
  `channel` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '退款渠道：wechat / alipay / stored / offline',
  `reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '退款原因',
  `reject_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '驳回原因（status=已驳回）',
  `operator` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '处理人',
  `status` VARCHAR(20) NOT NULL DEFAULT '退款中' COMMENT '状态：退款中 / 已退款 / 已驳回',
  `refunded_at` DATETIME NULL DEFAULT NULL COMMENT '退款到账时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refund_no` (`refund_no`, `deleted_at`) COMMENT '退款单号唯一，退款请求按单号幂等',
  KEY `idx_status` (`status`, `created_at`) COMMENT '退款审核列表'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='退款单';

-- ---------------------------------------------------------------------
-- 发票（授权版+：电子普票/电子专票，开具/驳回）
-- 索引理由：idx_order 查订单开票情况防重复开票；idx_status 开票任务列表
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoice` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `member_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员 ID',
  `member` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '会员姓名',
  `type` VARCHAR(20) NOT NULL DEFAULT '电子普票' COMMENT '发票类型：电子普票 / 电子专票',
  `title_type` VARCHAR(10) NOT NULL DEFAULT '个人' COMMENT '抬头类型：个人 / 企业',
  `title` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '发票抬头',
  `tax_no` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '税号（企业抬头必填）',
  `amount` INT NOT NULL DEFAULT 0 COMMENT '开票金额，单位分',
  `status` VARCHAR(20) NOT NULL DEFAULT '开具中' COMMENT '状态：开具中 / 已开具 / 已驳回',
  `invoice_no` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '发票号码（开具成功后回填）',
  `reject_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '驳回原因',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_no`) COMMENT '查订单开票情况，防重复开票',
  KEY `idx_status` (`status`, `created_at`) COMMENT '开票任务列表'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='发票';

-- ---------------------------------------------------------------------
-- 财务对账（授权版+：渠道流水 vs 平台实收，差异标红）
-- 索引理由：uk_order_no 一单一条对账记录；idx_status 差异单处理列表
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `finance_reconcile` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '订单号',
  `channel` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '收款渠道：wechat / alipay / stored',
  `gross` INT NOT NULL DEFAULT 0 COMMENT '应收原价，单位分',
  `discount` INT NOT NULL DEFAULT 0 COMMENT '优惠金额，单位分',
  `actual` INT NOT NULL DEFAULT 0 COMMENT '实收金额，单位分（gross-discount，服务端核算）',
  `fee` INT NOT NULL DEFAULT 0 COMMENT '渠道手续费，单位分',
  `net` INT NOT NULL DEFAULT 0 COMMENT '净入账，单位分（actual-fee）',
  `at` DATETIME NULL DEFAULT NULL COMMENT '入账时间',
  `status` VARCHAR(20) NOT NULL DEFAULT '待对账' COMMENT '状态：已对账 / 差异 / 待对账',
  `diff` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '差异说明（差异状态必填）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`, `deleted_at`) COMMENT '一单一条对账记录',
  KEY `idx_status` (`status`, `at`) COMMENT '差异单处理列表与按月对账'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='财务对账';

-- ---------------------------------------------------------------------
-- 提成规则（授权版+：按比例/固定/阶梯；源码版核心加密）
-- 索引理由：idx_scope 按适用范围取生效规则计算提成
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `commission_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '规则名称（如 保洁提成 20%）',
  `scope` VARCHAR(20) NOT NULL DEFAULT '全部服务' COMMENT '适用范围：全部服务 / 指定服务 / 指定技师',
  `target_ids` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '指定对象 ID（逗号分隔）',
  `mode` VARCHAR(20) NOT NULL DEFAULT '比例' COMMENT '计算方式：比例 / 固定金额 / 阶梯',
  `value` INT NOT NULL DEFAULT 0 COMMENT '比例百分比（20=20%）或固定金额（分）',
  `ladder` JSON NULL COMMENT '阶梯规则（[{min:0,max:100000,rate:20}]，金额分）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1 启用 0 停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  KEY `idx_scope` (`scope`, `status`) COMMENT '结算时按范围+状态取生效提成规则'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提成规则';

-- ---------------------------------------------------------------------
-- 提成结算/工资条（授权版+）
-- 索引理由：idx_tech_month 技师月度工资条查询；uk_tech_month 防重复结算
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `commission_settlement` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `technician_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '技师 ID',
  `technician` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '技师姓名（冗余展示）',
  `month` CHAR(7) NOT NULL DEFAULT '' COMMENT '结算月份（YYYY-MM）',
  `orders` INT NOT NULL DEFAULT 0 COMMENT '完成单量',
  `gross` INT NOT NULL DEFAULT 0 COMMENT '服务金额合计，单位分',
  `commission` INT NOT NULL DEFAULT 0 COMMENT '提成合计，单位分',
  `bonus` INT NOT NULL DEFAULT 0 COMMENT '奖励金额，单位分',
  `penalty` INT NOT NULL DEFAULT 0 COMMENT '扣款金额，单位分',
  `actual` INT NOT NULL DEFAULT 0 COMMENT '应发合计，单位分（commission+bonus-penalty）',
  `status` VARCHAR(20) NOT NULL DEFAULT '待发放' COMMENT '状态：待发放 / 已发放 / 已驳回',
  `note` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` BIGINT NOT NULL DEFAULT 0 COMMENT '软删时间戳，0=未删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tech_month` (`technician_id`, `month`, `deleted_at`) COMMENT '同一技师同一月份只结算一次',
  KEY `idx_tech_month` (`technician_id`, `month`) COMMENT '技师月度工资条查询'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提成结算/工资条';

SET FOREIGN_KEY_CHECKS = 1;
