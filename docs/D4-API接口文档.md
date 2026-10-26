# MineAdmin OA 系统 — API 接口文档（D4）

> 版本：v0.5｜日期：2026-10-26｜D9 A01–A16 修订后的唯一接口契约，均待实现联调
> 服务基址：https://api.example.com；三端复用同一user与业务Service，身份来源见D2

## 0. 全局契约

| 项 | 规则 |
| --- | --- |
| PC认证 | 复用 `/admin/passport/login`、getInfo、refresh、logout；default JWT，SYSTEM(100)账号，原契约不改 |
| 移动认证 | `/api/v1/oa/auth/*`，mobile JWT，查询同一SYSTEM(100)内部员工 |
| 管理业务 | `/admin/oa/*`，AccessToken/Permission/Operation中间件 + 方法级Permission(code:...) |
| 员工业务 | `/api/v1/oa/*`，mobile员工中间件 + 服务端业务资源授权 |
| 响应 | `{ "code":200, "message":"ok", "data":{} }`；业务响应HTTP200，成功仅JSON code=200 |
| HTTP错误 | 网关可能返回非2xx；客户端先区分HTTP状态，再处理业务code；六位码不能作为HTTP状态 |
| 分页 | 所有新增OA分页使用 `page=1&page_size=10`，page_size最大100；返回 `data:{list:[],total:0}` |
| 命名 | OA业务请求/DTO使用camelCase；认证token响应保留snake_case；分页page_size沿用框架 |
| ID与时间 | 新增OA接口的BIGINT ID按字符串传输；日期YYYY-MM-DD、时间Asia/Shanghai，DATETIME为YYYY-MM-DD HH:mm:ss |
| 身份 | user_id、origin_user_id、origin_dept_id等由可信Actor及主部门历史确定，忽略客户端同名身份字段 |
| 验证 | JSON或明确声明的multipart；Controller对应Request::validated()，Swagger的HyperfServer+完整路径属性注解 |
| 消息 | message为可读中文，不返回堆栈；生产关闭APP_DEBUG |

统一扩展官方 `App\Http\Common\ResultCode` 命名case，使用 `App\Exception\BusinessException`；
不使用独立OaResultCode直接替代现有强类型。401表示需要刷新/登录，403权限拒绝，422参数拒绝，423账号停用。
新增OA枚举值见§7；PC原有响应由其现有客户端处理，OA业务API层执行字段与ID适配。

## 1. 移动鉴权（一阶段）

以下路径均以 `/api/v1/oa/auth` 开头，旧的 `/api/auth`、`/api/v1/auth` 不再用于OA设计。

| 方法/路径 | 入参 | 返回data | 认证 |
| --- | --- | --- | --- |
| POST /sms/send | `{phone,purpose:"login或bind"}` | true；冷却60s、有效5min、每手机号每日10条；同时按IP限流 | 公共，短信风控 |
| POST /login | `{mode:"password",username,password}` 或 `{mode:"sms",phone,smsCode}` | TokenPair | 公共，员工有效性检查 |
| POST /wechat/login | `{code}`，微信原始登录code | 已绑定：`{bound:true,...TokenPair}`；未绑定：`{bound:false,bindTicket,expiresIn:300}` | 公共，服务端code2session |
| POST /wechat/bind | `{bindTicket,phone,smsCode}` | `{bound:true,...TokenPair}` | 公共但必须有一次性绑定票据及验证码 |
| POST /refresh | 无body，Authorization: Bearer refresh_token | TokenPair | mobile专用refresh校验，禁止使用access |
| GET /me | 无 | `{id,username,nickname,avatar,phoneMasked,departments,primaryDept,roles,wechatBound}` | mobile access |
| POST /logout | 无body，Authorization: Bearer refresh_token | true | mobile refresh；撤销关联会话及其access |
| POST /wechat/subscribe | `{templateKey,result:"accept或reject"}` | true | mobile access，三阶段，仅记录客户端报告许可 |

