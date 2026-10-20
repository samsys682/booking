-- =====================================================================
-- booking 预约上门服务系统 · 数据库脚本 06 · 种子数据
-- 承接：05_marketing.sql
-- 用途：安装向导建库后初始化（角色/权限/设置/派单权重/示例配置）
-- 管理员账号由安装向导「管理员初始化」步骤写入（不预置默认密码，安全要求）
-- 密码说明：本脚本不含任何账号密码；admin_account.password 一律 password_hash() 写入
-- 幂等说明：全部种子使用 INSERT IGNORE，安装/升级可反复执行不产生重复数据
-- =====================================================================

SET NAMES utf8mb4;

-- 内置角色（is_system=1 禁删）
INSERT IGNORE INTO `role` (`name`, `code`, `desc`, `is_system`) VALUES
('老板', 'owner', '全部权限，含财务/系统设置/授权', 1),
('店长', 'manager', '门店经营：订单/技师/排班/营销，不含财务与系统', 1),
('前台', 'front', '订单/预约/会员接待，不含财务与系统', 1),
('财务', 'finance', '财务对账/发票/退款/提成结算', 1);

-- 权限码与角色映射（对齐前端 permissionTree：booking/tech/member/finance/system 五组）
-- owner 全量权限
INSERT IGNORE INTO `role_permission` (`role_id`, `perm`)
SELECT r.id, p.perm FROM `role` r
JOIN (
  SELECT 'booking.view' AS perm UNION ALL SELECT 'booking.edit' UNION ALL SELECT 'booking.delete'
  UNION ALL SELECT 'booking.dispatch' UNION ALL SELECT 'tech.view' UNION ALL SELECT 'tech.edit'
  UNION ALL SELECT 'tech.schedule' UNION ALL SELECT 'member.view' UNION ALL SELECT 'member.edit'
  UNION ALL SELECT 'member.marketing' UNION ALL SELECT 'finance.view' UNION ALL SELECT 'finance.edit'
  UNION ALL SELECT 'finance.export' UNION ALL SELECT 'system.view' UNION ALL SELECT 'system.edit'
) p
WHERE r.code = 'owner' AND r.deleted_at = 0;

-- manager：除 finance.export/system.edit 外全量
INSERT IGNORE INTO `role_permission` (`role_id`, `perm`)
SELECT r.id, p.perm FROM `role` r
JOIN (
  SELECT 'booking.view' AS perm UNION ALL SELECT 'booking.edit' UNION ALL SELECT 'booking.delete'
  UNION ALL SELECT 'booking.dispatch' UNION ALL SELECT 'tech.view' UNION ALL SELECT 'tech.edit'
  UNION ALL SELECT 'tech.schedule' UNION ALL SELECT 'member.view' UNION ALL SELECT 'member.edit'
  UNION ALL SELECT 'member.marketing' UNION ALL SELECT 'finance.view'
) p
WHERE r.code = 'manager' AND r.deleted_at = 0;

-- front：订单与会员接待
INSERT IGNORE INTO `role_permission` (`role_id`, `perm`)
SELECT r.id, p.perm FROM `role` r
JOIN (
  SELECT 'booking.view' AS perm UNION ALL SELECT 'booking.edit' UNION ALL SELECT 'booking.dispatch'
  UNION ALL SELECT 'tech.view' UNION ALL SELECT 'member.view' UNION ALL SELECT 'member.edit'
) p
WHERE r.code = 'front' AND r.deleted_at = 0;

-- finance：财务域
INSERT IGNORE INTO `role_permission` (`role_id`, `perm`)
SELECT r.id, p.perm FROM `role` r
JOIN (
  SELECT 'finance.view' AS perm UNION ALL SELECT 'finance.edit' UNION ALL SELECT 'finance.export'
  UNION ALL SELECT 'booking.view' UNION ALL SELECT 'member.view'
) p
WHERE r.code = 'finance' AND r.deleted_at = 0;

