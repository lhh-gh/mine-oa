# MineAdmin OA 系统 — API 接口文档（D4）

> 项目代号：MineOA
> 版本：v0.1（接口契约，编码前评审）｜日期：2026-10-26
> 传递格式：JSON（`Content-Type: application/json`）；鉴权：Bearer JWT（Header `Authorization: Bearer {token}`）
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
| 管理端 | `/admin/oa/*` | Vue3 后台 | 模板配置、报表、公告发布、文档管理（走 MineAdmin JWT + RBAC 中间件） |
| 移动端 | `/api/oa/*` | H5 + 小程序 | 审批、打卡、公告阅读、消息（走移动端 JWT 中间件） |
| 移动端鉴权 | `/api/auth/*` | H5 + 小程序 | 登录与绑定 |

> 双端共用业务 Service 层，仅 Controller/鉴权方式不同。错误码规范：`1000 + 模块号*100 + 序号`（如审批 1100xx）。

---

## 1. 鉴权与小程序绑定

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
| /admin/oa/approval/template/list | GET | 模板分页；参数 `name`、`status` |
| /admin/oa/approval/template/save | POST | 新建/更新模板；body 含 `formSchema[]`、`nodeSchema{nodes[]}`（结构见 D3 §1.1） |
| /admin/oa/approval/template/change-status | PUT | 启用/停用（在途实例不受影响） |

> 校验规则：`nodeSchema.nodes[].seq` 必须从 1 连续递增；`approver_type` 为 `dept_leader` 时运行期解析发起人部门负责人，解析失败接口返回 110003"未找到部门负责人"。

### 移动端（流程）

| 接口 | 方法 | 说明 |
| --- | --- | --- |
| /api/oa/approval/templates | GET | 可发起的模板列表（name+icon），缓存 5min |
| /api/oa/approval/template/{id} | GET | 模板详情（formSchema，双端动态渲染表单） |
| /api/oa/approval/submit | POST | 发起：`{ templateId, title, formData: {field:value}, ccUserIds?:[] }` |
| /api/oa/approval/list | GET | 我的申请：`status`、`page`、`pageSize` |
| /api/oa/approval/todos | GET | 待我审批（status=0 任务） |
| /api/oa/approval/dones | GET | 我已审批 |
| /api/oa/approval/ccs | GET | 抄送我的 |
| /api/oa/approval/detail/{instanceId} | GET | 实例详情：表单数据 + 节点/任务状态 + 留痕时间线 |
| /api/oa/approval/act | POST | **审批操作（核心）**，见下 |
| /api/oa/approval/cancel/{instanceId} | POST | 撤回（仅 DRAFT、或首节点未处理前） |
| /api/oa/approval/resubmit/{instanceId} | POST | 被驳回至发起人后重新编辑提交：`{ formData, title? }` |

### POST /api/oa/approval/act — 审批操作契约

```jsonc
// 入参
{ "taskId": 123, "action": 2, "opinion": "同意，注意交接工作", "transferredTo": 45 }
// action: 1=同意 2=驳回 3=转交；action=3 时 transferredTo 必填
```

```jsonc
// 出参
{ "instanceFinished": false, "instanceStatus": 2, "advancedToSeq": 2, "todoCount": 3 }
// instanceFinished=true 表示流程到达终态；todoCount 为当前用户剩余待办数（前端刷新角标）
```

| 错误码 | 场景 |
| --- | --- |
| 110001 | 任务不存在或不属于当前用户（防越权） |
| 110002 | 任务已被处理（乐观锁/分布式锁拦截，提示刷新） |
| 110004 | 非法状态转移（状态机 Transition 拒绝） |
| 110005 | 转交目标人无效 |

> 幂等性：同 taskId + 同 version 重复请求返回 110002，不产生二次副作用。
> 后置动作：审批通过产生待办时入队列异步推送（H5 角标 + 小程序订阅消息），不阻塞本接口。

---

## 3. 考勤