TokenPair固定为：

```json
{
  "access_token":"<access>",
  "refresh_token":"<refresh>",
  "access_expires_in":43200,
  "refresh_expires_in":2592000
}
```

有效期为响应时剩余秒数，受会话首次登录后30天硬上限约束；不把expire_at名称当作时间戳。
每次签发含sid/rotation，刷新锁会话并轮换，旧token对立即失效；退出持久化撤销会话。
未绑定响应不含员工token，bindTicket不携带客户端可改的openid；绑定只关联已存在员工，不自动注册。
useTokenStore通过API适配为accessToken/refreshToken/accessExpiresIn/refreshExpiresIn，
me的departments为`[{id,name}]`，primaryDept为`{id,name}`或null，roles为业务角色code字符串数组。
用户资料id映射为userId，并同步OA ID类型为string。所有公告仍需登录，无公告匿名白名单。

## 2. 审批模板与公共选择接口（一阶段）

| 方法/路径 | 说明 | 管理权限码 |
| --- | --- | --- |
| GET /admin/oa/approval/template/list | name/status/bizType + 分页 | oa:approval:template:list |
| GET /admin/oa/approval/template/{id} | 模板详情 | oa:approval:template:detail |
| POST /admin/oa/approval/template/save | `{id?,name,bizType,formSchema,nodeSchema,ccRule,allowedScope,fallbackApprover,rejectPolicy,remindDays,escalateDays}`；编辑带version | oa:approval:template:save |
| PUT /admin/oa/approval/template/change-status | `{id,status,version}`；只影响新发起 | oa:approval:template:status |
| GET /admin/oa/employee/options | keyword/deptId + 分页，仅返回允许选择的在职员工 | oa:employee:select |
| GET /admin/oa/department/options | 可配置部门树；保留业务权限范围 | oa:department:select |
| GET /admin/oa/role/options | 可选业务角色；不暴露无关系统角色 | oa:role:select |
| GET /api/v1/oa/employee/options | keyword/deptId + 分页，用于合法抄送/转交 | 员工资格 + 业务选择范围 |

保存校验：seq从1连续递增，一阶段每节点唯一处理人，枚举和字段类型白名单，兜底必须为有效业务员工，
allowedScope必须显式设置；版本条件更新失败返回110007。固定请假字段落billData，formData仅附加字段。
管理模板详情为 `/admin/oa/approval/template/{id}`；员工发起详情为两端流程前缀下的 `/templates/{id}`，不得注册同方法同路径的双控制器。
字段编辑为有限列表/JSON校验，不含拖拽设计器。前端显示可选员工不代表提交时免校验。

## 3. 审批流程（三端同Service，一阶段）

下面每一行定义两个**完整入口**：后台 `/admin/oa/approval` + 后缀，移动 `/api/v1/oa/approval` + 后缀。
后台均使用表中具体权限码；移动使用员工身份、模板范围和参与者授权。功能权限不能替代资源归属。

| 方法/后缀 | 请求/结果 | 后台权限码 |
| --- | --- | --- |
| GET /templates | 当前可发起模板 `{id,name,icon,bizType}` | oa:approval:process:templates |
| GET /templates/{id} | 可发起模板详情，附固定单据定义与formSchema | oa:approval:process:templates |
| POST /submit | `{clientRequestId,templateId,templateVersion,title,billData,formData,ccUserIds:[]}` → `{instanceId,round,status}` | oa:approval:process:submit |
| GET /list | 我的申请，status + 分页 | oa:approval:process:list |
| GET /todos | 当前轮次ACTIVE待我处理任务 + 分页 | oa:approval:process:todos |
| GET /dones | 我已处理任务，实例去重并返回最后处理轮次 + 分页 | oa:approval:process:dones |
| GET /ccs | 抄送给我的可见轮次，实例去重 + 分页 | oa:approval:process:ccs |
| GET /detail/{instanceId} | round可选，返回获授权轮次快照/单据/节点/任务/留痕与allowedActions | oa:approval:process:detail |
| POST /act | `{taskId,version,action,opinion,transferredTo?}` | oa:approval:process:act |
| POST /cancel/{instanceId} | `{version}`；DRAFT或PENDING首节点无人操作时 | oa:approval:process:cancel |
| POST /resubmit/{instanceId} | `{version,clientRequestId,title?,billData,formData,ccUserIds:[]}`，仅DRAFT | oa:approval:process:resubmit |

