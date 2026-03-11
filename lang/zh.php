<?php
// lang/zh.php
declare(strict_types=1);

return [

    // ----- Global common (admin + portal 通用) -----
    'common' => [
        'status' => '状态',
        'cancel' => '取消',
        'save'   => '保存',
        'yes'    => '是',
        'no'     => '否',
        'apply'  => '应用',
        'reset'  => '重置',
        'date_range' => '日期范围',
        'select_date_range' => '选择日期范围',

        'mo' => '一',
        'tu' => '二',
        'we' => '三',
        'th' => '四',
        'fr' => '五',
        'sa' => '六',
        'su' => '日',

        'month_year' => '月份 YYYY',

        'today' => '今天',
        'yesterday' => '昨天',
        'this_week' => '本周',
        'last_week' => '上周',
        'this_month' => '本月',
        'last_month' => '上月',
        'this_year' => '今年',
        'last_year' => '去年',
        'all' => '全部',
    ],

    // =========================
    // ADMIN BLOCK
    // =========================
    'admin' => [

        // ----- Header / Footer -----
        'header' => [
            'app_title'      => '后台管理',
            'toggle_sidebar' => '切换侧边栏',
            'logout'         => '登出',
        ],
        'footer' => [
            'rights' => '版权所有。',
        ],

        // ----- Reusable common labels -----
        'common' => [
            'apply'     => '应用',
            'reset'     => '重置',
            'prev'      => '上一页',
            'next'      => '下一页',
            'save'      => '保存',
            'cancel'    => '取消',
            'search'    => '搜索',
            'export'    => '导出',
            'actions'   => '操作',
            'edit'      => '编辑',
            'all'       => '全部',
            'page_line' => '第 %d 页 / 共 %d 页',
            'inactive'  => '停用',
            'active'    => '启用',
            'back'      => '返回',
            'yes'       => '是',
            'no'        => '否',

            'delete'   => '删除',
            'view'     => '查看',
            'status'   => '状态',
        ],

        // ----- Navigation -----
        'nav' => [
            'section' => [
                'overview'  => '总览',
                'customers' => '客户',
                'payers'    => '付款方',
                'reports'   => '报表',
                'security'  => '安全',
                'bank' => '银行',
            ],
            'dashboard'          => '仪表板',
            'customer_list'      => '客户列表',
            'payer_companies'    => '付款公司',
            'payer_staff'        => '付款职员',
            'transaction_report' => '交易报表',
            'customer_report'    => '客户报表',
            'users'              => '后台用户',
            'roles'              => '角色与权限',
            'audit_logs'         => '审计日志',

            'bank' => [
                'accounts'     => '银行账户',
                'transactions' => '银行交易',
            ],
        ],

        // ----- Dashboard -----
        'dashboard' => [
            'title'       => '仪表板',
            'eyebrow'     => '总览',

            'pending_sig' => '待',

            'this_month'  => '本月',
            'total_in'    => '总 IN',
            'total_out'   => '总 OUT',
            'pending'     => '待收',

            'all_time'        => '累计',
            'return_balance'  => '回款余额',
            'bonus'           => '奖金',

            'bank_total' => '银行当前总余额',
            'no_bank'    => '暂无启用的银行账户。',

            'charts'    => '图表',
            'pie_title' => '本月 IN vs OUT',
            'bar_title' => '最近 6 个月（IN / OUT）',
            'total'     => '合计',

            'bank_in'    => '银行 IN',
            'latest_in'  => '最新银行 IN',
            'bank_out'   => '银行 OUT',
            'latest_out' => '最新银行 OUT',

            'col' => [
                'date'   => '日期',
                'bank'   => '银行',
                'desc'   => '说明',
                'amount' => '金额',
                'ref'    => '参考号',
            ],

            'in_empty'  => '暂无银行 IN 交易。',
            'out_empty' => '暂无银行 OUT 交易。',
        ],

        // ----- Audit Logs -----
        'audit' => [
            'title'    => '审计日志',
            'eyebrow'  => '安全',
            'subtitle' => '记录谁在什么时间做了什么。每页显示 %d 条日志。',
            'filter' => [
                'user'        => '用户',
                'user_all'    => '所有用户',
                'action'      => '动作',
                'action_all'  => '所有动作',
                'keyword'     => '关键字',
                'keyword_ph'  => '搜索描述 / 实体 / extra...',
            ],
            'total_line' => '总计：%d 条记录 · 每页 %d 条',
            'page_line'  => '第 %d 页 / 共 %d 页',
            'col' => [
                'time'        => '时间',
                'user'        => '用户',
                'action'      => '动作',
                'entity'      => '实体',
                'description' => '描述',
                'extra'       => 'Extra',
                'ip'          => 'IP',
            ],
            'empty'          => '该筛选条件下没有审计记录。',
            'system_unknown' => '系统 / 未知',
        ],

        // ----- Customer common status -----
        'customer' => [
            'status' => [
                'active'   => '启用',
                'inactive' => '停用',
            ],
        ],

        // =========================
        // ROLES & PERMISSIONS
        // =========================
        'roles' => [
            'list' => [
                'title'    => '角色与权限',
                'eyebrow'  => '安全',
                'subtitle' => '设置权限组并分配给用户。',
                'new_btn'  => '+ 新角色',
                'col' => [
                    'code'    => '代码',
                    'name'    => '名称',
                    'status'  => '状态',
                    'actions' => '操作',
                ],
                'empty' => '未找到角色。',
            ],
            'edit' => [
                'title_new'  => '新角色：%s',
                'title_edit' => '编辑角色：%s',
                'eyebrow'  => '角色与权限',
                'subtitle' => '管理角色资料并勾选需要的权限。',
                'badge_id' => 'ID：%d',
                'saved'    => '角色与权限已保存。',
                'error' => [
                    'code_required'  => '代码为必填。',
                    'name_required'  => '角色名称为必填。',
                    'code_unique'    => '该代码已被其他角色使用。',
                    'save_failed'    => '保存失败',
                ],
                'section' => [
                    'details_title' => '角色资料',
                    'details_desc'  => '代码与名称用于识别该角色。',
                    'perms_title'   => '权限',
                    'perms_desc'    => '勾选 / 取消勾选下方权限。',
                ],
                'field' => [
                    'code'        => '代码',
                    'name'        => '角色名称',
                    'description' => '描述',
                    'active'      => '启用',
                ],
                'group' => [
                    'all'  => '全选',
                    'none' => '全不选',
                ],
                'save_btn'        => '保存权限',
                'title_new_label' => '新角色',
            ],
        ],

        // =========================
        // INTERNAL ADMIN USERS
        // =========================
        'users_admin' => [
            'list' => [
                'title'    => '内部用户',
                'eyebrow'  => '安全',
                'subtitle' => '管理后台账号及其角色。',
                'new_btn'  => '+ 新用户',
                'search_ph' => '搜索 username / 姓名 / email',
                'col' => [
                    'username' => '用户名',
                    'fullname' => '姓名',
                    'email'    => '邮箱',
                    'roles'    => '角色',
                    'status'   => '状态',
                    'actions'  => '操作',
                ],
                'empty'    => '未找到用户。',
                'no_roles' => '（无角色）',
            ],
            'edit' => [
                'eyebrow' => '安全',
                'title_new'  => '新用户',
                'title_edit' => '编辑用户',
                'subtitle' => '内部后台账号与角色。',
                'back_to_list' => '← 返回用户列表',
                'section' => [
                    'account_title' => '账号',
                    'account_desc'  => 'Username 与 password 用于登录。',
                    'profile_title' => '资料',
                    'roles_title'   => '角色',
                    'roles_desc'    => '一个用户可拥有多个角色，权限来自角色。',
                ],
                'field' => [
                    'username'         => '用户名',
                    'password_new'     => '密码',
                    'password_change'  => '新密码',
                    'confirm_password' => '确认密码',
                    'full_name'        => '姓名',
                    'email'            => '邮箱',
                    'phone'            => '电话',
                    'nric'             => 'NRIC',
                    'status'           => '状态',
                    'status_active'    => '启用',
                ],
                'error' => [
                    'username_required' => '用户名必填',
                    'fullname_required' => '姓名必填',
                    'password_required' => '密码必填',
                    'password_mismatch' => '密码与确认密码不一致',
                    'username_used'     => '用户名已被使用',
                ],
                'no_roles_defined' => '尚未定义角色，请先创建至少一个角色。',
            ],
        ],

        // =========================
        // PAYER COMPANY
        // =========================
        'payer_company' => [
            'title'      => '付款公司',
            'edit_title' => '编辑付款公司',
            'new_title'  => '新付款公司',
            'eyebrow'    => '主数据',
            'subtitle'   => '付款公司的基本信息。',
            'error' => [
                'name_required' => '公司名称必填。',
            ],
            'field' => [
                'name'   => '公司名称',
                'reg_no' => '注册号',
            ],
            'action' => [
                'new' => '+ 新公司',
            ],
            'search' => [
                'ph' => '按名称 / 注册号搜索...',
            ],
            'col' => [
                'id'         => 'ID',
                'name'       => '公司名称',
                'reg_no'     => '注册号',
                'created_at' => '创建时间',
            ],
            'empty' => '未找到付款公司。',
        ],

        // =========================
        // PAYER STAFF
        // =========================
        'payer_staff' => [
            'title'      => '付款职员',
            'edit_title' => '编辑付款职员',
            'new_title'  => '新付款职员',
            'eyebrow'    => '主数据',
            'subtitle'   => '可供任意付款公司选择的签字职员。',
            'error' => [
                'name_required' => '职员姓名必填。',
            ],
            'field' => [
                'name'   => '姓名',
                'ic'     => 'IC / 护照',
                'phone'  => '电话',
                'email'  => '邮箱',
                'active' => '启用',
            ],
            'action' => [
                'new' => '+ 新职员',
            ],
            'search' => [
                'ph' => '按姓名 / IC / 电话搜索...',
            ],
            'col' => [
                'id'     => 'ID',
                'name'   => '姓名',
                'ic'     => 'IC / 护照',
                'phone'  => '电话',
                'email'  => '邮箱',
                'status' => '状态',
            ],
            'status' => [
                'active'   => '启用',
                'inactive' => '停用',
            ],
            'empty' => '未找到付款职员。',
        ],

        // =========================
        // REPORTS
        // =========================
        'report' => [
            'eyebrow' => '报表',

            'customer_list' => [
                'title' => '客户列表报表',
                'filter' => [
                    'search'      => '搜索',
                    'search_ph'   => '按代码 / 名称搜索...',
                    'only_active' => '只显示启用',
                ],
                'col' => [
                    'id'        => 'ID',
                    'customer'  => '客户',
                    'code'      => '代码',
                    'in_after'  => 'IN 总额（对冲后）',
                    'out_after' => 'OUT 总额（对冲后）',
                    'net'       => '净额（IN - OUT）',
                    'status'    => '状态',
                ],
                'empty'       => '该筛选条件下没有客户。',
                'total_label' => '合计',
            ],

            'transaction' => [
                'title' => '交易报表',
                'filter' => [
                    'customer'       => '客户',
                    'customer_all'   => '全部客户',
                    'type'           => '类型',
                    'status'         => '状态',
                    'method'         => '方式',
                    'contra'         => '对冲',
                    'contra_only'    => '只看对冲',
                    'contra_without' => '对冲后（隐藏已 fully allocated）',
                ],
                'metric' => [
                    'total_in_label'  => 'IN 总额（筛选后）',
                    'total_in_sub'    => '符合当前筛选条件的所有 IN 交易合计。',
                    'total_out_label' => 'OUT 总额（筛选后）',
                    'total_out_sub'   => '符合当前筛选条件的所有 OUT 交易合计。',
                    'net_label'       => '净额（IN - OUT）',
                    'net_sub'         => '正数 = IN 多于 OUT。',
                ],
                'details' => [
                    'eyebrow' => '明细',
                    'title'   => '交易',
                    'badge'   => '总计：%d 条 · 每页 %d 条',
                ],
                'col' => [
                    'date'        => '日期',
                    'customer'    => '客户',
                    'type'        => '类型',
                    'amount'      => '金额',
                    'method'      => '方式',
                    'ref'         => '参考号',
                    'title_notes' => '标题 / 备注',
                ],
                'flag' => [
                    'contra' => '对冲',
                ],
                'empty' => '该筛选条件下没有交易。',
            ],
        ],

        // =========================
        // Legacy payers (for compatibility)
        // =========================
        'payers' => [
            'company_list_title'   => '付款公司',
            'company_edit_title'   => '编辑付款公司',
            'company_new_title'    => '新付款公司',
            'company_name'         => '公司名称',
            'company_reg_no'       => '注册号',
            'company_basic_info'   => '付款公司的基本信息。',
            'company_add'          => '+ 新公司',

            'staff_list_title'     => '付款职员',
            'staff_edit_title'     => '编辑付款职员',
            'staff_new_title'      => '新付款职员',
            'staff_name'           => '姓名',
            'staff_ic'             => 'IC / 护照',
            'staff_phone'          => '电话',
            'staff_email'          => '邮箱',
            'staff_active'         => '启用',
            'staff_inactive'       => '停用',
            'staff_add'            => '+ 新职员',
            'staff_basic_info'     => '可用于任意付款公司的签字职员',

            'search_placeholder_company' => '按名称 / 注册号搜索...',
            'search_placeholder_staff'   => '按姓名 / IC / 电话搜索...',
        ],

        'reports' => [
            'customer_list_title' => '客户列表报表',
            'transaction_title'   => '交易报表',

            'reports'       => '报表',
            'details'       => '明细',
            'export'        => '导出',
            'search'        => '搜索',
            'only_active'   => '只显示启用',
            'customer'      => '客户',

            'txn_type'      => '类型',
            'txn_status'    => '状态',
            'txn_method'    => '方式',
            'txn_contra'    => '对冲',

            'all'           => '全部',
            'in_only'       => '只看 IN',
            'out_only'      => '只看 OUT',
            'contra_only'   => '只看对冲',
            'after_contra'  => '对冲后（隐藏已 fully allocated）',

            'total_in'      => 'IN 总额（筛选后）',
            'total_out'     => 'OUT 总额（筛选后）',
            'net'           => '净额（IN - OUT）',

            'no_data'       => '没有数据。',
        ],

        // =========================
        // CUSTOMERS
        // =========================
        'customers' => [
            'list' => [
                'title'     => '客户',
                'eyebrow'   => '主数据',
                'new_btn'   => '+ 新客户',
                'saved'     => '客户已保存。',
                'search_ph' => '按代码 / 名称 / 注册号搜索...',
                'col' => [
                    'code'      => '代码',
                    'name'      => '名称',
                    'in_after'  => 'IN（对冲后）',
                    'out_after' => 'OUT（实际支付）',
                    'net_after' => '净额（对冲后）',
                    'status'    => '状态',
                    'actions'   => '操作',
                ],
                'reg_prefix'           => 'Reg:',
                'empty'                => '未找到客户。',
                'net_label_we_owe'     => '我们欠客户',
                'net_label_cust_owe'   => '客户欠我们',
                'net_label_balanced'   => '平衡',
                'action_txn'           => '交易',
                'action_users'         => '登录用户',
                'action_edit'          => '编辑客户',
                'return_outstanding'   => '客户欠我们',
                'return_balanced'      => '平衡',
                'return_fully'         => '已全数归还',
            ],
            'edit' => [
                'title_new'       => '新客户',
                'title_edit'      => '编辑客户',
                'title_new_label' => '新客户',
                'title_fallback'  => '客户',
                'eyebrow_new'  => '创建客户资料',
                'eyebrow_edit' => '更新客户资料',
                'subtitle'     => '公司基础信息、联系人与默认收据签名信息。',
                'section' => [
                    'basic_title'    => '基本信息',
                    'basic_desc'     => '内部代码与公司注册名称。',
                    'contact_title'  => '联系人 & 收据',
                    'contact_desc'   => '默认联系人与收据签名人。',
                    'address_title'  => '地址',
                    'address_desc'   => '注册地址或账单地址。',
                ],
                'field' => [
                    'code'                 => '代码',
                    'name'                 => '名称',
                    'reg_no'               => '注册号',
                    'billing_name'         => '账单名称',
                    'contact_name'         => '联系人姓名',
                    'contact_phone'        => '联系人电话',
                    'contact_email'        => '联系人邮箱',
                    'default_receipt_name' => '默认收据签名人',
                    'default_receipt_nric' => '默认收据签名人 NRIC',
                    'address1'             => '地址行 1',
                    'address2'             => '地址行 2',
                    'address3'             => '地址行 3',
                    'postcode'             => '邮编',
                    'city'                 => '城市',
                    'state'                => '州属',
                    'country'              => '国家',
                    'status_active'        => '启用',
                ],
                'error' => [
                    'code_required' => '代码必填',
                    'name_required' => '名称必填',
                    'code_unique'   => '代码已存在',
                ],
            ],
        ],

        // =========================
        // TXN ALLOCATE 文案
        // =========================
        'txn_allocate' => [
            'page_title' => '分配 IN',
            'eyebrow_allocation' => '分配这笔交易',
            'heading_allocate_in_from' => '分配 IN 来源',
            'subtitle_allocation' => '把这笔 IN 的部分金额分配给其他客户。',
            'label_txn_no' => 'Txn #',

            'section_source_txn_title' => '来源交易',
            'section_source_txn_desc'  => '你正在从这笔 IN 记录进行分配。',

            'label_customer'  => '客户：',
            'label_date'      => '日期：',
            'label_title'     => '标题：',
            'label_amount'    => '金额：',
            'label_allocated' => '已分配：',
            'label_remaining' => '剩余：',
            'label_attachment' => '附件：',
            'link_view_file'  => '查看文件',

            'section_allocate_to_title' => '分配到其他客户',
            'section_allocate_to_desc'  => '选择目标客户与分配金额。',

            'field_target_customer'  => '目标客户',
            'option_select_customer' => '— 选择客户 —',
            'field_alloc_amount'     => '分配金额',
            'btn_allocate'           => '分配',

            'error_target_same_as_source'    => '目标客户不能与来源客户相同。',
            'error_target_customer_required' => '请选择目标客户。',
            'error_amount_gt_zero'           => '金额必须大于 0。',
            'error_amount_exceeds_remaining' => '金额超过可用余额。',
            'error_target_not_found'         => '找不到目标客户。',
            'error_allocation_failed'        => '分配失败',
        ],

        'txn_allocate_fifo' => [
            'page_title' => 'FIFO 分配',
            'eyebrow'    => '分配 IN 余额（FIFO）',
            'subtitle'   => '使用该客户剩余的 IN 余额按 FIFO（最早的 IN 先用）分配给其他客户。',

            'section_source_title' => '来源 IN（FIFO 池）',
            'section_source_desc'  => '这些 IN 仍有余额并会按 FIFO 使用。只会分配相同货币。',
            'no_source'            => '没有任何还有余额的 IN，无法分配。',

            'section_allocate_to_title' => '分配到其他客户',
            'section_allocate_to_desc'  => '选择目标客户、货币与分配金额。仅使用所选货币的 IN 余额。',

            'field_target_customer'  => '目标客户',
            'option_select_customer' => '— 选择客户 —',
            'field_alloc_amount'     => '分配金额',
            'btn_allocate' => '分配（FIFO）',

            'error_target_same_as_source'    => '目标客户不能与来源客户相同。',
            'error_target_customer_required' => '请选择目标客户。',
            'error_amount_gt_zero'           => '金额必须大于 0。',
            'error_amount_exceeds_available' => '金额超过可用余额。',
            'error_target_not_found'         => '找不到目标客户。',
            'error_allocation_failed'        => 'FIFO 分配失败',
        ],

        // =========================
        // CUSTOMER TXN (admin side)
        // =========================
        'customer_txn' => [
            'page_title' => [
                'new'  => '新交易',
                'edit' => '编辑交易',
            ],
            'header' => [
                'eyebrow_new'   => '新交易',
                'eyebrow_edit'  => '编辑交易',
                'subtitle_new'  => '为该客户记录新的 IN / OUT 资金流动。',
                'subtitle_edit' => '更新该笔资金流动的细节。',
            ],
            'back_to_list' => '返回交易列表',

            'basic' => [
                'title' => '基本信息',
                'desc'  => '日期、类型（IN / OUT）、方式与金额。',
            ],

            'out_kind' => [
                'label'  => 'OUT 类型',
                'normal' => '普通 OUT',
                'loan'   => '借款 / 垫付给客户',
                'help'   => 'NORMAL = 出款；LOAN = 借款 / 垫付款',
            ],

            'status_help' => [
                'customer_autosent' => '若选择“另一位客户代付”，保存会自动变成 SENT（待处理）。',
            ],

            'pay_source' => [
                'label' => '付款来源',
                'bank'  => '银行 / 现金',
                'customer' => '另一位客户（代付）',
                'help' => '选择“另一位客户”= 系统会为该客户（B）创建一笔新的 IN 回款，并减少 B 的 Return balance。',
                'paying_customer' => '代付客户',
                'paying_customer_ph' => '— 选择客户 —',
            ],

            'fx' => [
                'amount_in_base_label' => ':base 金额（仅供参考）',
            ],

            'field' => [
                'date'     => '日期',
                'type'     => '类型',
                'method'   => '方式',
                'currency' => '货币',
                'amount'   => '金额',
                'status'   => '状态',
                'ref_no'   => '参考号',
                'title'    => '标题',
                'notes'    => '备注',

                'bank_account'     => '银行 / 现金账户',
                'bank_placeholder' => '— 选择银行 / 现金 —',
            ],

            'type' => [
                'in'  => 'IN（入账 / 分配）',
                'out' => 'OUT（付款给客户）',
            ],

            'method' => [
                'cash'  => '现金',
                'bank'  => '银行',
                'usdt'  => 'USDT',
                'other' => '其他',
            ],

            'status' => [
                'auto_confirm' => 'CONFIRMED（IN 自动）',
                'draft'        => 'DRAFT',
                'sent'         => 'SENT',
                'confirmed'    => 'CONFIRMED',
            ],

            'error' => [
                'date_required'   => '日期必填',
                'type_invalid'    => '类型无效',
                'method_invalid'  => '方式无效',
                'amount_gt_zero'  => '金额必须大于 0',
                'paying_customer_required' => '请选择代付客户。',
                'paying_customer_same'     => '代付客户不能与对方客户相同。',
                'fx_rate_required'         => '当货币不是 :base 时，必须填写汇率。',
            ],

            'payer' => [
                'title' => '付款方（我们）',
                'desc'  => '选择哪间公司付款，以及由谁代表我们签名。职员可跨公司使用。',
                'company'             => '付款公司',
                'company_placeholder' => '— 选择公司 —',
                'staff'               => '付款职员 / 签字人',
                'staff_placeholder'   => '— 选择职员 —',
            ],

            'parties' => [
                'title'        => '相关方',
                'desc'         => '对方固定来自客户；收款人（签名者）仅 OUT 需要填写。',
                'counterparty' => '对方（固定）',
            ],

            'recipient' => [
                'name'        => '收款人姓名（签名者）',
                'placeholder' => '输入或从登录用户选择...',
                'nric'        => '收款人 NRIC',
                'tip'         => '从登录用户选择会自动填入 NRIC，也可手动填写。',
            ],

            'desc' => [
                'title' => '描述与附件',
                'desc'  => '参考号、列表标题、备注，以及 PDF / 图片附件。',
            ],

            'title' => [
                'placeholder_in'  => '默认：客户名称',
                'placeholder_out' => '默认：收据',
            ],

            'attach' => [
                'all'      => '附件（PDF / 图片）',
                'helper'   => '你可以上传一个或多个文件，文件会列在下方。',
                'existing' => '已存在文件',
                'delete'   => '删除',

                'multi_error'      => '文件“%s”上传错误（代码 %d）。',
                'multi_invalid'    => '文件“%s”已跳过（类型不允许）。',
                'multi_move_fail'  => '文件“%s”移动失败。',
                'multi_db_fail'    => '部分附件保存到数据库失败。',
            ],

            'sign' => [
                'title'       => '签名要求',
                'desc'        => '只适用于非对冲的 OUT。IN 会忽略该设置。',
                'require'     => '需要客户在收据上签名',
                'contra_note' => '该 OUT 为对冲（分配生成），不需要签名。',
                'in_note'     => 'IN 交易签名自动关闭。',
            ],

            'badge' => [
                'contra' => '对冲',
                'loan'             => '借款 / 垫付',
                'paid_by_customer' => '由客户（B）代付',
            ],

            'list' => [
                'page_title' => '客户交易：%s',
                'eyebrow'    => '客户',
                'subtitle'   => '查看 IN / OUT，可按日期和对冲视图筛选。',
                'user_detail_btn'   => '用户详情',
                'back_to_customers' => '返回客户列表',
                'new_btn'           => '+ 新交易',

                'alloc_ok' => '分配完成（来源已更新并生成对冲记录）。',
                'save_ok'  => '交易已保存。',

                'summary' => [
                    'eyebrow'   => '汇总',
                    'before'    => '未对冲',
                    'after'     => '对冲后',
                    'total_in'  => 'IN 总额',
                    'total_out' => 'OUT 总额',
                    'net'       => '净额',
                ],

                'filter' => [
                    'type'        => '类型',
                    'contra_view' => '对冲视图',
                ],

                'available_to_alloc' => '可分配：',
                'payer_label'        => '付款方：',
                'staff_label'        => '职员：',

                'action_view'     => '查看',
                'action_allocate' => '分配',

                'empty' => '该筛选条件下没有交易。请尝试其他日期范围。',
            ],

            'view' => [
                'page_title'   => '收据 / 确认',
                'eyebrow_in'   => 'IN 交易',
                'eyebrow_out'  => '收据预览',
                'txn_label'    => '交易',
                'print_btn'    => '打印 / PDF',
                'sign_saved'   => '签名已保存。',

                // ✅ 新增：INVOICE 提示（你 txn_view.php 若有用到就不会报 undefined）
                'invoice_info' => "此页面主要用于 OUT / RETURN / BONUS 的收据预览。\nINVOICE 类型请使用你原本的发票打印页面。",
                // ✅ 新增：没 items 时提示
                'no_items' => '没有可显示的收据项目。',

                'in' => [
                    'title'    => 'IN 交易详情',
                    'desc'     => 'IN 不需要收据；附件用作支持文件。',
                    'no_notes' => '无备注',
                ],

                'attach' => [
                    'tip'   => '点击文件查看或下载。',
                    'none'  => '无附件',
                    'title' => '附件：',
                ],

                'receipt_title'   => '收据',
                'received_from'   => '收款自（付款方）：',
                'rep'             => '代表：',
                'nric'            => 'NRIC：',
                'received_by'     => '收款人 / 代表：',
                'address'         => '地址：',
                'recipient'       => '收款人（签名者）：',
                'recipient_fill'  => '（请填写客户签名人姓名）',
                'receipt_confirm' => '本收据确认上述金额已收讫。',

                'method_bank'  => '银行转账',
                'method_other' => '其他',

                // ✅ 关键：分 IN / OUT 两套标题（你说“还是一样”就是因为没分方向）
                // OUT：客户收钱（签名=客户），我方付款（签名=我方公司）
                'sig_customer_title_out' => '签名（收款人 / 客户）',
                'sig_payer_title_out'    => '签名（付款方 / 我方公司）',

                // IN：我方收钱（签名=我方公司），客户付款（签名=客户）
                'sig_customer_title_in'  => '签名（收款方 / 我方公司）',
                'sig_payer_title_in'     => '签名（付款方 / 客户）',

                // 通用 none 文案
                'sig_customer_none'  => '尚未有客户签名。',
                'sig_payer_none'     => '尚未有付款方签名。',

                // ✅ 新增：底部显示用（你 txn_view.php 若用到）
                'name_nric' => '姓名 / NRIC：',
                'sign_date' => '日期：',

                // 旧 key 仍保留（避免你其它页面还在用）
                'name_label' => '姓名：',
                'date_label' => '日期：',

                'sign_here_title' => '在此签名',
                'sign_here_desc'  => '任何一方可先签。只有客户签名后，状态才会变成 CONFIRMED。',

                'canvas_customer_tip' => '客户 – 请在框内签名',
                'canvas_payer_tip'    => '我方公司 – 请在框内签名',
                'clear_btn'           => '清除',
                'save_signatures'     => '保存签名',
                'sign_done'           => '签名已记录 / 无需再签。',

                'error' => [
                    'sign_required' => '请至少完成一方签名后再保存。',
                ],
            ],

            'select' => [
                'eyebrow'       => '新交易',
                'subtitle'      => '选择 IN（客户付钱进来）、OUT（付钱给客户），或使用 FIFO 分配 IN 余额到其他客户。',
                'section_title' => '选择动作',
                'section_desc'  => 'IN = 存入 / 充值 / 收款；OUT = 退款 / 提现 / 结算；Allocate = 用剩余 IN（FIFO）对冲其他客户。',

                'in_title' => 'IN（进账）',
                'in_desc'  => '客户付款进来时使用，可全额或部分。',
                'in_btn'   => '+ 新建 IN 交易',

                'out_title' => 'OUT（出账）',
                'out_desc'  => '你付款给客户时使用（退款/提现/结算等）。',
                'out_btn'   => '+ 新建 OUT 交易',

                'alloc_title' => '分配（FIFO）',
                'alloc_desc'  => '把该客户的 IN 余额按 FIFO 分配给其他客户（最早 IN 先用）。',
                'alloc_btn'   => '→ FIFO 分配',
            ],

            'out' => [
                'subtitle' => '记录/更新付给该客户的款项（退款、提现、结算等）。',
                'save_btn' => '保存 OUT',
            ],

            'bank' => [
                'load_error' => '载入银行账户出错：%s',
                'none'       => 'company_bank_accounts 表里没有银行账户。',
                'helper_out' => '请选择使用哪个银行/现金账户付款。',
            ],

            'list' => [
                'title'   => '客户交易',
                'eyebrow' => '客户',
                'subtitle' => '查看发票/出款、客户付款与 contra 分配记录。',
                'new_btn' => '+ 新增交易',
                'back_to_customers' => '返回客户列表',
                'user_detail_btn'   => '用户资料',

                'save_ok'   => '交易已保存。',
                'delete_ok' => '交易已删除。',
                'alloc_ok'  => '分配完成（已更新来源并创建 contra 记录）。',

                'empty' => '此筛选条件暂无交易。请尝试更换日期范围。',

                'filter' => [
                    'type'        => '类型',
                    'search'      => '搜索',
                    'contra_view' => 'Contra 视图',
                ],

                'summary' => [
                    'eyebrow'        => '汇总',
                    'after'          => '扣除 contra 后',
                    'before'         => '未扣除 contra（未使用）',
                    'total_in'       => '总 IN',
                    'total_out'      => '总 OUT',
                    'net_normal'     => '净额',
                    'pending'        => '未收款',
                    'return_balance' => 'Return',
                    'total_bonus'    => '总 BONUS',
                    'summary_in'     => '汇总IN',
                    'summary_out'    => '汇总OUT',
                    'summary_net'    => '汇总净额',
                ],

                'return_still_owing' => '客户仍持有我们的本金（未归还）',
                'return_profit'      => '本金已全部归还',
                'return_balanced'    => '本金已全部归还',

                'paid_label'        => '客户已付款：',
                'alloc_avail_label' => '可分配金额（已付款，MYR）：',

                'payer_label' => '付款方：',
                'staff_label' => '签名员工：',

                'pending' => '未收',

                'action_view'       => '查看',
                'action_receipt_in' => 'IN 收据',
                'action_allocate'   => '分配',

                'contra_summary_title'   => '交易分配（Contra 汇总）',
                'contra_summary_company' => 'Contra 至公司：',
                'contra_summary_desc'    => '该日期的 contra 分配总额。',
            ],

            'type' => [
                'in'             => 'IN',
                'out'            => 'OUT',
                'contra_summary' => 'CONTRA',
            ],

            'fx' => [
                'label'             => '兑 :base 汇率（1 {CUR} = ? :base）',
                'example'           => '例：1 USD = 4.700000 :base → 输入 4.700000',
                'base_amount_label' => ':base 金额（仅供参考）',
            ],
        ], // end customer_txn

        // =========================
        // txn_in
        // =========================
        'txn_in' => [
            'eyebrow'  => 'IN 交易',
            'subtitle' => '创建/更新一张 IN 订单（可多笔付款）。',
            'back'     => '← 返回交易列表',
            'saved'    => 'IN 交易已保存。',

            'basic' => [
                'title' => '基本信息',
                'desc'  => '日期、类型、金额与主币种。',
            ],

            'field' => [
                'date'       => '日期',
                'in_type'    => 'IN 类型',
                'invoice_no' => '发票号',
                'amount'     => '金额',
            ],

            'in_kind' => [
                'invoice' => '发票 / 正常 IN',
                'return'  => '回款 / 退回本金',
                'bonus'   => '奖金 / 分红',
                'help'    => 'INVOICE = 发票；RETURN = 本金；BONUS = 其他',
            ],

            'invoice_ph'            => '留空自动生成 VMYYMM-XXX',
            'invoice_disabled_help' => 'RETURN / BONUS 不使用发票号。',
            'currency_ph'           => 'MYR / USD / SGD ...',
            'amount_help'           => '金额与币种为该 IN 主币种，Paid / Pending 也按同币种计算。',

            'paid_so_far' => '已收款',
            'pending'     => '未收款余额',

            'payments' => [
                'title'    => '本订单付款明细',
                'desc'     => '可添加多行付款；每行也会创建一笔银行交易（银行表记录 MYR 换算参考）。',
                'add_line' => '+ 添加一行',
            ],

            'col' => [
                'or_no'    => '正式收据号（OR）',
                'pay_date' => '日期',
                'amount'   => '金额',
                'bank'     => '银行',
                'currency' => '货币',
                'fx_rate'  => '汇率 → MYR',
                'attach'   => '付款附件',
                'receipt'  => '收据 / 查看',
                'action'   => '操作',
            ],

            'select'       => '— 选择 —',
            'after_save'   => '保存后',
            'view_invoice' => '查看发票',
            'view_receipt' => '查看收据',

            'attach' => [
                'add'    => '+ 添加',
                'saved'  => '已保存：',
                'delete' => '删除',
                'default_name' => '附件 %d',
            ],

            'notes_sign' => [
                'title' => '备注与签名',
                'desc'  => '内部备注、附件与签名设置。',
            ],
            'notes' => '备注',

            'txn_attach' => [
                'label' => '附件（本 IN）',
                'help'  => '可上传发票、DO、合同等（可多文件）。',
            ],

            'our' => [
                'title'      => '我方（收款方）',
                'company'    => '我方公司',
                'staff'      => '我方职员 / 签名人',
                'staff_help' => '选择谁收款/签名（配合 “Sign receive” 使用）。',
            ],

            'recipient' => [
                'name'    => '收款人姓名（签名者）',
                'name_ph' => '输入或从登录用户选择...',
                'nric'    => '收款人 NRIC / 护照',
                'tip'     => '选择登录用户会自动填入 NRIC，也可手动填写。',
            ],

            'sign' => [
                'sign_receive'      => 'Sign receive（我方签收）',
                'sign_receive_help' => '勾选=显示 “Received by” + 公司盖章；不勾=仅公司盖章。',
                'need_customer'     => '需要客户签名',
                'need_customer_help' => '勾选=客户必须签名；不勾=不需要签名且状态不锁定。',
            ],

            'btn' => [
                'cancel' => '取消',
                'save'   => '保存',
            ],
        ],

        // =========================
        // CUSTOMER LOGIN USERS (admin side)
        // =========================
        'customer_user' => [
            'edit' => [
                'page_title'           => '客户登录用户',
                'eyebrow'              => '登录用户',
                'title_edit'           => '编辑登录用户',
                'title_new'            => '新登录用户',
                'subtitle'             => '管理该客户的门户账号。',
                'saved'                => '用户已保存。',
                'section_account'      => '账号',
                'section_account_desc' => '用户名与基本联系信息。',
            ],
            'field' => [
                'username'      => '用户名',
                'full_name'     => '姓名',
                'nric'          => 'NRIC / IC',
                'email'         => '邮箱',
                'phone'         => '电话',
                'password_new'  => '密码 *',
                'password_edit' => '密码（留空=不更改）',
                'active'        => '启用',
            ],
            'error' => [
                'username_required'      => '用户名必填',
                'full_name_required'     => '姓名必填',
                'username_exists'        => '用户名已存在',
                'username_exists_global' => '用户名已存在',
                'password_required_new'  => '新用户必须填写密码',
                'password_required'      => '密码必填',
            ],
            'list' => [
                'page_title'     => '客户登录：',
                'eyebrow'        => '客户登录',
                'saved'          => '用户已保存。',
                'existing_title' => '已有登录',
                'no_users'       => '暂无登录用户。',
                'btn_edit'       => '编辑 / 重置',
                'add_title'      => '新增登录用户',
                'btn_create'     => '创建登录',
            ],
            'col' => [
                'id'       => 'ID',
                'username' => '用户名',
                'name'     => '姓名',
                'nric'     => 'NRIC / IC',
                'email'    => '邮箱',
                'phone'    => '电话',
                'active'   => '启用',
                'action'   => '操作',
            ],
        ],

        // =========================
        // ✅ BANK (跟 ms 一样：只保留一份 admin['bank'])
        // =========================
        'bank' => [

            // ✅ 补：Cash account 文案（transactions.php bank_id=0 会用到）
            'cash' => [
                'title'        => '现金账户',
                'account_name' => '现金',
            ],

            'banks' => [
                'list' => [
                    'title'    => '公司银行账户',
                    'eyebrow'  => '财务',
                    'subtitle' => '管理内部用于收款/付款的银行账户。',
                    'new_btn'  => '+ 新银行',
                    'empty'    => '暂无银行账户。',
                    'filter' => [
                        'q'    => '搜索',
                        'q_ph' => '银行 / 户名 / 账号',
                    ],
                    'action_txn'        => '交易',
                    'action_statements' => '月结单',
                ],
                'field' => [
                    'bank_name'    => '银行名称',
                    'account_name' => '账户名称',
                    'account_no'   => '账号',
                    'currency'     => '币种',
                ],
            ],

            'account' => [
                'title_new'       => '新银行账户',
                'title_edit'      => '编辑银行账户',
                'title_new_label' => '新银行账户',
                'eyebrow'         => '银行',
                'subtitle'        => '设置内部使用的银行账户与币种。',

                'section' => [
                    'details_title' => '账户资料',
                    'details_desc'  => '银行名称、代码、户名与币种。',
                ],

                'field' => [
                    'bank_name'    => '银行名称',
                    'bank_code'    => '银行代码',
                    'account_name' => '账户名称',
                    'account_no'   => '账号',
                    'currency'     => '币种',
                    'sort_order'   => '排序',
                    'active'       => '启用',
                ],

                'sort_tip' => '留空/填 0 将自动设置（最大值 + 10）。',
                'save_btn' => '保存账户',

                'error' => [
                    'bank_name_required'    => '银行名称必填。',
                    'account_name_required' => '账户名称必填。',
                    'save_failed'           => '保存失败',
                ],
            ],

            'accounts' => [
                'page_title' => '公司银行账户',
                'eyebrow'    => '银行',
                'subtitle'   => '管理用于 IN / OUT 的内部银行账户。',
                'new_btn'    => '+ 新账户',
                'empty'      => '暂无银行账户。',

                'col' => [
                    'bank'         => '银行',
                    'account_name' => '账户名称',
                    'account_no'   => '账号',
                    'opening'      => '期初余额',
                    'current'      => '当前余额',
                    'currency'     => '币种',
                    'status'       => '状态',
                ],

                'action' => [
                    'view_txn' => '查看交易',
                    'view_stmt' => '查看结单',
                    'delete_confirm' => '确定要删除这个银行账户吗？',
                    'delete'   => '删除',
                ],
            ],

            // ✅ transactions.php 用到的一整套（你之前缺了很多）
            'txn' => [
                'page_title' => '银行交易',
                'eyebrow'    => '银行',

                'btn_statement' => '银行结单',

                'type_in'  => '进账',
                'type_out' => '出账',

                'err_missing_bank'    => '缺少 bank_id',
                'err_bank_not_found'  => '找不到银行账户',

                'view_cur_account' => '{cur}（账户币种）',
                'view_cur_myr'     => 'MYR（换算）',

                'new_btn'  => '+ 新交易',

                'summary' => [
                    'eyebrow'        => '汇总',
                    'title'          => '余额概览',
                    'opening_simple' => '期初余额',
                    'in'             => '期间 IN',
                    'out'            => '期间 OUT',
                    'net'            => '净变动',
                    'current_simple' => '当前余额',
                ],

                'filter' => [
                    'type'     => '类型',
                    'view_cur' => '查看币种',
                    'q'        => '搜索关键字',
                    'q_ph'     => '参考号 / 说明',
                ],

                'row' => [
                    'opening' => '期间前期初余额',
                ],

                'empty' => '该筛选条件下没有交易。',

                'col' => [
                    'date'    => '日期',
                    'type'    => '类型',
                    'ref'     => '参考号',
                    'desc'    => '说明',
                    'cur'     => '币种',
                    'amount'  => '金额',
                    'myr'     => 'MYR',
                    'balance' => '余额（{cur}）',
                ],

                // 旧版兼容（你以前的 bank txn edit 可能还在用这套）
                'edit' => [
                    'eyebrow'   => '公司银行',
                    'title'     => '银行交易',
                    'title_new' => '新银行交易',
                    'title_edit' => '编辑银行交易',
                    'saved'     => '银行交易已保存。',

                    'section' => [
                        'details' => '交易资料',
                        'amount'  => '金额与汇率',
                    ],

                    'field' => [
                        'bank'        => '银行账户',
                        'date'        => '日期',
                        'type'        => '类型',
                        'ref_no'      => '参考号',
                        'description' => '描述',
                        'amount'      => '金额',
                        'currency'    => '币种',
                        'rate_to_myr' => '兑 MYR 汇率',
                        'amount_myr'  => '折算 MYR 金额（自动计算）',
                    ],

                    'type' => [
                        'in'  => 'IN（进账）',
                        'out' => 'OUT（出账）',
                    ],

                    'helper' => [
                        'rate'       => '若币种不是 MYR，请填写汇率用于折算为 MYR。',
                        'amount_myr' => '按 金额 × 汇率 计算，并保存到 amount_myr 用于报表。',
                    ],

                    'save_btn' => '保存交易',

                    'error' => [
                        'missing_bank_id'      => '缺少 bank_id',
                        'bank_not_found'       => '找不到银行账户',
                        'txn_not_found'        => '找不到银行交易',
                        'invalid_bank_id'      => 'bank id 无效。',
                        'date_required'        => '日期必填。',
                        'date_format'          => '日期格式无效（YYYY-MM-DD）。',
                        'type_invalid'         => '交易类型无效。',
                        'amount_zero'          => '金额不能为 0。',
                        'rate_required'        => '非 MYR 必须填写兑 MYR 汇率且必须 > 0。',
                        'save_failed'          => '保存失败：%s',
                    ],
                ],
            ],

            // ✅ 这套才是你 txn_edit.php / transactions.php 现在正在用的 key：admin.bank.txn_edit.*
            'txn_edit' => [
                'eyebrow'  => '银行交易',
                'subtitle' => '记录该账户的银行 / USDT 资金流动。',
                'saved'    => '交易已保存。',

                'title_new'  => '新银行交易',
                'title_edit' => '编辑银行交易',

                'section' => [
                    'main' => '交易资料',
                ],

                'field' => [
                    'date'        => '日期',
                    'type'        => '类型',
                    'description' => '说明',
                    'ref_no'      => '参考号',
                    'amount'      => '金额',
                    'currency'    => '币种',
                    'rate_to_myr' => '汇率 → MYR',
                ],

                'help' => [
                    'rate' => '若是 USDT，请输入 1 USDT = ? MYR。',
                ],

                'save_btn' => '保存交易',

                'pick' => [
                    'title'       => '选择银行账户',
                    'desc'        => '请选择要为哪个银行 / 钱包创建交易。',
                    'field'       => '银行账户',
                    'ph'          => '— 请选择 —',
                    'id_fallback' => 'ID {id}',
                ],

                'type_allocate' => '转到其他银行',

                'allocate' => [
                    'target_label'   => '转入银行账户',
                    'target_ph'      => '— 选择目标银行 —',
                    'tip'            => '只支持同币种账户。系统会自动创建 OUT（本账户）和 IN（目标账户）。',
                    'currency_fixed' => '转账币种锁定为本账户币种。',
                ],

                'allocate_from' => '来自 {name} 的转账',

                'err_bank_not_found' => '找不到银行账户',
                'err_txn_not_found'  => '找不到银行交易',
                'err_missing_bank'   => '缺少 bank_id',

                'attach' => [
                    'title'     => '附件',
                    'desc'      => '上传 PDF / 图片作为支持文件。',
                    'upload'    => '上传文件',
                    'tip_types' => 'PDF、PNG、JPG、GIF',
                    'existing'  => '已存在文件',

                    'err_upload' => '文件“{name}”上传失败（错误码 {code}）。',
                    'err_type'   => '文件“{name}”已跳过（类型不支持：{type}）。',
                    'err_move'   => '文件“{name}”移动失败。',
                ],

                'error' => [
                    'date_required'    => '日期必填。',
                    'amount_required'  => '金额不能为 0。',
                    'rate_required'    => '非 MYR 必须填写兑 MYR 汇率且必须 > 0。',
                    'save_failed'      => '保存失败',

                    'target_required'        => '请选择目标银行。',
                    'target_same'            => '不能转到同一个银行。',
                    'target_invalid'         => '目标银行无效。',
                    'allocate_same_currency' => '目前只支持同币种账户转账。',
                ],
            ],

            'stmt' => [
                'page_title' => '银行结单',
                'eyebrow'    => '银行结单',

                'opening' => '期初余额',
                'current' => '当前余额',

                'upload_title' => '上传结单',
                'upload_btn'   => '上传',
                'upload_ok'    => '结单上传成功。',

                'month'  => '月份',
                'label'  => '标题',
                'remark' => '备注',
                'file'   => '结单文件',

                'label_ph'  => '例：2025年7月',
                'remark_ph' => '例：电子结单',

                'label_tip' => '留空则系统自动生成。',
                'file_tip'  => 'PDF、PNG、JPG、GIF',

                'search'    => '搜索',
                'search_ph' => '搜索标题 / 备注 / 文件',

                'col' => [
                    'month'       => '月份',
                    'label'       => '标题',
                    'remark'      => '备注',
                    'file'        => '文件',
                    'size'        => '大小',
                    'uploaded_at' => '上传时间',
                ],

                'empty' => '暂无结单。',
                'confirm_delete' => '删除这张结单？',

                'err' => [
                    'month'         => '请选择有效月份。',
                    'file_required' => '请选择文件。',
                    'file_type'     => '文件类型无效。',
                    'upload'        => '上传错误（错误码 {code}）。',
                    'general'       => '上传失败：',
                ],
            ],

            'all_txn' => [
                'page_title' => '所有银行交易',
                'eyebrow'    => '银行总览',
                'subtitle'   => '以 MYR 汇总所有账户。',

                'summary' => '汇总',

                'opening' => '期初余额',
                'current' => '当前余额',
                'total_in' => '总收入',
                'total_out' => '总支出',
                'net'     => '净额',

                'filter' => [
                    'type'     => '类型',
                    'search'   => '搜索',
                    'search_ph' => '说明 / 参考号 / 银行 / 账号',
                    'accounts' => '账户',
                ],

                'no_accounts' => '没有启用的银行账户。',
                'empty'       => '没有交易。',

                'ref'         => '参考号',
                'attachments' => '附件',

                'col' => [
                    'date'   => '日期',
                    'type'   => '类型',
                    'account' => '账户',
                    'desc'   => '说明',
                    'amount' => '金额',
                    'rate'   => '汇率 → MYR',
                    'myr'    => 'MYR',
                ],
            ],
        ], // end admin.bank
    ], // end admin

    // =========================
    // CUSTOMER PORTAL BLOCK
    // =========================
    'portal' => [
        'lang' => [
            'en' => '英文',
            'ms' => '马来文',
            'zh' => '中文',
        ],

        'header' => [
            'app_title'     => '客户门户',
            'logout'        => '登出',
            'signed_in_as'  => '当前登录',
        ],

        'footer' => [
            'powered_by' => '技术支持：Vision Mix',
        ],

        'sidebar' => [
            'section_main'     => '主页',
            'section_settings' => '设置',
            'dashboard'        => '仪表板',
            'txns'             => '交易 / 报表',
            'users'            => '登录用户',
        ],

        'status' => [
            'confirmed' => 'CONFIRMED',
            'sent'      => 'SENT',
            'draft'     => 'DRAFT',
        ],

        'dashboard' => [
            'pending_eyebrow'   => '待处理',
            'pending_title'     => '待签名',
            'pending_subtitle'  => '这些付款正在等待你的签名。',
            'pending_view_all'  => '查看全部待处理',
            'pending_sign_btn'  => '签名',
            'pending_type_receipt' => '收据（我们付你）',
            'pending_type_invoice' => '发票（你付我们）',

            'table' => [
                'date'    => '日期',
                'title'   => '标题',
                'amount'  => '金额',
                'status'  => '状态',
                'actions' => '操作',
                'type' => '类型',
            ],

            // Summary row 1
            'row1' => [
                'total_out' => '总 OUT（你付我们）',
                'total_in'  => '总 IN（我们付你）',
                'balance'   => '余额（OUT - IN）',
            ],

            // Summary row 2
            'row2' => [
                'repayment' => 'Repayment（回款）',
                'bonus'     => 'Bonus（奖金）',
            ],

            // Summary row 3
            'row3' => [
                'summary_out' => '汇总 OUT',
                'summary_in'  => '汇总 IN',
                'summary_net' => '汇总净额（OUT - IN）',
            ],

            'customer_eyebrow'  => '客户',
            'customer_subtitle' => '你所有交易与余额的概览。',

            'summary_eyebrow'   => '汇总',
            'summary_title'     => '对冲后',
            'summary_out_label' => 'OUT 总额（你付我们）',
            'summary_in_label'  => 'IN 总额（我们付你）',
            'summary_bal_label' => '余额（OUT - IN）',
            'summary_we_owe'    => '我们欠你',
            'summary_you_owe'   => '你欠我们',
            'summary_balanced'  => '平衡',

            'recent_eyebrow'     => '最新',
            'recent_title'       => '最近 10 笔交易',
            'recent_full_report' => '完整报表',
            'recent_in_title'    => 'IN — 我们付你',
            'recent_out_title'   => 'OUT — 你付我们',
            'recent_no_payout'   => '暂无我们付给你的记录。',
            'recent_no_payment'  => '暂无你付给我们的记录。',
        ],
    ],

    // ===== Portal-side status shortcuts =====
    'status' => [
        'confirmed' => 'CONFIRMED',
        'sent'      => 'SENT',
        'draft'     => 'DRAFT',
        'pending'   => 'PENDING',
    ],

    // ===== Portal: Users (customer self-manage logins) =====
    'users' => [
        'list' => [
            'page_title'     => '登录用户',
            'eyebrow'        => '登录用户',
            'subtitle'       => '管理你公司自己的门户账号。',
            'created'        => '用户已创建 / 更新。',
            'existing_title' => '已有账号',
            'no_users'       => '暂无登录用户。',
            'add_title'      => '新增账号',
            'btn_edit'       => '编辑 / 重置',
            'btn_create'     => '创建账号',
            'col' => [
                'id'       => 'ID',
                'username' => '用户名',
                'name'     => '姓名',
                'email'    => '邮箱',
                'phone'    => '电话',
                'active'   => '启用',
                'action'   => '操作',
            ],
            'ref_prefix' => '参考：',
        ],

        'edit' => [
            'page_title'            => '编辑登录用户',
            'eyebrow'               => '登录用户',
            'title'                 => '编辑登录用户',
            'subtitle'              => '更新门户账号资料。',
            'saved'                 => '用户已保存。',
            'section_account_title' => '账号',
            'section_account_desc'  => '用户名与联系信息。',
        ],

        'field' => [
            'username'      => '用户名',
            'password'      => '密码',
            'password_hint' => '密码（留空=不更改）',
            'full_name'     => '姓名',
            'email'         => '邮箱',
            'phone'         => '电话',
            'active'        => '启用',
            'nric'          => 'NRIC',
        ],

        'error' => [
            'username_required'  => '用户名必填',
            'full_name_required' => '姓名必填',
            'password_required'  => '密码必填',
            'username_exists'    => '用户名已存在',
            'general_failed'     => '保存失败，请重试。',
        ],

        'btn' => [
            'back' => '返回',
            'save' => '保存',
        ],

        'col' => [
            'id'       => 'ID',
            'username' => '用户名',
            'name'     => '姓名',
            'email'    => '邮箱',
            'phone'    => '电话',
            'active'   => '启用',
            'action'   => '操作',
            'nric'     => 'NRIC',
        ],
    ],

    // ===== Portal: Transactions list & view =====
    'txn' => [
        'type' => [
            'in'  => 'IN（我们付你）',
            'out' => 'OUT（你付我们）',
        ],

        'badge' => [
            'contra' => '对冲',
        ],

        'list' => [
            'page_title'       => '交易 / 报表',
            'eyebrow_customer' => '客户',
            'subtitle'         => '交易与简单报表。IN = 我们付你，OUT = 你付我们。',

            'filter' => [
                'type'        => '类型',
                'type_all'    => '全部',
                'type_in'     => 'IN（我们付你）',
                'type_out'    => 'OUT（你付我们）',

                'status'      => '状态',
                'status_all'  => '全部',

                'method'       => '方式',
                'method_all'   => '全部',
                'method_cash'  => '现金',
                'method_bank'  => '银行',
                'method_usdt'  => 'USDT',
                'method_other' => '其他',

                'keyword'    => '关键字',
                'keyword_ph' => '标题 / 参考号...',
            ],

            'col' => [
                'date'    => '日期',
                'type'    => '类型',
                'method'  => '方式',
                'title'   => '标题',
                'amount'  => '金额',
                'status'  => '状态',
                'action'  => '操作',
                'balance' => '余额',
                'pending' => '待付',
            ],

            'btn_view'   => '查看',
            'ref_prefix' => '参考：',
            'empty'      => '该筛选条件下没有交易。',
        ],

        'view' => [
            'page_title' => '交易详情',
            'eyebrow'    => '交易',

            'type_label_in'  => 'IN — 我们付你',
            'type_label_out' => 'OUT — 你付我们',

            'field' => [
                'type'               => '类型',
                'amount'             => '金额',
                'status'             => '状态',
                'date'               => '日期',
                'ref_no'             => '参考号',
                'method'             => '方式',
                'title'              => '标题',
                'notes'              => '备注',
                'recipient_name'     => '收款人姓名',
                'recipient_nric'     => '收款人 NRIC',
                'signature_required' => '需要签名',
            ],

            'section_details_title' => '详情',
            'section_details_desc'  => '该交易的基本信息。',

            'section_recipient_title' => '收款人 / 签名',
            'section_recipient_desc'  => '收到款项的人信息。',

            'section_attach_title' => '附件',
            'section_attach_desc'  => '打开或下载附件（PDF / 图片）。',

            'section_in_title' => 'IN 交易详情',
            'section_in_desc'  => '订单金额、付款与附件。',

            'paid_myr'    => '已付（MYR）',
            'pending_myr' => '未付（MYR）',

            'section_payments_title'     => '付款',
            'section_notes_attach_title' => '备注附件',

            'col' => [
                'or_no'    => '正式收据号',
                'pay_date' => '日期',
                'amount'   => '金额',
                'bank'     => '银行',
                'currency' => '货币',
                'fx_rate'  => '汇率 → MYR',
                'attach'   => '付款附件',
                'invoice'  => '发票',
            ],

            'attach_default'    => '附件',
            'attach_none_short' => '无附件',
            'payments_none'     => '暂无付款。',

            'alert_pending' => '该付款等待你的确认/签名。请点击上方 “View receipt” 查看并签名。',
            'alert_confirmed' => '该付款已确认并完成签名。',
            'alert_contra'    => '该记录由分配（对冲）生成，不需要签名。',

            'sig_status_signed'      => '已签名 / 已确认',
            'sig_status_pending_you' => '等待你签名',
            'sig_status_pending'     => '等待中',

            'attach_tip'  => '点击文件名查看或下载。',
            'attach_none' => '该交易未上传附件。',

            'btn_back'    => '← 返回交易',
            'btn_receipt' => '查看收据',
            'btn_invoice' => '查看发票',
        ],
    ],

    // =========================
    // CUSTOMER RECEIPT + SIGN (cust.*)
    // =========================
    'cust' => [
        'common' => [
            'status' => [
                'confirmed' => 'CONFIRMED',
                'sent'      => 'SENT',
                'draft'     => 'DRAFT',
            ],
            'btn' => [
                'back'          => '返回',
                'back_to_txns'  => '返回交易',
                'back_to_list'  => '返回列表',
                'cancel'        => '取消',
                'clear'         => '清除',
            ],
            'error' => [
                'save_failed' => '保存失败，请重试。',
            ],
        ],

        'txn' => [
            'type_label' => [
                'in'  => 'IN — 我们付你',
                'out' => 'OUT — 你付我们',
            ],
            'badge' => [
                'contra' => '对冲',
            ],
            'btn' => [
                'print_pdf' => '打印 / PDF',
            ],
            'method' => [
                'cash'  => '现金',
                'bank'  => '银行转账',
                'usdt'  => 'USDT',
                'other' => '其他',
            ],
            'receipt' => [
                'title'                => '收据 / 确认',
                'page_title'           => '收据 / 确认',
                'eyebrow'              => '收据 / 确认',
                'txn_no'               => 'Txn #',

                'date'                 => '日期',
                'received_from'        => '收款自（付款方）：',
                'received_by'          => '收款人 / 代表：',
                'received_by_company'  => '（我方公司）',

                'recipient'            => '收款人（签名者）：',
                'recipient_placeholder' => '（请在门户填写收款人姓名）',
                'received_from_payer'   => '收款自（付款方）：',
                'received_from_rep'     => '代表：',
                'nric_label'            => 'NRIC：',
                'received_by_on_behalf' => '收款人 / 代表：',
                'address'               => '地址：',

                'amount'               => '金额：',
                'method'               => '方式：',
                'ref_no'               => '参考号：',
                'notes'                => '备注：',
                'attachment'           => '附件：',

                'footer_text'          => '本收据确认上述金额已收讫。',

                'sig_receiver_title'   => '签名（收款人 / 客户）',
                'sig_receiver_none'    => '尚未有客户签名。',

                'sig_payer_title'      => '签名（付款方 / 我方公司）',
                'sig_payer_none'       => '尚未有付款方签名。',

                'name_label'           => '姓名：',
                'nric_short'           => 'NRIC：',
                'date_label'           => '日期：',
            ],

            'sign' => [
                'saved'            => '收款人与签名已保存。',

                'who_title'        => '签名人',
                'who_desc'         => '选择由谁代表你方签名，然后在框内签名。',

                'recipient_name'   => '收款人姓名',
                'recipient_placeholder' => '输入或从登录用户选择...',
                'recipient_nric'   => '收款人 NRIC',
                'auto_fill_hint'   => '选择上方用户会自动填入 NRIC，你也可手动填写。',

                'sign_here_title'  => '在此签名',
                'sign_here_desc'   => '在框内签名。保存后该付款会标记为 CONFIRMED。',

                'btn_save'         => '保存收款人与签名',

                'page_title'       => '交易签名',
                'eyebrow'          => '付款确认',
                'default_title'    => '付款',
                'subtitle'         => '请在下方签名确认你已收到该付款。',

                'not_pending'      => '该交易不是待你签名状态（可能已确认、无需签名或为对冲）。',
                'thanks'           => '谢谢，你已完成签名并确认该付款。',

                'details_title'    => '交易详情',
                'date'             => '日期',
                'method'           => '方式',
                'amount'           => '金额',
                'ref_no'           => '参考号',
                'recipient_name_label' => '收款人姓名（签名者）',
                'recipient_nric_label' => '收款人 NRIC',
                'attachment'       => '附件文件',

                'sign_box_title'   => '在此签名',
                'sign_box_desc'    => '请使用鼠标或触控在框内签名。',

                'btn_save_signature' => '保存签名',

                'error_sign_box'   => '请先在签名框内签名后再保存。',
            ],
        ],
    ],

];