| 接口 | 方法 | 端 | 说明 |
| --- | --- | --- | --- |
| /api/oa/attendance/punch | POST | 双端 | `{ "type": 1, "lat": 30.1, "lng": 120.2 }`；服务端校验 IP 段/围栏/时间窗；**异步落库**，即返 `{ "punchTime": "...", "result": "success" }` |
| /api/oa/attendance/calendar | GET | 双端 | 个人考勤日历：`month=2026-10` → 逐日 status（正常/迟到/缺卡/请假…） |
| /api/oa/attendance/correct/submit | POST | 双端 | 发起补卡（内部创建补卡审批实例） |
| /admin/oa/attendance/rule/save | POST | 管理端 | 考勤规则配置 |
| /admin/oa/attendance/report/monthly | GET | 管理端 | 月度报表：`month`、`deptId?` → 出勤/迟到/缺卡/请假统计 |
| /admin/oa/attendance/report/export | POST | 管理端 | 异步导出 Excel，出参 `{ "taskId": "..." }`，完成后站内信通知下载 |

错误码：120001 非 IP 段打卡；120002 围栏外打卡；120003 重复打卡；120004 非工作日打卡（允许但标记）。
> punch 权限校验失败属于业务拒绝，不入打卡记录表，但写入日志（可观测）。

---

## 4. 公告与消息

| 接口 | 方法 | 端 | 说明 |
| --- | --- | --- | --- |
| /admin/oa/announcement/save | POST | 管理端 | 新建/编辑：`title、content、scopeType、scopeDeptIds[]、isTop、publishAt?` |
| /admin/oa/announcement/publish/{id} | PUT | 管理端 | 发布（publishAt 未来时间则进定时任务） |
| /admin/oa/announcement/read-stats/{id} | GET | 管理端 | 已读/未读人员列表（按部门分组） |
| /admin/oa/announcement/urge/{id} | POST | 管理端 | 一键催办：向未读人员发站内消息 |
| /api/oa/announcement/list | GET | 双端 | 移动端公告列表（置顶优先） |
| /api/oa/announcement/detail/{id} | GET | 双端 | 阅读详情（**服务端同时写入已读记录，幂等**） |
| /api/oa/message/list | GET | 双端 | 消息盒子：`bizType?`、分页 |
| /api/oa/message/read | POST | 双端 | `{ "ids": [1,2] }` 或 `all=true` 批量已读 |
| /api/oa/message/unread-count | GET | 双端 | 未读数（角标，Redis 计数） |

---

## 5. 文档中心（管理端 + H5，小程序不开放）

| 接口 | 方法 | 说明 |
| --- | --- | --- |
| /admin/oa/doc/folder/tree | GET | 目录树（含权限过滤：仅返回可见目录） |
| /admin/oa/doc/folder/save | POST | 新建目录 + 权限策略 `perm` |
| /admin/oa/doc/file/upload | POST | multipart 上传（白名单扩展名/大小 ≤ 100MB） |
| /admin/oa/doc/file/list | GET | `folderId`、`name?` 分页 |
| /admin/oa/doc/file/preview/{id} | GET | 返回 PDF 预览地址（无缓存则入队列转换后通知） |
| /admin/oa/doc/file/download/{id} | GET | 下载链接（校验目录 view 权限） |

错误码：130001 无目录权限；130002 文件类型不允许；130003 超大小限制。

---

## 6. 全局规范

- **权限**：管理端接口经 MineAdmin RBAC（菜单权限 + API 权限 + 数据权限按部门过滤）；移动端接口默认全员可访问，敏感接口（报表/公告发布）仅管理端提供。
- **越权防护**：所有 `detail/act` 类接口必须校验资源归属（任务属于当前用户、公告在可见范围内）。
- **幂等**：`act`（version 锁）、`punch`（唯一键）、`announcement/read`（唯一键）。
- **限流**：`punch`、`sms` 发送、`wechat/login` 接口加 Redis 令牌桶（每用户 10 次/分钟）。
- **文档**：Swagger 注解（Hyperf `#[AutoController]` + MineAdmin 注解）自动生成，与本契约同步维护。
- **审计**：审批操作、后台配置变更全量写入操作日志（trace_id + user_id + 耗时）。