billData请假：`{leaveType,startTime,endTime,reason,attachments:[]}`；服务端计算durationMinutes及展示小时；一阶段使用D2版本化固定日历，不依赖二阶段考勤管理。
服务端按规范化载荷计算请求散列，发起时核对templateVersion；模板改版返回110007要求重新确认。
发起用(origin_user_id,client_request_id)唯一键；同请求号同载荷返回原结果，不同载荷返回110008。
重提幂等号记录在对应round，唯一(instance_id,client_request_id)；旧轮次输入不覆盖。
重提复用实例冻结模板，重验员工有效性与请假时间重叠（排除自身），仅提交成功时round+1。

act.action：1同意、2驳回、3转交；action3必须transferredTo。成功data示例：

```json
{"instanceFinished":false,"instanceStatus":2,"advancedToSeq":2,"todoCount":3,"round":1}
```

相同taskId/version重复处理返回110002；instance→node→task行锁，任务必须属于当前Actor及活动轮次。
状态、业务单据、快照、日志、抄送、事件与delivery按D2同事务。转交保留链条，不前进节点。
回退至发起人是DRAFT，REJECTED为终态；详情allowedActions由服务端计算，前端不猜测可操作状态。
抄送只授予指定轮次只读，不因实例ID相同而取得未获抄送的新轮次内容。

## 4. 考勤（二阶段）

| 方法/路径 | 契约 | 后台权限码 |
| --- | --- | --- |
| POST /api/v1/oa/attendance/punch | `{type:1或2}`，同步落库；服务端校验IP/时间窗，返回recordId/punchTime/result | 员工身份 |
| GET /api/v1/oa/attendance/calendar | month=YYYY-MM，逐日status/lateMinutes/settlementState | 本人 |
| POST /api/v1/oa/attendance/correct/submit | `{clientRequestId,date,type,correctTime,reason,supersedesId?}`，创建补卡实例 | 本人 |
| GET /api/v1/oa/attendance/correct/list | 本人补卡分页 | 本人 |
| GET /admin/oa/attendance/rule/list | 规则版本分页 | oa:attendance:rule:list |
| GET /admin/oa/attendance/rule/{id} | 规则版本与适用区间详情 | oa:attendance:rule:detail |
| POST /admin/oa/attendance/rule/save | `{ruleKey,baseVersion?,name,workStart,workEnd,lunchStart?,lunchEnd?,workdays,holidays,ipRanges,punchOpen,punchClose,deptIds,effectiveFrom}`；新建版本并安排切换 | oa:attendance:rule:save |
| GET /admin/oa/attendance/correct/list | 可见部门补卡记录 + 分页 | oa:attendance:correct:list |
| GET /admin/oa/attendance/report/monthly | month/deptId + 分页，部门参数必须落在授权集合 | oa:attendance:report:list |
| POST /admin/oa/attendance/report/export | `{month,deptId?}` → `{taskId}`，同事务创建导出任务与Outbox | oa:attendance:report:export |
| GET /admin/oa/attendance/report/export/{taskId} | status/errorMessage等，仅操作者，包含产物范围信息 | oa:attendance:report:export |
| GET /admin/oa/attendance/report/export/{taskId}/download | 私有文件流，再次校验操作者与产物数据范围；范围收缩时要求重生成 | oa:attendance:report:export |
| GET /admin/oa/employee/primary-department/list | userId + 历史分页 | oa:employee:department:list |
| POST /admin/oa/employee/primary-department/save | `{userId,deptId,effectiveFrom}`，唯一主部门切换，历史不覆盖；一阶段交付 | oa:employee:department:save |

