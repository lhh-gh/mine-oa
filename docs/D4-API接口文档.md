# MineAdmin OA 系统 — API 接口文档（D4）

> 项目代号：MineOA
> 版本：v0.2（修订稿，已对齐 D3 v0.2：打卡同步写库、审批操作示例修正、H5 文档只读接口、请假重叠校验、轮次语义）
> 传递格式：JSON（`Content-Type: application/json`）；鉴权：Bearer JWT（Header `Authorization: Bearer {token}``）
> 统一响应结构（沿用 MineAdmin Result）：

```json
{ "code": 200, "message": "ok", "data": { } }
```

- 业务失败返回对应错误码（4xx 语义），`message` 为用户可读中文提示，**不含堆栈**。
- 分页参数：`page`（默认 1）、`pageSize`（默认 10，上限 100）；分页返回 `data: { list: [], total: n, page, pageSize }`。

---

## 0. 接口总览

| 入口 | 前缀 | 端 | 说明 |
| --- | --- | --- | --- |
| 管理端 | `/admin/oa/*` | Vue3 后台 | 模板配置、报表、公告发布、文档管理（控制器注解 `#[Auth]` + `#[Permission("oa:...")]`，走 MineAdmin JWT + RBAC） |
| 移动端 | `/api/oa/*` | H5 + 小程序 | 审批、打卡、公告阅读、消息、文档只读（走 mobile scene JWT 专用中间件） |
| 移动端鉴权 | `/api/auth/*` | H5 + 小程序 | 登录、绑定、短信验证码 |

> 双端共用业务 Service 层，仅 Controller/鉴权方式不同（`App\OA\Http\Controller\Admin` 与 `...\Api`，见 D2 §2）。
> 错误码规范：`1000 + 模块号*100 + 序号`（如审批 1100xx）。

---

## 1. 鉴权与小程序绑定

### POST /api/auth/sms/send — 发送验证码
入参：`{ "phone": "13800000001" }`
出参：`data: true`（60s 冷却、5min 有效、每手机号日上限 10 条；阿里云短信通道，模板走 .env）
错误：100101 发送过于频繁；100102 超出当日上限

### POST /api/auth/login — H5 登录
入参：`{ "username": "13800000001", "password": "***" }`（或 `mode:"sms"` + `phone` + `smsCode`）
出参：`{ "token": "eyJ...", "user": { "id": 1, "nickname": "张三", "dept": "技术部" } }`
错误：100001 账号或密码错误；100002 验证码错误/过期

### POST /api/auth/wechat/login — 小程序登录
```
入参: { "code": "wx.login 原始 code", "phone": "13800000001", "smsCode": "8421" }
逻辑: 服务端 code2session 换 openid → 已绑定则直接签发 JWT；未绑定则校验手机验证码后创建绑定
出参: { "token": "...", "bound": true }
```
错误：101001 code 无效；101002 该微信已绑定其他员工；101003 手机验证码错误

### POST /api/auth/wechat/subscribe — 记录订阅消息授权
入参：`{ "templateKey": "approval_todo" }`
出参：`data: true`（服务端将 `oa_wechat_binding.subscribe_status` 置 1）

---

## 2. 审批中心【核心】

### 管理端（模板）

| 接口 | 方法 | 说明 |
| --- | --- | --- |
| /admin/oa/approval/template/list | GET | 模板分页；参数 `name`、`status`、`bizType` |
| /admin/oa/approval/template/save | POST | 新建/更新模板；body 含 `bizType`、`formSchema`（JSON，字段定义见 D3 §1.1）、`nodeSchema{nodes[]}`、`fallbackApprover?`；保存后模板 `version` +1 |
| /admin/oa/approval/template/change-status | PUT | 启用/停用（在途实例不受影响） |

> 校验规则：`nodeSchema.nodes[].seq` 必须从 1 连续递增；`approver_type` 为 `dept_leader` 时运行期解析发起人部门负责人，解析链（dept_leader → 上溯父部门 → 模板兜底审批人）全部失败则提交接口返回 110003"未找到可用审批人"（B1：阻止提交，不转超管）。

### 移动端（流程）

| 接口 | 方法 | 说明 |
| --- | --- | --- |
| /api/oa/approval/templates | GET | 可发起的模板列表（name+icon+bizType），缓存 5min |
| /api/oa/approval/template/{id} | GET | 模板详情（formSchema，双端动态渲染表单） |
| /api/oa/approval/submit | POST | 发起：`{ templateId, title, formData: {field:value}, billData: {...}, ccUserIds?:[] }`——`billData` 为业务单据数据（请假模板必传假别/起止时间等，结构见 D3 §2），同事务创建单据 + 实例 |
| /api/oa/approval/list | GET | 我的申请：`status`、`page`、`pageSize` |
| /api/oa/approval/todos | GET | 待我审批（status=0 任务） |
| /api/oa/approval/dones | GET | 我已审批 |
| /api/oa/approval/ccs | GET | 抄送我的 |
| /api/oa/approval/detail/{instanceId} | GET | 实例详情：单据数据 + 表单数据 + 节点/任务状态（按 form_snapshot 渲染）+ 留痕时间线（含轮次） |
| /api/oa/approval/act | POST | **审批操作（核心）**，见下 |
| /api/oa/approval/cancel/{instanceId} | POST | 撤回（仅 DRAFT、或**首个节点未处理前**，与 D1/D2 统一） |
| /api/oa/approval/resubmit/{instanceId} | POST | 被驳回至发起人后重新编辑提交：`{ formData, billData, title? }`；实例 `round` +1，旧未处理任务作废，已处理记录全保留 |

### POST /api/oa/approval/act — 审批操作契约

```jsonc
// 入参
{ "taskId": 123, "action": 1, "opinion": "同意放行" }
// action: 1=同意 2=驳回 3=转交；action=3 时必须携带 transferredTo
// { "taskId": 123, "action": 3, "opinion": "出差授权 B 处理", "transferredTo": 45 }
```

```jsonc
// 出参
{ "instanceFinished": false, "instanceStatus": 2, "advancedToSeq": 2, "todoCount": 3, "round": 1 }
// instanceFinished=true 表示流程到达终态；todoCount 为当前用户剩余待办数（前端刷新角标）
```

| 错误码 | 场景 |
| --- | --- |
| 110001 | 任务不存在或不属于当前用户（防越权） |
| 110002 | 任务已被处理（实例级锁 + 乐观锁拦截，提示刷新） |
| 110003 | 发起时未找到可用审批人（B1 阻止提交） |
| 110004 | 非法状态转移（状态机 Transition 拒绝） |
| 110005 | 转交目标人无效或处于本任务历史处理人链（防转交死循环） |
| 110006 | 请假时间与已有单据重叠（提交/重新提交时校验） |

> 幂等性：同 taskId + 同 version 重复请求返回 110002，不产生二次副作用。
> 并发防护：以实例为一致性边界，锁顺序 instance→node→task，事务内二次校验后条件更新（D2 §3.1）。
> 后置动作：审批通过/产生待办时写 Outbox 事件（同事务）异步推送（站内消息 + 小程序订阅消息），不阻塞本接口。

---

## 3. 考勤（二阶段）

| 接口 | 方法 | 端 | 说明 |
| --- | --- | --- | --- |
| /api/oa/attendance/punch | POST | 双端 | `{ "type": 1 }`；服务端校验 IP 段/时间窗；**同步事务写库**，提交成功才返回 |
| /api/oa/attendance/calendar | GET | 双端 | 个人考勤日历：`month=2026-10` → 逐日 `{status, lateMinutes, ...}`；口径分"历史已结算 / 当日暂计 / 未来未发生" |
| /api/oa/attendance/correct/submit | POST | 双端 | 发起补卡（内部创建补卡审批实例；通过后 Outbox 事件触发当日重算） |
| /admin/oa/attendance/rule/save | POST | 管理端 | 考勤规则配置（含 `effectiveDate` 生效日期；新规则不回算历史） |
| /admin/oa/attendance/report/monthly | GET | 管理端 | 月度报表：`month`、`deptId?` → 出勤/迟到/缺卡/请假统计；挂 `#[DataScope]`（deptColumn/dept 过滤，见 D2 §6.4） |
| /admin/oa/attendance/report/export | POST | 管理端 | 异步导出 Excel，出参 `{ "taskId": "..." }`，完成后站内信通知下载 |

**POST /api/oa/attendance/punch 响应（同步语义修订）**：

```jsonc
// 成功：打卡记录已落库
{ "punchTime": "2026-10-26 08:55:12", "result": "success", "recordId": 1024 }
// 重复打卡（唯一键命中）：返回当日已打卡信息，不再计一次
{ "punchTime": "2026-10-26 08:52:10", "result": "duplicated", "recordId": 1024 }
```

错误码：120001 非 IP 段打卡；120002 围栏外打卡（围栏启用时）；120004 非工作日打卡（允许但标记）。
> punch 权限校验失败属于业务拒绝，不入打卡记录表，但写入日志（可观测）。
> 打卡写入为同步事务（唯一键 `uk_user_date_type` 防重），推送/统计副作用经 Outbox 异步（D2 §3.3）。

---

## 4. 公告与消息（公告三阶段，消息一阶段）

| 接口 | 方法 | 端 | 说明 |
| --- | --- | --- | --- |
| /admin/oa/announcement/save | POST | 管理端 | 新建/编辑：`title、content、scopeType、scopeDeptIds[]、isTop、publishAt?`；content 服务端 XSS 过滤后存储 |
| /admin/oa/announcement/publish/{id} | PUT | 管理端 | 发布（publishAt 未来时间则进定时任务） |
| /admin/oa/announcement/read-stats/{id} | GET | 管理端 | 已读/未读人员列表（按部门分组） |
| /admin/oa/announcement/urge/{id} | POST | 管理端 | 一键催办：向未读人员发站内消息 |
| /api/oa/announcement/list | GET | 双端 | 移动端公告列表（置顶优先） |
| /api/oa/announcement/detail/{id} | GET | 双端 | 阅读详情（**服务端同时写入已读记录，幂等**；返回过滤后内容） |
| /api/oa/message/list | GET | 双端 | 消息盒子：`bizType?`、分页 |
| /api/oa/message/read | POST | 双端 | `{ "ids": [1,2] }` 或 `all=true` 批量已读 |
| /api/oa/message/unread-count | GET | 双端 | 未读数（角标，Redis 计数） |

> H5 提醒通道为站内消息 + 角标（Web Push 已移除：微信内置浏览器与国内浏览器环境不可用，D1 §2.5）。

---

## 5. 文档中心（管理端 + H5 只读，小程序不开放，三阶段）

| 接口 | 方法 | 端 | 说明 |
| --- | --- | --- | --- |
| /admin/oa/doc/folder/tree | GET | 管理端 | 目录树（含权限过滤：仅返回可见目录） |
| /admin/oa/doc/folder/save | POST | 管理端 | 新建目录 + 权限策略 `perm` |
| /admin/oa/doc/file/upload | POST | 管理端 | multipart 上传（白名单扩展名/大小 ≤ 100MB） |
| /admin/oa/doc/file/list | GET | 管理端 | `folderId`、`name?` 分页 |
| /admin/oa/doc/file/preview/{id} | GET | 管理端 | 返回 PDF 预览地址（无缓存则入队列转换后通知） |
| /admin/oa/doc/file/download/{id} | GET | 管理端 | 下载链接（校验目录 view 权限） |
| /api/oa/doc/folder/tree | GET | H5 | **只读目录树**（权限过滤） |
| /api/oa/doc/file/list | GET | H5 | **只读文件列表**：`folderId`、`name?` 分页 |
| /api/oa/doc/file/download/{id} | GET | H5 | **只读下载**（校验 view 权限） |
| /api/oa/doc/file/preview/{id} | GET | H5 | **只读预览**（返回 PDF 地址；无权限或未转换返回可读错误） |

错误码：130001 无目录权限；130002 文件类型不允许；130003 超大小限制。

---

## 6. 全局规范

- **权限**：管理端接口经 MineAdmin RBAC（控制器 `#[Auth]` + `#[Permission("oa:...")]` 注解 + 菜单权限）；移动端接口默认全员可访问，敏感接口（报表/公告发布/文档管理）仅管理端提供。
- **数据权限**：管理端列表/报表接口按需挂 `#[DataScope]` 注解并显式声明列映射（如 instance 表 `deptColumn='origin_dept_id'`、`createdByColumn='origin_user_id'`，day 表走冗余 `dept_id`；协程上下文隔离注意事项见 D2 §6.4）。
- **越权防护**：所有 `detail/act` 类接口必须校验资源归属（任务属于当前用户、公告在可见范围内、文档目录有 view 权限）。
- **幂等**：`act`（version 锁）、`punch`（唯一键）、`announcement/read`（唯一键）、Outbox 消费（event_id）。
- **限流**：`punch`、`sms/send`、`wechat/login` 接口加 Redis 令牌桶（每用户 10 次/分钟）。
- **文档**：Swagger 注解（控制器 `#[AutoController]` + 注解）自动生成，与本契约同步维护。
- **审计**：审批操作、后台配置变更全量写入操作日志（trace_id + user_id + 耗时）。