-- 系统设置（限额外置配置——《功能矩阵》附三：限额外置配置，禁止写死代码）
-- 开源版默认限额：1 门店 / ≤5 技师 / 月 200 单；授权版 3 店/不限技师/不限单；微信通知不限条数（2026-10-10 决策）
-- 授权激活后由 LicenseService 按 edition 覆盖 quota 分组的 max 值
INSERT IGNORE INTO `setting` (`group`, `key`, `value`, `type`, `remark`) VALUES
('basic', 'site_name', '洁净到家·预约上门', 'text', '站点名称'),
('basic', 'company_name', '', 'text', '公司主体（发票抬头默认值）'),
('basic', 'service_phone', '', 'text', '客服电话'),
('basic', 'logo', '', 'text', '站点 Logo URL'),
('booking', 'advance_hours', '2', 'number', '提前预约时长（小时）：下单最早可约时间距现在'),
('booking', 'max_advance_days', '30', 'number', '最远可预约天数'),
('booking', 'cancel_limit_hours', '4', 'number', '服务开始前可免费取消的小时数，超时取消收违约金'),
('booking', 'cancel_fee_rate', '10', 'number', '超时取消违约金比例（%）'),
('booking', 'noshow_limit', '3', 'number', '月内爽约次数上限，超限限制下单'),
('booking', 'noshow_freeze_days', '7', 'number', '爽约超限后的下单冻结天数（配合 noshow_limit，OrderService::markNoshow 读取）'),
('booking', 'queue_enabled', '0', 'bool', '候补排队开关（授权版+，开源版锁定关闭）'),
('booking', 'deposit_enabled', '0', 'bool', '定金尾款开关（授权版+，开源版锁定关闭）'),
('booking', 'deposit_rate', '30', 'number', '定金比例（%）：启用定金尾款时下单先付实付金额的 N% 作定金，余款为尾款'),
('quota', 'stores_max', '1', 'number', '门店数上限（开源版 1，授权版 3，源码版不限传 -1）'),
('quota', 'technicians_max', '5', 'number', '技师数上限（开源版 5，授权/源码不限传 -1）'),
('quota', 'monthly_orders_max', '200', 'number', '月订单量上限（开源版 200，授权/源码不限传 -1）'),
('quota', 'wechat_messages_max', '-1', 'number', '微信通知条数不限（2026-10-10 产品决策：全版本不限条数）'),
('quota', 'warn_rate', '80', 'number', '限额触达百分比即后台黄条提醒（《功能矩阵》附一）'),
('brand', 'powered_by', '1', 'bool', '保留 Powered by 版权链接（开源版强制 1，授权版可关，源码版白标）'),
('brand', 'brand_name', '', 'text', '自定义品牌名（授权版+）'),
('notify', 'wechat_mini_enabled', '1', 'bool', '小程序订阅消息通道开关'),
('notify', 'wechat_mp_enabled', '1', 'bool', '公众号模板消息通道开关'),
('notify', 'log_keep_days', '7', 'number', '操作日志保留天数（开源版 7 天，授权/源码 1 年=365）'),
('notify', 'backup_mode', 'manual', 'text', '备份策略：manual 手动（开源版）/ auto 自动定时（授权/源码）'),
-- 在线支付渠道（后台「系统设置 → 在线支付」面板；商户密钥由 PaymentSettingsService 加密落库）
-- 开关默认：线下收款开、微信/支付宝关（需商户自行配置凭证后开启）；配置能力为授权版+
('payment', 'pay_offline_enabled', '1', 'bool', '线下收款开关（现金/POS 刷卡，后台确认收款）'),
('payment', 'pay_wechat_enabled', '0', 'bool', '微信支付开关（需在「在线支付」面板配置商户参数）'),
('payment', 'pay_alipay_enabled', '0', 'bool', '支付宝开关（需在「在线支付」面板配置商户参数）'),
-- 积分体系与推荐/分销（会员域；PointList/ReferralList 可改）
('member', 'point_rate', '1', 'number', '积分比例：实付 1 元 = N 积分（订单确认完成时自动发放）'),
('member', 'level_rules', '[{"name":"普通会员","threshold":0},{"name":"白银会员","threshold":500},{"name":"黄金会员","threshold":2000}]', 'json', '会员等级规则：累计消费满 threshold 元自动升为对应等级'),
('marketing', 'distribution_rate', '5', 'number', '分销佣金比例：订单确认完成时按实付金额百分比计入分销员待结算（未配置邀请关系则不触发）'),
-- 价格规则（时段差价/节假日价目；PriceRuleService 读取，落库字段见 11_pricing_engine.sql）
('price_rule', 'price_rule.enabled', '1', 'bool', '价格规则总开关（0=全局停用时段差价/节假日加价）'),
('price_rule', 'price_rule.holidays', '', 'text', '法定节假日日期（逗号分隔，如 2026-10-01,2026-10-02；date_type=节假日 的规则据此命中）'),
-- 自动派单（BookingList「派单设置」对话框；与 OrderService::autoDispatch 同源）
('booking', 'dispatch_mode', '手动指派', 'text', '派单模式：手动指派 / 自动派单（自动=支付成功后按权重自动指派 Top1 技师）'),
('booking', 'dispatch_weights', '{"skill":40,"schedule":25,"load":20,"rating":15}', 'json', '自动派单权重：技能匹配/档期空闲/负载/评分（合计 100，可调整）');

-- 智能派单权重（授权版+；权重合计 100%，可后台调整）
INSERT IGNORE INTO `dispatch_rule` (`key`, `label`, `weight`, `desc`) VALUES
('distance', '距离', 35, '技师与客户地址距离越近得分越高'),
('skill', '技能匹配', 25, '技师技能标签与服务项目匹配度'),
('rating', '评分', 20, '技师历史评分'),
('load', '负载', 15, '当日已排单量越少得分越高'),
('complete', '完成率', 5, '近 30 天完成率');

-- 示例服务分类（安装向导可跳过，也可在后台「服务分类」维护）
INSERT IGNORE INTO `service_category` (`name`, `sort`) VALUES
('保洁', 100), ('家电清洗', 90), ('收纳', 80), ('专项清洁', 70);

-- 可购卡种（会员卡模板；后台「可购卡种」维护，用户端「购买新卡」据此列出）
-- 显式 id + INSERT IGNORE 保证幂等；金额单位分
INSERT IGNORE INTO `card_type` (`id`, `name`, `type`, `cycle`, `total`, `amount`, `days`, `sort`, `status`) VALUES
(1, '保洁次卡 10 次', '次卡', 'once', 10, 99000, 365, 10, 1),
(2, '保洁季卡 30 次', '次卡', 'quarterly', 30, 259000, 90, 20, 1),
(3, '深度保洁年卡 50 次', '次卡', 'yearly', 50, 399000, 365, 30, 1);

-- 默认提成规则（员工提成与业绩结算；授权版功能，后台「提成规则」可改）
-- mode=比例，value=55 即 55%；CommissionService 结算时按此计提
INSERT IGNORE INTO `commission_rule` (`id`, `name`, `scope`, `target_ids`, `mode`, `value`, `status`) VALUES
(1, '标准提成 55%', '全部服务', '', '比例', 55, 1);