原始记录唯一(user,date,type)；重复返回`result:"duplicated"`及已有卡，不覆盖时间。
补卡只改变有效修正，不改原始打卡。规则发布内容不可改，切换更新未来适用区间；普通操作不更改历史日结快照。
主部门维护接口虽列于本节，在一阶段用于审批身份归属；二阶段复用。部门历史与规则区间均按锁内校验防重叠。

## 5. 消息、公告与私有文档

### 5.1 消息（一阶段）

后台 `/admin/oa/message` 与移动 `/api/v1/oa/message` 共用后缀：
GET `/list`（bizType+分页）、POST `/read`（`{ids:[]}`或`{all:true}`）、GET `/unread-count`。
后台对应权限码分别为 oa:message:list、oa:message:read、oa:message:count；一律限定本人。
审批消息DTO返回targetRound，详情跳转保留该轮次；消息只由事件消费生成，UNIQUE(event_id,user_id,biz_type)，未读Redis计数可重建。

### 5.2 公告（三阶段）

| 方法/路径 | 说明 | 后台权限码 |
| --- | --- | --- |
| GET /admin/oa/announcement/list | 列表分页 | oa:announcement:list |
| GET /admin/oa/announcement/{id} | 管理详情 | oa:announcement:detail |
| POST /admin/oa/announcement/save | title/content/scopeType/scopeDeptIds/isTop/publishAt；服务端清洗富文本 | oa:announcement:save |
| PUT /admin/oa/announcement/publish/{id} | 发布/安排定时发布 | oa:announcement:publish |
| GET /admin/oa/announcement/read-stats/{id} | 可见范围已读统计 | oa:announcement:stats |
| POST /admin/oa/announcement/urge/{id} | 对未读者创建去重通知任务 | oa:announcement:urge |
| GET /api/v1/oa/announcement/list | 员工可见公告分页 | 登录+公告可见范围 |
| GET /api/v1/oa/announcement/detail/{id} | 员工可见内容 | 登录+公告可见范围 |
| POST /api/v1/oa/announcement/read/{id} | 显式记录已读，UNIQUE(announcement,user) | 登录+公告可见范围 |

页面加载成功后POST已读；GET不隐式产生阅读统计。公告不开放匿名访问。

### 5.3 私有附件与文档（单据附件一阶段，目录文档三阶段）

| 方法/路径 | 说明 | 后台权限码 |
| --- | --- | --- |
| POST /admin/oa/attachment/upload | multipart file+purpose，返回attachmentId/name/size；private_oa临时私有附件 | oa:attachment:upload |
| POST /api/v1/oa/attachment/upload | 同上，仅合法员工 | 员工身份+用途约束 |
| GET /admin/oa/attachment/{id}/download | 单据/目录/导出业务关系授权后私有文件流 | oa:attachment:download |
| GET /api/v1/oa/attachment/{id}/download | 同上，员工本人或所参与业务授权 | 员工资源授权 |
| GET /admin/oa/doc/folder/tree | view范围目录树 | oa:document:list |
| POST /admin/oa/doc/folder/save | name/parentId/perm，管理授权 | oa:document:manage |
| GET /admin/oa/doc/file/list | folderId/name+分页 | oa:document:list |
| POST /admin/oa/doc/file/upload | multipart file+folderId；权限upload，≤100MB、扩展名/MIME校验 | oa:document:upload |
| GET /admin/oa/doc/file/download/{id} | view授权后私有文件流 | oa:document:download |
| GET /admin/oa/doc/file/preview/{id} | 原生PDF私有文件流；Office转换未就绪返回130004 | oa:document:preview |

H5提供 `/api/v1/oa/doc/folder/tree`、`/doc/file/list`、`/doc/file/download/{id}`、`/doc/file/preview/{id}`
四个只读接口，后端统一认证授权；“H5专属”是产品入口限制，不以客户端平台字符串代替权限。
小程序不编译文档页面。下载成功返回二进制，失败返回JSON Result；客户端按Content-Type区分。
单据附件限制20MB，目录文档100MB；附件元数据不返回裸URL。未关联附件仅上传者可见，关联后校验业务读取权限；使用oa_attachment记录上传者/用途/对象键与单一业务归属。
private_oa存储是一阶段附件前置；三阶段扩展目录权限和原生PDF预览，Office转换后续启用。

## 6. 权限与工程接入

- 所有管理端控制器使用HyperfServer、明确中间件与方法级 `Permission(code:...)`；Swagger声明完整路径。
- 部门列表/报表按D2指定onlyTables、列映射、ScopeType与PolicyType，并显式处理空授权集合。
- 操作者当前用户需在DataScope执行前加载；异步导出保存申请范围，执行时取当前范围交集。
- 员工/HR/模板管理员角色种子按本表精确权限码授予；`*`仅可用于说明，不直接写入菜单权限名。
- 一阶段交付角色/菜单、办公主部门及默认策略初始化；没有有效主部门或策略的员工明确拒绝相关操作。
- 生产保留Request-Id，操作日志含user/instance/event/耗时；业务消息不包含凭据。

## 7. 固定业务错误码

| 值 | ResultCode新增case | 场景 |
| --- | --- | --- |
| 100001 | OA_AUTH_FAILED | 账号/密码或短信凭证不正确 |
| 100002 | OA_BIND_TICKET_INVALID | 绑定票据无效/过期 |
| 100101 | OA_SMS_TOO_FREQUENT | 验证码请求冷却中 |
| 100102 | OA_SMS_DAILY_LIMIT | 当日额度耗尽 |
| 101001 | OA_WECHAT_CODE_INVALID | 微信code无效 |
| 101002 | OA_WECHAT_BIND_CONFLICT | 已有绑定冲突 |
| 101003 | OA_WECHAT_BIND_SMS_INVALID | 绑定验证码无效 |
| 110001 | OA_TASK_UNAVAILABLE | 任务不存在/当前员工不可操作 |
| 110002 | OA_TASK_ALREADY_HANDLED | version或任务状态已变化 |
| 110003 | OA_APPROVER_UNAVAILABLE | 无唯一有效审批人 |
| 110004 | OA_INVALID_TRANSITION | 非法状态转移 |
| 110005 | OA_TRANSFER_INVALID | 转交目标或任务链不符合规则 |
| 110006 | OA_LEAVE_OVERLAP | 请假时间重叠 |
| 110007 | OA_TEMPLATE_VERSION_CONFLICT | 模板版本变化 |
| 110008 | OA_REQUEST_CONFLICT | 幂等请求号载荷冲突 |
| 120001 | OA_PUNCH_IP_DENIED | 打卡IP不在规则范围 |
| 120002 | OA_PUNCH_TIME_DENIED | 打卡时间窗不允许 |
| 120003 | OA_ATTENDANCE_RULE_UNAVAILABLE | 无适用规则/区间冲突 |
| 130001 | OA_FILE_FORBIDDEN | 无文件业务读取/目录权限 |
| 130002 | OA_FILE_TYPE_DENIED | 文件类型不允许 |
| 130003 | OA_FILE_TOO_LARGE | 超出用途大小上限 |
| 130004 | OA_PREVIEW_NOT_READY | 预览未就绪或未启用转换 |
| 140001 | OA_EXPORT_NOT_READY | 导出任务未完成 |

过期/撤销会话使用框架UNAUTHORIZED(401)，业务枚举不重新定义认证过期语义。
