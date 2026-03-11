<?php
// lang/en.php
declare(strict_types=1);

return [

    // ----- Global common (admin + portal 通用) -----
    'common' => [
        'status' => 'Status',
        'cancel' => 'Cancel',
        'save'   => 'Save',
        'yes'    => 'Yes',
        'no'     => 'No',
        'apply'  => 'Apply',
        'reset'  => 'Reset',

        'date_range' => 'Date Range',
        'select_date_range' => 'Select date range',

        'mo' => 'Mo',
        'tu' => 'Tu',
        'we' => 'We',
        'th' => 'Th',
        'fr' => 'Fr',
        'sa' => 'Sa',
        'su' => 'Su',

        'month_year' => 'Month YYYY',

        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This Week',
        'last_week' => 'Last Week',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'this_year' => 'This Year',
        'last_year' => 'Last Year',
        'all' => 'All',
    ],

    // =========================
    // ADMIN BLOCK
    // =========================
    'admin' => [

        // ----- Header / Footer -----
        'header' => [
            'app_title'      => 'Admin',
            'toggle_sidebar' => 'Toggle sidebar',
            'logout'         => 'Logout',
        ],
        'footer' => [
            'rights' => 'All rights reserved.',
        ],

        // ----- Reusable common labels -----
        'common' => [
            'apply'     => 'Apply',
            'reset'     => 'Reset',
            'prev'      => 'Prev',
            'next'      => 'Next',
            'save'      => 'Save',
            'cancel'    => 'Cancel',
            'search'    => 'Search',
            'export'    => 'Export',
            'actions'   => 'Actions',
            'edit'      => 'Edit',
            'all'       => 'All',
            'page_line' => 'Page %d / %d',
            'inactive'  => 'Inactive',
            'active'    => 'Active',
            'back'      => 'Back',
            'yes'       => 'Yes',
            'no'        => 'No',
            'delete'    => 'Delete',
            'view'      => 'View',
            'status'    => 'Status',
        ],

        // ----- Navigation -----
        'nav' => [
            'section' => [
                'overview'  => 'OVERVIEW',
                'customers' => 'CUSTOMERS',
                'payers'    => 'PAYERS',
                'reports'   => 'REPORTS',
                'security'  => 'SECURITY',
            ],
            'dashboard'          => 'Dashboard',
            'customer_list'      => 'Customer List',
            'payer_companies'    => 'Payer Companies',
            'payer_staff'        => 'Payer Staff',
            'transaction_report' => 'Transaction Report',
            'customer_report'    => 'Customer Report',
            'users'              => 'Users',
            'roles'              => 'Roles & Permissions',
            'audit_logs'         => 'Audit Logs',
        ],

        // ----- Dashboard -----
        'dashboard' => [
            'title'       => 'Dashboard',
            'eyebrow'     => 'Overview',

            'pending_sig' => 'Pending',

            'this_month'  => 'This month',
            'total_in'    => 'Total IN',
            'total_out'   => 'Total OUT',
            'pending'     => 'Pending',

            'all_time'        => 'All time',
            'return_balance'  => 'Return balance',
            'bonus'           => 'Bonus',

            'bank_total' => 'Bank total current balance',
            'no_bank'    => 'No active bank accounts.',

            'charts'    => 'Charts',
            'pie_title' => 'This month IN vs OUT',
            'bar_title' => 'Last 6 months (IN / OUT)',
            'total'     => 'Total',

            'bank_in'    => 'Bank IN',
            'latest_in'  => 'Latest bank IN',
            'bank_out'   => 'Bank OUT',
            'latest_out' => 'Latest bank OUT',

            'col' => [
                'date'   => 'Date',
                'bank'   => 'Bank',
                'desc'   => 'Description',
                'amount' => 'Amount',
                'ref'    => 'Ref',
            ],

            'in_empty'  => 'No bank IN transactions yet.',
            'out_empty' => 'No bank OUT transactions yet.',
        ],

        // ----- Audit Logs -----
        'audit' => [
            'title'    => 'Audit Logs',
            'eyebrow'  => 'Security',
            'subtitle' => 'Track who did what and when. Showing %d logs per page.',
            'filter' => [
                'user'        => 'User',
                'user_all'    => 'All users',
                'action'      => 'Action',
                'action_all'  => 'All actions',
                'keyword'     => 'Keyword',
                'keyword_ph'  => 'Search description / entity / extra...',
            ],
            'total_line' => 'Total: %d records · %d per page',
            'page_line'  => 'Page %d / %d',
            'col' => [
                'time'        => 'Time',
                'user'        => 'User',
                'action'      => 'Action',
                'entity'      => 'Entity',
                'description' => 'Description',
                'extra'       => 'Extra',
                'ip'          => 'IP',
            ],
            'empty'          => 'No audit records found for this filter.',
            'system_unknown' => 'System / Unknown',
        ],

        // ----- Customer common status -----
        'customer' => [
            'status' => [
                'active'   => 'Active',
                'inactive' => 'Inactive',
            ],
        ],

        // =========================
        // ROLES & PERMISSIONS
        // =========================
        'roles' => [
            'list' => [
                'title'    => 'Roles & Permissions',
                'eyebrow'  => 'Security',
                'subtitle' => 'Define groups of permissions and assign them to users.',
                'new_btn'  => '+ New Role',
                'col' => [
                    'code'    => 'Code',
                    'name'    => 'Name',
                    'status'  => 'Status',
                    'actions' => 'Actions',
                ],
                'empty' => 'No roles found.',
            ],
            'edit' => [
                'title_new'        => 'New Role',
                'title_edit'       => 'Edit Role',
                'title_new_label'  => 'New Role',

                'eyebrow'  => 'Roles & Permissions',
                'subtitle' => 'Manage role details and tick which permissions this role should have.',

                'badge_id' => 'ID: %d',
                'saved'    => 'Role & permissions saved.',

                'error' => [
                    'code_required'  => 'Code is required.',
                    'name_required'  => 'Role name is required.',
                    'code_unique'    => 'This code is already used by another role.',
                    'save_failed'    => 'Save failed',
                ],

                'section' => [
                    'details_title' => 'Role details',
                    'details_desc'  => 'Code and name are used to identify this role.',
                    'perms_title'   => 'Permissions',
                    'perms_desc'    => 'Tick / untick the permissions below.',
                ],

                'field' => [
                    'code'        => 'Code',
                    'name'        => 'Role name',
                    'description' => 'Description',
                    'active'      => 'Active',
                ],

                'group' => [
                    'all'  => 'All',
                    'none' => 'None',
                ],

                'save_btn' => 'Save permissions',
            ],
        ],

        // =========================
        // INTERNAL ADMIN USERS
        // =========================
        'users_admin' => [
            'list' => [
                'title'    => 'Internal Users',
                'eyebrow'  => 'Security',
                'subtitle' => 'Manage admin accounts and their roles.',
                'new_btn'  => '+ New User',

                'search_ph' => 'Search username / name / email',

                'col' => [
                    'username' => 'Username',
                    'fullname' => 'Full name',
                    'email'    => 'Email',
                    'roles'    => 'Roles',
                    'status'   => 'Status',
                    'actions'  => 'Actions',
                ],

                'empty'    => 'No users found.',
                'no_roles' => '(no roles)',
            ],
            'edit' => [
                'eyebrow' => 'Security',

                'title_new'  => 'New user',
                'title_edit' => 'Edit user',

                'subtitle' => 'Internal admin account and its roles.',
                'back_to_list' => '← Back to users',

                'section' => [
                    'account_title' => 'Account',
                    'account_desc'  => 'Login username & password.',
                    'profile_title' => 'Profile',
                    'roles_title'   => 'Roles',
                    'roles_desc'    => 'One user can have multiple roles. Permissions come from roles.',
                ],

                'field' => [
                    'username'         => 'Username',
                    'password_new'     => 'Password',
                    'password_change'  => 'New password',
                    'confirm_password' => 'Confirm password',
                    'full_name'        => 'Full name',
                    'email'            => 'Email',
                    'phone'            => 'Phone',
                    'nric'             => 'NRIC',
                    'status'           => 'Status',
                    'status_active'    => 'Active',
                ],

                'error' => [
                    'username_required' => 'Username is required',
                    'fullname_required' => 'Full name is required',
                    'password_required' => 'Password is required',
                    'password_mismatch' => 'Password and confirm do not match',
                    'username_used'     => 'Username already in use',
                ],

                'no_roles_defined' => 'No roles defined yet. Please create at least one role first.',
            ],
        ],

        // =========================
        // PAYER COMPANY
        // =========================
        'payer_company' => [
            'title'      => 'Payer Companies',
            'edit_title' => 'Edit Payer Company',
            'new_title'  => 'New Payer Company',
            'eyebrow'    => 'Master data',
            'subtitle'   => 'Basic payer company information.',
            'error' => [
                'name_required' => 'Company name is required.',
            ],
            'field' => [
                'name'   => 'Company Name',
                'reg_no' => 'Registration No',
            ],
            'action' => [
                'new' => '+ New Company',
            ],
            'search' => [
                'ph' => 'Search by name / reg no...',
            ],
            'col' => [
                'id'         => 'ID',
                'name'       => 'Company Name',
                'reg_no'     => 'Registration No',
                'created_at' => 'Created At',
            ],
            'empty' => 'No payer companies found.',
        ],

        // =========================
        // PAYER STAFF
        // =========================
        'payer_staff' => [
            'title'      => 'Payer Staff',
            'edit_title' => 'Edit Payer Staff',
            'new_title'  => 'New Payer Staff',
            'eyebrow'    => 'Master data',
            'subtitle'   => 'Signatory staff that can be selected for any payer company.',
            'error' => [
                'name_required' => 'Staff name is required.',
            ],
            'field' => [
                'name'   => 'Name',
                'ic'     => 'IC / Passport',
                'phone'  => 'Phone',
                'email'  => 'Email',
                'active' => 'Active',
            ],
            'action' => [
                'new' => '+ New Staff',
            ],
            'search' => [
                'ph' => 'Search by name / IC / phone...',
            ],
            'col' => [
                'id'     => 'ID',
                'name'   => 'Name',
                'ic'     => 'IC / Passport',
                'phone'  => 'Phone',
                'email'  => 'Email',
                'status' => 'Status',
            ],
            'status' => [
                'active'   => 'Active',
                'inactive' => 'Inactive',
            ],
            'empty' => 'No payer staff found.',
        ],

        // =========================
        // REPORTS (Customer + Transaction)
        // =========================
        'report' => [
            'eyebrow' => 'Reports',

            'customer_list' => [
                'title' => 'Customer List Report',
                'filter' => [
                    'search'      => 'Search',
                    'search_ph'   => 'Search by code / name...',
                    'only_active' => 'Only active',
                ],
                'col' => [
                    'id'        => 'ID',
                    'customer'  => 'Customer',
                    'code'      => 'Code',
                    'in_after'  => 'Total IN (after contra)',
                    'out_after' => 'Total OUT (after contra)',
                    'net'       => 'Net (IN - OUT)',
                    'status'    => 'Status',
                ],
                'empty'       => 'No customers found for this filter.',
                'total_label' => 'TOTAL',
            ],

            'transaction' => [
                'title' => 'Transaction Report',
                'filter' => [
                    'customer'       => 'Customer',
                    'customer_all'   => 'All customers',
                    'type'           => 'Type',
                    'status'         => 'Status',
                    'method'         => 'Method',
                    'contra'         => 'Contra',
                    'contra_only'    => 'Contra only',
                    'contra_without' => 'After contra (hide fully allocated)',
                ],
                'metric' => [
                    'total_in_label'  => 'Total IN (filtered)',
                    'total_in_sub'    => 'All IN transactions matching current filters.',
                    'total_out_label' => 'Total OUT (filtered)',
                    'total_out_sub'   => 'All OUT transactions matching current filters.',
                    'net_label'       => 'Net (IN - OUT)',
                    'net_sub'         => 'Positive = more IN than OUT in the filtered result.',
                ],
                'details' => [
                    'eyebrow' => 'Details',
                    'title'   => 'Transactions',
                    'badge'   => 'Total: %d records · %d per page',
                ],
                'col' => [
                    'date'        => 'Date',
                    'customer'    => 'Customer',
                    'type'        => 'Type',
                    'amount'      => 'Amount',
                    'method'      => 'Method',
                    'ref'         => 'Ref',
                    'title_notes' => 'Title / Notes',
                ],
                'flag' => [
                    'contra' => 'Contra',
                ],
                'empty' => 'No transactions found for this filter.',
            ],
        ],

        // =========================
        // Legacy payers (for compatibility)
        // =========================
        'payers' => [
            'company_list_title'   => 'Payer Companies',
            'company_edit_title'   => 'Edit Payer Company',
            'company_new_title'    => 'New Payer Company',
            'company_name'         => 'Company Name',
            'company_reg_no'       => 'Registration No',
            'company_basic_info'   => 'Basic payer company information.',
            'company_add'          => '+ New Company',

            'staff_list_title'     => 'Payer Staff',
            'staff_edit_title'     => 'Edit Payer Staff',
            'staff_new_title'      => 'New Payer Staff',
            'staff_name'           => 'Name',
            'staff_ic'             => 'IC / Passport',
            'staff_phone'          => 'Phone',
            'staff_email'          => 'Email',
            'staff_active'         => 'Active',
            'staff_inactive'       => 'Inactive',
            'staff_add'            => '+ New Staff',
            'staff_basic_info'     => 'Signatory staff that can be selected for any payer company',

            'search_placeholder_company' => 'Search by name / reg no...',
            'search_placeholder_staff'   => 'Search by name / IC / phone...',
        ],

        'reports' => [
            'customer_list_title' => 'Customer List Report',
            'transaction_title'   => 'Transaction Report',

            'reports'       => 'Reports',
            'details'       => 'Details',
            'export'        => 'Export',
            'search'        => 'Search',
            'only_active'   => 'Only active',
            'customer'      => 'Customer',

            'txn_type'      => 'Type',
            'txn_status'    => 'Status',
            'txn_method'    => 'Method',
            'txn_contra'    => 'Contra',

            'all'           => 'All',
            'in_only'       => 'IN only',
            'out_only'      => 'OUT only',
            'contra_only'   => 'Contra only',
            'after_contra'  => 'After contra (hide fully allocated)',

            'total_in'      => 'Total IN (filtered)',
            'total_out'     => 'Total OUT (filtered)',
            'net'           => 'Net (IN - OUT)',

            'no_data'       => 'No data found.',
        ],

        // =========================
        // CUSTOMERS
        // =========================
        'customers' => [
            'list' => [
                'title'     => 'Customers',
                'eyebrow'   => 'Master data',
                'new_btn'   => '+ New Customer',
                'saved'     => 'Customer saved.',
                'search_ph' => 'Search by code / name / reg no...',

                'col' => [
                    'code'      => 'Code',
                    'name'      => 'Name',
                    'in_after'  => 'IN (after contra)',
                    'out_after' => 'OUT (real payout)',
                    'net_after' => 'Net (after contra)',
                    'status'    => 'Status',
                    'actions'   => 'Actions',
                ],

                'reg_prefix'           => 'Reg:',
                'empty'                => 'No customers found.',
                'net_label_we_owe'     => 'We owe customer',
                'net_label_cust_owe'   => 'Customer owes us',
                'net_label_balanced'   => 'Balanced',
                'action_txn'           => 'Transactions',
                'action_users'         => 'Login users',
                'action_edit'          => 'Edit customer',
            ],

            'edit' => [
                'title_new'       => 'New Customer',
                'title_edit'      => 'Edit Customer',
                'title_new_label' => 'New Customer',
                'title_fallback'  => 'Customer',

                'eyebrow_new'  => 'Create customer profile',
                'eyebrow_edit' => 'Update customer profile',
                'subtitle'     => 'Basic company details, contact person and default receipt info.',

                'section' => [
                    'basic_title'    => 'Basic information',
                    'basic_desc'     => 'Internal code and legal name of the company.',
                    'contact_title'  => 'Contact & receipt',
                    'contact_desc'   => 'Default person to contact and who signs on receipts.',
                    'address_title'  => 'Address',
                    'address_desc'   => 'Registered or billing address for this customer.',
                ],

                'field' => [
                    'code'                 => 'Code',
                    'name'                 => 'Name',
                    'reg_no'               => 'Reg. No.',
                    'billing_name'         => 'Billing Name',
                    'contact_name'         => 'Contact Person',
                    'contact_phone'        => 'Contact Phone',
                    'contact_email'        => 'Contact Email',
                    'default_receipt_name' => 'Default Receipt Name',
                    'default_receipt_nric' => 'Default Receipt NRIC',
                    'address1'             => 'Address line 1',
                    'address2'             => 'Address line 2',
                    'address3'             => 'Address line 3',
                    'postcode'             => 'Postcode',
                    'city'                 => 'City',
                    'state'                => 'State',
                    'country'              => 'Country',
                    'status_active'        => 'Active',
                ],

                'error' => [
                    'code_required' => 'Code is required',
                    'name_required' => 'Name is required',
                    'code_unique'   => 'Code already exists',
                ],
            ],
        ],

        // =========================
        // TXN ALLOCATE 文案
        // =========================
        'txn_allocate' => [
            'page_title'               => 'Allocate IN transaction',
            'eyebrow_allocation'       => 'Allocation',
            'heading_allocate_in_from' => 'Allocate IN from',
            'subtitle_allocation'      => 'Source customer will have one IN and one OUT (contra); target customer will have an IN.',
            'label_txn_no'             => 'Txn',

            'section_source_txn_title' => 'Source transaction',
            'section_source_txn_desc'  => 'Original IN from this customer.',

            'label_customer'           => 'Customer:',
            'label_date'               => 'Date:',
            'label_title'              => 'Title:',
            'label_amount'             => 'Amount:',
            'label_allocated'          => 'Already allocated:',
            'label_remaining'          => 'Remaining:',
            'label_attachment'         => 'Attachment:',
            'link_view_file'           => 'View file',

            'section_allocate_to_title' => 'Allocate to',
            'section_allocate_to_desc'  => 'Select the target customer. We will owe this target after allocation.',

            'field_target_customer'   => 'Target customer',
            'option_select_customer'  => 'Select customer...',

            'field_alloc_amount'      => 'Allocation amount',
            'label_max'               => 'Max:',

            'btn_allocate'            => 'Allocate',

            'note_allocated_from' => 'Allocated from',
            'note_txn_hash'       => 'txn #',
            'note_allocated_to'   => 'Allocated to',
            'note_allocation_to'  => 'Allocation to',
            'note_from_in_txn'    => 'from IN txn #',

            'error_target_customer_required' => 'Please select a target customer.',
            'error_amount_gt_zero'           => 'Allocation amount must be greater than zero.',
            'error_amount_exceeds_remaining' => 'Allocation amount cannot exceed remaining amount.',
            'error_target_not_found'          => 'Target customer not found.',
            'error_allocation_failed'         => 'Allocation failed',
            'error_target_same_as_source'     => 'Target customer cannot be the same as source customer.',
        ],

        'txn_allocate_fifo' => [
            'page_title' => 'FIFO Allocation',
            'eyebrow'    => 'Allocate IN balance (FIFO)',
            'subtitle'   => 'Use remaining IN transactions for this customer and allocate to another customer using FIFO (oldest IN first). Same currency will be allocated together.',

            'badge_id' => 'ID:',

            'section_source_title' => 'Source IN transactions (FIFO pool)',
            'section_source_desc'  => 'These IN transactions still have remaining balance and will be used in FIFO order. Same currency will be allocated together.',
            'no_source'            => 'No IN transactions with remaining balance. Nothing to allocate.',

            'col' => [
                'txn'       => 'Txn #',
                'date'      => 'Date',
                'title'     => 'Title',
                'amount'    => 'Amount',
                'allocated' => 'Allocated',
                'remaining' => 'Remaining',
            ],

            'total_available_in' => 'Total available in',
            'title_fallback'     => 'Txn #',

            'section_allocate_to_title' => 'Allocate to another customer',
            'section_allocate_to_desc'  => 'Choose target customer, currency and amount to allocate using FIFO. Only selected currency\'s IN will be used.',

            'field_target_customer'  => 'Target customer',
            'option_select_customer' => '— Select customer —',

            'field_currency'         => 'Currency',
            'option_select_currency' => '— Select currency —',
            'available'              => 'available',

            'field_alloc_amount' => 'Amount to allocate',
            'max_available'      => 'Max available:',

            'btn_allocate' => 'Allocate (FIFO)',

            'note_fifo_alloc_from' => 'FIFO allocation from',
            'note_fifo_alloc_to'   => 'FIFO allocation to',
            'note_total'           => 'total',
            'note_from_in_txn'     => 'from IN txn',

            'error_target_same_as_source'     => 'Target customer cannot be the same as source customer.',
            'error_target_customer_required'  => 'Target customer is required.',
            'error_currency_required'         => 'Currency is required.',
            'error_currency_no_balance'       => 'Selected currency has no remaining balance.',
            'error_amount_gt_zero'            => 'Amount must be greater than 0.',
            'error_amount_exceeds_available'  => 'Amount exceeds available remaining.',
            'error_target_not_found'          => 'Target customer not found.',
            'error_allocation_failed'         => 'FIFO allocation failed',
        ],

        // =========================
        // CUSTOMER TXN
        // =========================
        'customer_txn' => [
            'page_title' => [
                'new'  => 'New Transaction',
                'edit' => 'Edit Transaction',
            ],

            'header' => [
                'eyebrow_new'   => 'New transaction',
                'eyebrow_edit'  => 'Edit transaction',
                'subtitle_new'  => 'Record new IN / OUT movement for this customer.',
                'subtitle_edit' => 'Update the details of this movement.',
            ],

            'back_to_list' => 'Back to transactions',

            'basic' => [
                'title' => 'Basic details',
                'desc'  => 'Date, type (IN / OUT), payment method and amount.',
            ],

            'out_kind' => [
                'label'  => 'OUT type',
                'normal' => 'Normal OUT',
                'loan'   => 'Loan / Advance to customer',
                'help'   => 'NORMAL = payout; LOAN = loan / advance payment',
            ],
            'status_help' => [
                'customer_autosent' => 'If "Another customer" is selected, saving will auto set to SENT (pending).',
            ],
            'pay_source' => [
                'label' => 'Payment source',
                'bank'  => 'Bank / Cash',
                'customer' => 'Another customer (Pay on behalf)',
                'help' => 'Choose "Another customer" = system will create a NEW IN repayment for that customer (B) and reduce B\'s return balance.',
                'paying_customer' => 'Paying customer',
                'paying_customer_ph' => '— Select customer —',
            ],
            'fx' => [
                'amount_in_base_label' => 'Amount in :base (for info)',
            ],

            'field' => [
                'date'     => 'Date',
                'type'     => 'Type',
                'method'   => 'Method',
                'currency' => 'Currency',
                'amount'   => 'Amount',
                'status'   => 'Status',
                'ref_no'   => 'Reference no.',
                'title'    => 'Title',
                'notes'    => 'Notes',

                'bank_account'     => 'Bank / cash account',
                'bank_placeholder' => '— Select bank / cash —',
            ],

            'type' => [
                'in'  => 'IN (money in / allocated)',
                'out' => 'OUT (payout to customer)',
            ],

            'method' => [
                'cash'  => 'Cash',
                'bank'  => 'Bank',
                'usdt'  => 'USDT',
                'other' => 'Other',
            ],

            'status' => [
                'auto_confirm' => 'CONFIRMED (auto for IN)',
                'draft'        => 'DRAFT',
                'sent'         => 'SENT',
                'confirmed'    => 'CONFIRMED',
            ],

            'error' => [
                'date_required'   => 'Date is required',
                'type_invalid'    => 'Invalid type',
                'method_invalid'  => 'Invalid method',
                'amount_gt_zero'  => 'Amount must be greater than 0',
                'paying_customer_required' => 'Paying customer is required.',
                'paying_customer_same'     => 'Paying customer cannot be the same as the counterparty.',
                'fx_rate_required'         => 'FX rate is required when currency is not :base.',
            ],

            'payer' => [
                'title' => 'Payer (our side)',
                'desc'  => 'Which company is paying out, and who signs on our side. Same staff can be used for any company.',

                'company'             => 'Payer company',
                'company_placeholder' => '— Select company —',
                'staff'               => 'Payer staff / signatory',
                'staff_placeholder'   => '— Select staff —',
            ],

            'parties' => [
                'title'        => 'Parties',
                'desc'         => 'Counterparty is fixed from customer; recipient (signer) only needed for OUT.',
                'counterparty' => 'Counterparty (fixed)',
            ],

            'recipient' => [
                'name'        => 'Recipient name (who signs)',
                'placeholder' => 'Type or pick from login users...',
                'nric'        => 'Recipient NRIC',
                'tip'         => 'When you pick a login user name, NRIC will auto-fill. You can also type both name and NRIC manually.',
            ],

            'desc' => [
                'title' => 'Description & attachment',
                'desc'  => 'Reference, title shown in list, notes, and PDF / image attachment.',
            ],

            'title' => [
                'placeholder_in'  => 'Default: customer name',
                'placeholder_out' => 'Default: Receipt',
            ],

            'attach' => [
                'all'      => 'Attachments (PDF / image)',
                'helper'   => 'You can upload one or multiple supporting files here. They will be listed below.',
                'existing' => 'Existing files',
                'delete'   => 'Delete',

                'multi_error'      => 'File "%s" upload error (code %d).',
                'multi_invalid'    => 'File "%s" skipped (invalid type).',
                'multi_move_fail'  => 'File "%s" failed to move.',
                'multi_db_fail'    => 'Some attachments could not be saved to DB.',
            ],

            'sign' => [
                'title'       => 'Signature requirement',
                'desc'        => 'Only applies to OUT transactions that are not contra. IN will ignore this.',
                'require'     => 'Require customer signature on receipt',
                'contra_note' => 'This OUT transaction is marked as contra (generated by allocation). Signature is not required.',
                'in_note'     => 'Signature is auto disabled for IN transactions.',
            ],

            'badge' => [
                'contra' => 'Contra',
                'loan'             => 'Loan / Advance',
                'paid_by_customer' => 'Paid by customer (B)',
            ],

            'select' => [
                'eyebrow'       => 'New transaction',
                'subtitle'      => 'Choose whether this is an IN (money coming in from customer), an OUT (payout to customer), or allocate remaining IN balance to another customer using FIFO.',
                'section_title' => 'Select action',
                'section_desc'  => 'IN = deposit / top-up / payment received. OUT = refund / withdrawal / settlement. Allocate = use remaining IN balance (FIFO) to contra with other customers.',

                'in_title' => 'IN (money in)',
                'in_desc'  => 'Use this when customer pays in. Can be full payment or partial payments.',
                'in_btn'   => '+ Create IN transaction',

                'out_title' => 'OUT (payout)',
                'out_desc'  => 'Use this when you pay out money to this customer (refund, withdrawal, settlement, etc.).',
                'out_btn'   => '+ Create OUT transaction',

                'alloc_title' => 'Allocate (FIFO)',
                'alloc_desc'  => 'Allocate remaining balance from this customer\'s IN transactions to another customer using FIFO (oldest IN used first).',
                'alloc_btn'   => '→ Allocate using FIFO',
            ],

            'out' => [
                'subtitle' => 'Record or update a payout to this customer (refund, withdrawal, settlement, etc.).',
                'save_btn' => 'Save OUT',
            ],

            'bank' => [
                'load_error' => 'Bank account load error: %s',
                'none'       => 'No bank accounts found in company_bank_accounts.',
                'helper_out' => 'Choose which bank / cash account this payout is from.',
            ],

            'fx' => [
                'label'             => 'FX rate to :base (1 {CUR} = ? :base)',
                'example'           => 'Example: 1 USD = 4.700000 :base → enter 4.700000',
                'base_amount_label' => 'Amount in :base (for info)',
            ],
            'view' => [
                'page_title'   => 'Receipt / Confirmation',
                'eyebrow_in'   => 'IN Transaction',
                'eyebrow_out'  => 'Receipt Preview',
                'txn_label'    => 'Transaction',
                'print_btn'    => 'Print / PDF',
                'sign_saved'   => 'Signature saved.',

                'in' => [
                    'title'    => 'IN transaction details',
                    'desc'     => 'IN does not require a receipt; attachments are supporting documents.',
                    'no_notes' => 'No notes',
                ],

                'attach' => [
                    'tip'   => 'Click a file to view or download.',
                    'none'  => 'No attachment',
                    'title' => 'Attachment:',
                ],

                'receipt_title'   => 'Receipt',
                'received_from'   => 'Received from (Payer):',
                'rep'             => 'Representative:',
                'nric'            => 'NRIC:',
                'received_by'     => 'Received by / On behalf of:',
                'address'         => 'Address:',
                'recipient'       => 'Recipient (Signer):',
                'recipient_fill'  => '(Please fill in customer signer name)',
                'receipt_confirm' => 'This receipt confirms the above amount has been received.',

                'method_bank'  => 'Bank transfer',
                'method_other' => 'Other',

                // OUT: customer is receiver, payer is our company
                'sig_customer_title_out' => 'Signature (Receiver / Customer)',
                'sig_payer_title_out'    => 'Signature (Payer / Our company)',

                // IN: receiver is our company, payer is customer
                'sig_customer_title_in'  => 'Signature (Receiver / Our company)',
                'sig_payer_title_in'     => 'Signature (Payer / Customer)',

                'sig_customer_none' => 'No customer signature yet.',
                'sig_payer_none'    => 'No payer signature yet.',

                'name_label' => 'Name:',
                'date_label' => 'Date:',

                'sign_here_title' => 'Sign here',
                'sign_here_desc'  => 'Either side can sign first. Status will become CONFIRMED only after customer signs.',

                'canvas_customer_tip' => 'Customer – sign inside the box',
                'canvas_payer_tip'    => 'Our company – sign inside the box',
                'clear_btn'           => 'Clear',
                'save_signatures'     => 'Save signatures',
                'sign_done'           => 'Signature recorded / no further signing required.',

                'error' => [
                    'sign_required' => 'Please complete at least one signature before saving.',
                ],
            ],
            'list' => [
                'title'   => 'Customer Transactions',
                'eyebrow' => 'Customer',
                'subtitle' => 'View invoices / payouts, customer payments and contra allocations.',
                'new_btn' => '+ New Transaction',
                'back_to_customers' => 'Back to customers',
                'user_detail_btn'   => 'User detail',

                'save_ok'   => 'Transaction saved.',
                'delete_ok' => 'Transaction deleted.',
                'alloc_ok'  => 'Allocation completed (source updated & contra entry created).',

                'empty' => 'No transactions for this filter. Try another date range.',

                'filter' => [
                    'type'        => 'Type',
                    'search'      => 'Search',
                    'contra_view' => 'Contra view',
                ],

                'summary' => [
                    'eyebrow'       => 'Summary',
                    'after'         => 'After contra',
                    'before'        => 'Without contra (not used)',
                    'total_in'      => 'Total IN',
                    'total_out'     => 'Total OUT',
                    'net_normal'    => 'Net',
                    'pending'       => 'Pending payment',
                    'return_balance' => 'Return',
                    'total_bonus'   => 'Total BONUS',
                    'summary_in'    => 'Summary total IN',
                    'summary_out'   => 'Summary total OUT',
                    'summary_net'   => 'Summary net',
                ],

                'return_still_owing' => 'Customer still has our capital (outstanding)',
                'return_profit'      => 'Capital fully returned',
                'return_balanced'    => 'Capital fully returned',

                'paid_label'        => 'Paid by customer:',
                'alloc_avail_label' => 'Available to allocate (paid, MYR):',

                'payer_label' => 'Payer:',
                'staff_label' => 'Staff:',

                'pending' => 'Pending',

                'action_view'       => 'View',
                'action_receipt_in' => 'IN Receipt',
                'action_allocate'   => 'Allocate',

                'contra_summary_title'   => 'Transaction allocate (contra total)',
                'contra_summary_company' => 'Contra to company:',
                'contra_summary_desc'    => 'Total amount allocated (contra) for this date.',
            ],

            'type' => [
                'in'             => 'IN',
                'out'            => 'OUT',
                'contra_summary' => 'CONTRA',
            ],
        ],

        // =========================
        // BANK (FULL, aligned with admin/bank/*.php)
        // =========================
        'banks' => [
            'list' => [
                'title'    => 'Company Banks',
                'eyebrow'  => 'Finance',
                'subtitle' => 'Manage internal company bank accounts used in IN / OUT transactions.',
                'new_btn'  => '+ New Bank',
                'empty'    => 'No bank accounts found.',

                'filter' => [
                    'q'    => 'Search',
                    'q_ph' => 'Bank / account name / number',
                ],

                'action_txn'        => 'Transactions',
                'action_statements' => 'Statements',
            ],

            'field' => [
                'bank_name'    => 'Bank name',
                'account_name' => 'Account name',
                'account_no'   => 'Account no.',
                'currency'     => 'Currency',
            ],
        ],

        'bank' => [
            'cash' => [
                'title'        => 'Cash account',
                'account_name' => 'Cash',
            ],

            'txn' => [
                'page_title' => 'Bank transactions',
                'eyebrow'    => 'Bank',

                'btn_statement' => 'Bank statement',
                'new_btn'       => '+ New transaction',

                'type_in'  => 'IN',
                'type_out' => 'OUT',

                'err_missing_bank'   => 'Missing bank_id',
                'err_bank_not_found' => 'Bank account not found',

                'summary' => [
                    'eyebrow'        => 'Summary',
                    'title'          => 'Balance overview',
                    'opening_simple' => 'Opening balance',
                    'in'             => 'IN this period',
                    'out'            => 'OUT this period',
                    'net'            => 'Net movement',
                    'current_simple' => 'Current balance',
                ],

                'filter' => [
                    'type'     => 'Type',
                    'view_cur' => 'View currency',
                    'q'        => 'Search keyword',
                    'q_ph'     => 'Ref no. / Description',
                ],

                'view_cur_account' => '{cur} (account)',
                'view_cur_myr'     => 'MYR (converted)',

                'row' => [
                    'opening' => 'Opening balance before period',
                ],

                'col' => [
                    'date'    => 'Date',
                    'type'    => 'Type',
                    'ref'     => 'Ref',
                    'desc'    => 'Description',
                    'cur'     => 'Cur',
                    'amount'  => 'Amount',
                    'myr'     => 'MYR',
                    'balance' => 'Balance ({cur})',
                ],

                'empty' => 'No transactions for this filter.',
            ],

            'txn_edit' => [
                'eyebrow'   => 'BANK TRANSACTION',
                'subtitle'  => 'Record bank / USDT movements for this account.',

                'title_new' => 'New bank transaction',
                'title_edit' => 'Edit bank transaction',

                'saved'     => 'Transaction saved.',
                'save_btn'  => 'Save transaction',

                'pick' => [
                    'title'       => 'Select bank account',
                    'desc'        => 'Please choose which bank / wallet you want to create transaction for.',
                    'field'       => 'Bank account',
                    'ph'          => '— Please select —',
                    'id_fallback' => 'ID {id}',
                ],

                'section' => [
                    'main' => 'Transaction details',
                ],

                'field' => [
                    'date'        => 'Date',
                    'type'        => 'Type',
                    'description' => 'Description',
                    'ref_no'      => 'Reference no.',
                    'amount'      => 'Amount',
                    'currency'    => 'Currency',
                    'rate_to_myr' => 'Rate → MYR',
                ],

                'help' => [
                    'rate' => 'For USDT, enter 1 USDT = ? MYR.',
                ],

                'type_allocate' => 'Allocate to another bank',

                'allocate' => [
                    'target_label'   => 'Transfer to bank account',
                    'target_ph'      => '— Select target bank —',
                    'tip'            => 'Same-currency accounts only. System will auto-create OUT (this bank) and IN (target bank).',
                    'currency_fixed' => 'Currency is fixed to this bank for allocation.',
                ],

                'allocate_from' => 'Allocate from {name}',

                'attach' => [
                    'title'     => 'Attachments',
                    'desc'      => 'Upload PDF / image files as supporting documents.',
                    'upload'    => 'Upload files',
                    'existing'  => 'Existing files',
                    'tip_types' => 'PDF, PNG, JPG, GIF',

                    'err_upload' => 'File "{name}" upload error (code {code}).',
                    'err_type'   => 'File "{name}" skipped (invalid type: {type}).',
                    'err_move'   => 'File "{name}" failed to move.',
                ],

                'err_bank_not_found' => 'Bank account not found',
                'err_txn_not_found'  => 'Bank transaction not found',

                'error' => [
                    'date_required'          => 'Date is required.',
                    'amount_required'        => 'Amount cannot be zero.',
                    'rate_required'          => 'Rate to MYR is required for non-MYR currency.',
                    'save_failed'            => 'Save failed',

                    'target_required'        => 'Please select target bank.',
                    'target_same'            => 'Cannot allocate to the same bank.',
                    'target_invalid'         => 'Invalid target bank.',
                    'allocate_same_currency' => 'Allocate only supports same-currency accounts for now.',
                ],
            ],

            'stmt' => [
                'page_title' => 'Bank statements',
                'eyebrow'    => 'BANK STATEMENT',

                'opening' => 'Opening balance',
                'current' => 'Current balance',

                'upload_title' => 'Upload statement',
                'upload_btn'   => 'Upload',
                'upload_ok'    => 'Statement uploaded.',

                'month'  => 'Month',
                'label'  => 'Label',
                'remark' => 'Remark',
                'file'   => 'Statement file',

                'label_ph'  => 'e.g. July 2025',
                'remark_ph' => 'e.g. Maybank e-statement',

                'label_tip' => 'If empty, system will auto-fill like "July 2025".',
                'file_tip'  => 'PDF, PNG, JPG, GIF',

                'search'    => 'Search',
                'search_ph' => 'Search label / remark / file',

                'col' => [
                    'month'       => 'Month',
                    'label'       => 'Label',
                    'remark'      => 'Remark',
                    'file'        => 'File',
                    'size'        => 'Size',
                    'uploaded_at' => 'Uploaded at',
                ],

                'empty' => 'No statements yet.',
                'confirm_delete' => 'Delete this statement?',

                'err' => [
                    'month'         => 'Please select a valid month.',
                    'file_required' => 'Please choose a statement file.',
                    'file_type'     => 'Invalid file type.',
                    'upload'        => 'Upload error (code {code}).',
                    'general'       => 'Upload failed: ',
                ],
            ],

            'all_txn' => [
                'page_title' => 'All bank transactions',
                'eyebrow'    => 'BANK SUMMARY',
                'subtitle'   => 'Combined view of all bank / wallet accounts in MYR.',

                'summary' => 'Summary',

                'opening'    => 'Opening balance',
                'current'    => 'Current balance',
                'total_in'   => 'Total IN',
                'total_out'  => 'Total OUT',
                'net'        => 'Net',

                'filter' => [
                    'type'      => 'Type',
                    'search'    => 'Search',
                    'search_ph' => 'Description / Ref / Bank / Acc no',
                    'accounts'  => 'Accounts',
                ],

                'no_accounts' => 'No active bank accounts.',
                'empty'       => 'No transactions for this filter.',

                'ref'         => 'Ref',
                'attachments' => 'Attachments',

                'col' => [
                    'date'    => 'Date',
                    'type'    => 'Type',
                    'account' => 'Account',
                    'desc'    => 'Description',
                    'amount'  => 'Amount',
                    'rate'    => 'Rate → MYR',
                    'myr'     => 'MYR',
                ],
            ],
        ],

        // =========================
        // TXN IN (你后面原本继续的内容)
        // =========================
        'txn_in' => [
            'page_title' => 'IN Transaction',
            'eyebrow'    => 'IN transaction',
            'subtitle'   => 'Create / edit one IN order with multiple payments.',
            'back'       => '← Back to transactions',
            'saved'      => 'IN transaction saved.',

            'basic' => [
                'title' => 'Basic details',
                'desc'  => 'Date, type, amount and main currency.',
            ],

            'field' => [
                'date'       => 'Date',
                'in_type'    => 'IN type',
                'invoice_no' => 'Invoice No',
                'amount'     => 'Amount',
            ],

            'in_kind' => [
                'invoice' => 'Invoice / normal IN',
                'return'  => 'Repayment / return capital',
                'bonus'   => 'Bonus / profit share',
                'help'    => 'INVOICE = invoice; RETURN = capital; BONUS = others',
            ],

            'invoice_ph' => 'Leave blank for auto VMYYMM-XXX',
            'invoice_disabled_help' => 'RETURN / BONUS do not use invoice no.',
            'currency_ph' => 'MYR / USD / SGD ...',
            'amount_help' => 'Amount & currency will be used as the main currency for this IN. Paid / Pending are calculated in the same currency.',

            'paid_so_far' => 'Paid so far',
            'pending'     => 'Pending',

            'payments' => [
                'title'    => 'Payment for this order',
                'desc'     => 'Add one or more payment lines. Each line will also create a bank transaction (with MYR reference only inside bank table).',
                'add_line' => '+ Add line',
            ],

            'col' => [
                'or_no'     => 'Official Receipt No',
                'pay_date'  => 'Date',
                'amount'    => 'Amount',
                'bank'      => 'Bank',
                'currency'  => 'Currency',
                'fx_rate'   => 'FX → MYR',
                'attach'    => 'Payment Attachment',
                'receipt'   => 'Receipt / View',
                'invoice'   => 'Invoice',
                'action'    => 'Action',
            ],

            'select'  => '— Select —',
            'or_ph'   => 'Auto if empty',
            'fx_ph'   => 'e.g. 4.700000',
            'after_save' => 'After save',

            'attach' => [
                'add'          => '+ Add',
                'saved'        => 'Saved:',
                'delete'       => 'Delete',
                'default_name' => 'Attachment %d',
            ],

            'notes_sign' => [
                'title' => 'Notes & Signatures',
                'desc'  => 'Internal notes, attachments and signatures.',
            ],

            'notes' => 'Notes',

            'txn_attach' => [
                'label' => 'Attachments (for this IN)',
                'help'  => 'Attach invoice, DO, contract, etc. (multiple files allowed).',
            ],

            'our' => [
                'title'        => 'Our side (who receive)',
                'company'      => 'Our company',
                'company_ph'   => '— Select company —',
                'staff'        => 'Our staff / signatory',
                'staff_ph'     => '— Select staff —',
                'staff_help'   => 'Select who receives / signs on our side. Used together with “Sign receive”.',
            ],

            'recipient' => [
                'name'     => 'Recipient name (who signs)',
                'name_ph'  => 'Type or pick from login users...',
                'nric'     => 'Recipient NRIC / passport',
                'tip'      => 'When you pick a login user name, NRIC will auto-fill. You can also type both name and NRIC manually.',
            ],

            'sign' => [
                'sign_receive'      => 'Sign receive (our signature on receipt)',
                'sign_receive_help' => 'Checked = show “Received by” + company chop. Unchecked = only company chop.',
                'need_customer'     => 'Require customer signature',
                'need_customer_help' => 'Checked = customer must sign (name / NRIC above). Unchecked = customer signature not required.',
            ],

            'btn' => [
                'cancel' => 'Cancel',
                'save'   => 'Save',
            ],

            'view_invoice' => 'View invoice',
            'view_receipt' => 'View receipt',

            'err' => [
                'not_found'          => 'IN transaction not found',
                'customer_mismatch'  => 'Customer mismatch',
                'missing_customer'   => 'Missing customer_id',
                'customer_not_found' => 'Customer not found',
                'date_required'      => 'Date is required',
                'amount_gt_zero'     => 'Amount must be greater than 0',
                'save_failed'        => 'Save error: %s',
            ],
        ],

        // =========================
        // CUSTOMER LOGIN USERS (portal account) — admin side
        // =========================
        'customer_user' => [
            'edit' => [
                'page_title'           => 'Customer login user',
                'eyebrow'              => 'Login user',
                'title_edit'           => 'Edit login user',
                'title_new'            => 'New login user',
                'subtitle'             => 'Manage portal login for this customer.',
                'saved'                => 'User saved.',
                'section_account'      => 'Account',
                'section_account_desc' => 'Username and basic contact info for this login.',
            ],

            'field' => [
                'username'      => 'Username',
                'full_name'     => 'Full name',
                'nric'          => 'NRIC / IC',
                'email'         => 'Email',
                'phone'         => 'Phone',
                'password_new'  => 'Password *',
                'password_edit' => 'Password (leave blank = no change)',
                'active'        => 'Active',
            ],

            'error' => [
                'username_required'      => 'Username is required',
                'full_name_required'     => 'Name is required',
                'username_exists'        => 'Username already exists',
                'username_exists_global' => 'Username already exists',
                'password_required_new'  => 'Password is required for new user',
                'password_required'      => 'Password is required',
            ],

            'list' => [
                'page_title'     => 'Customer Logins: ',
                'eyebrow'        => 'Customer logins',
                'saved'          => 'User saved.',
                'existing_title' => 'Existing Logins',
                'no_users'       => 'No login users yet.',
                'btn_edit'       => 'Edit / Reset',
                'add_title'      => 'Add New Login',
                'btn_create'     => 'Create Login',
            ],

            'col' => [
                'id'       => 'ID',
                'username' => 'Username',
                'name'     => 'Name',
                'nric'     => 'NRIC / IC',
                'email'    => 'Email',
                'phone'    => 'Phone',
                'active'   => 'Active',
                'action'   => 'Action',
            ],
        ],
    ], // end 'admin'

    // =========================
    // CUSTOMER PORTAL BLOCK
    // =========================
    'portal' => [
        'lang' => [
            'en' => 'English',
            'ms' => 'Malay',
            'zh' => 'Chinese',
        ],

        'header' => [
            'app_title'     => 'Customer Portal',
            'logout'        => 'Log out',
            'signed_in_as'  => 'Signed in as',
        ],

        'footer' => [
            'powered_by' => 'Powered by Vision Mix',
        ],

        'sidebar' => [
            'section_main'     => 'Main',
            'section_settings' => 'Settings',
            'dashboard'        => 'Dashboard',
            'txns'             => 'Transactions / Reports',
            'users'            => 'Login users',
        ],

        'status' => [
            'confirmed' => 'CONFIRMED',
            'sent'      => 'SENT',
            'draft'     => 'DRAFT',
        ],

        'dashboard' => [
            'pending_eyebrow'   => 'Pending',
            'pending_title'     => 'Pending signatures',
            'pending_subtitle'  => 'These payouts are waiting for your signature.',
            'pending_view_all'  => 'View all pending',
            'pending_sign_btn'  => 'Sign',
            'pending_type_receipt' => 'Receipt (we pay you)',
            'pending_type_invoice' => 'Invoice (you pay us)',

            'table' => [
                'date'    => 'Date',
                'title'   => 'Title',
                'amount'  => 'Amount',
                'status'  => 'Status',
                'actions' => 'Actions',
                'type' => 'Type',
            ],

            'row1' => [
                'total_out' => 'Total OUT (you paid us)',
                'total_in'  => 'Total IN (we paid you)',
                'balance'   => 'Balance (OUT - IN)',
            ],

            'row2' => [
                'repayment' => 'Repayment',
                'bonus'     => 'Bonus',
            ],

            'row3' => [
                'summary_out' => 'Summary total OUT',
                'summary_in'  => 'Summary total IN',
                'summary_net' => 'Summary net (OUT - IN)',
            ],

            'customer_eyebrow'  => 'Customer',
            'customer_subtitle' => 'Overall summary of your transactions and balance.',

            'summary_eyebrow'   => 'Summary',
            'summary_title'     => 'After contra',
            'summary_out_label' => 'Total OUT (you paid us)',
            'summary_in_label'  => 'Total IN (we paid you)',
            'summary_bal_label' => 'Balance (OUT - IN)',
            'summary_we_owe'    => 'We owe you',
            'summary_you_owe'   => 'You owe us',
            'summary_balanced'  => 'Balanced',

            'recent_eyebrow'     => 'Recent',
            'recent_title'       => 'Last 10 transactions',
            'recent_full_report' => 'Full report',
            'recent_in_title'    => 'IN — We paid you',
            'recent_out_title'   => 'OUT — You paid us',
            'recent_no_payout'   => 'No payout records.',
            'recent_no_payment'  => 'No payment records.',
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
            'page_title'     => 'Login users',
            'eyebrow'        => 'Login users',
            'subtitle'       => 'Manage portal logins for your own company.',
            'created'        => 'User created / updated.',
            'existing_title' => 'Existing logins',
            'no_users'       => 'No login users yet.',
            'add_title'      => 'Add new login',
            'btn_edit'       => 'Edit / Reset',
            'btn_create'     => 'Create login',
            'col' => [
                'id'       => 'ID',
                'username' => 'Username',
                'name'     => 'Name',
                'email'    => 'Email',
                'phone'    => 'Phone',
                'active'   => 'Active',
                'action'   => 'Action',
            ],
            'ref_prefix' => 'Ref:',
        ],

        'edit' => [
            'page_title'            => 'Edit login user',
            'eyebrow'               => 'Login user',
            'title'                 => 'Edit login user',
            'subtitle'              => 'Update login details for your portal user.',
            'saved'                 => 'User saved.',
            'section_account_title' => 'Account',
            'section_account_desc'  => 'Username and contact info for this login.',
        ],

        'field' => [
            'username'      => 'Username',
            'password'      => 'Password',
            'password_hint' => 'Password (leave blank = no change)',
            'full_name'     => 'Full name',
            'email'         => 'Email',
            'phone'         => 'Phone',
            'active'        => 'Active',
            'nric'          => 'NRIC',
        ],

        'error' => [
            'username_required'  => 'Username is required',
            'full_name_required' => 'Name is required',
            'password_required'  => 'Password is required',
            'username_exists'    => 'Username already exists',
            'general_failed'     => 'Failed to save. Please try again.',
        ],

        'btn' => [
            'back' => 'Back',
            'save' => 'Save',
        ],

        'col' => [
            'id'       => 'ID',
            'username' => 'Username',
            'name'     => 'Name',
            'email'    => 'Email',
            'phone'    => 'Phone',
            'active'   => 'Active',
            'action'   => 'Action',
            'nric'     => 'NRIC',
        ],
    ],

    // ===== Portal: Transactions list & view =====
    'txn' => [
        'type' => [
            'in'  => 'IN (we paid you)',
            'out' => 'OUT (you paid us)',
        ],

        'badge' => [
            'contra' => 'Contra',
        ],

        'list' => [
            'page_title'       => 'Transactions / Reports',
            'eyebrow_customer' => 'Customer',
            'subtitle'         => 'Transactions and simple report. IN = we paid you, OUT = you paid us.',

            'filter' => [
                'type'        => 'Type',
                'type_all'    => 'All',
                'type_in'     => 'IN (we paid you)',
                'type_out'    => 'OUT (you paid us)',

                'status'      => 'Status',
                'status_all'  => 'All',

                'method'       => 'Method',
                'method_all'   => 'All',
                'method_cash'  => 'Cash',
                'method_bank'  => 'Bank',
                'method_usdt'  => 'USDT',
                'method_other' => 'Other',

                'keyword'    => 'Keyword',
                'keyword_ph' => 'Title / Ref no...',
            ],

            'col' => [
                'date'    => 'Date',
                'type'    => 'Type',
                'method'  => 'Method',
                'title'   => 'Title',
                'amount'  => 'Amount',
                'status'  => 'Status',
                'action'  => 'Action',
                'balance' => 'Balance',
                'pending' => 'Pending',
            ],

            'btn_view'   => 'View',
            'ref_prefix' => 'Ref:',
            'empty'      => 'No transactions for this filter.',
        ],

        'view' => [
            'page_title' => 'Transaction detail',
            'eyebrow'    => 'Transaction',

            'type_label_in'  => 'IN — We paid you',
            'type_label_out' => 'OUT — You paid us',

            'field' => [
                'type'               => 'Type',
                'amount'             => 'Amount',
                'status'             => 'Status',
                'date'               => 'Date',
                'ref_no'             => 'Reference no.',
                'method'             => 'Method',
                'title'              => 'Title',
                'notes'              => 'Notes',
                'recipient_name'     => 'Recipient name',
                'recipient_nric'     => 'Recipient NRIC',
                'signature_required' => 'Signature required',
            ],

            'section_details_title' => 'Details',
            'section_details_desc'  => 'Basic information for this transaction.',

            'section_recipient_title' => 'Recipient / Signature',
            'section_recipient_desc'  => 'Information of who receives the money.',

            'section_attach_title' => 'Attachments',
            'section_attach_desc'  => 'Open or download the attached documents (PDF / image).',

            'section_in_title' => 'IN transaction details',
            'section_in_desc'  => 'Order amount, payments and attachments.',

            'paid_myr'    => 'Paid so far (MYR)',
            'pending_myr' => 'Pending (MYR)',

            'section_payments_title'      => 'Payments',
            'section_notes_attach_title'  => 'Notes attachments',

            'col' => [
                'or_no'    => 'Official Receipt No',
                'pay_date' => 'Date',
                'amount'   => 'Amount',
                'bank'     => 'Bank',
                'currency' => 'Currency',
                'fx_rate'  => 'FX → MYR',
                'attach'   => 'Payment attachment',
                'invoice'  => 'Invoice',
            ],

            'attach_default'    => 'Attachment',
            'attach_none_short' => 'No attachment',
            'payments_none'     => 'No payment yet.',

            'alert_pending'   => 'This payout is pending your confirmation / signature. Please click “View receipt” above to review and sign on-site with our staff.',
            'alert_confirmed' => 'This payout has been confirmed and signed.',
            'alert_contra'    => 'This entry was created by allocation (contra). No signature is required.',

            'sig_status_signed'      => 'Already signed / confirmed',
            'sig_status_pending_you' => 'Pending your signature',
            'sig_status_pending'     => 'Pending',

            'attach_tip'  => 'Click any file name to view or download.',
            'attach_none' => 'No attachment uploaded for this transaction.',

            'btn_back'    => '← Back to transactions',
            'btn_receipt' => 'View receipt',
            'btn_invoice' => 'View invoice',
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
                'back'         => 'Back',
                'back_to_txns' => 'Back to transactions',
                'back_to_list' => 'Back to list',
                'cancel'       => 'Cancel',
                'clear'        => 'Clear',
            ],
            'error' => [
                'save_failed' => 'Failed to save. Please try again.',
            ],
        ],

        'txn' => [
            'type_label' => [
                'in'  => 'IN — We paid you',
                'out' => 'OUT — You paid us',
            ],

            'badge' => [
                'contra' => 'Contra',
            ],

            'btn' => [
                'print_pdf' => 'Print / PDF',
            ],

            'method' => [
                'cash'  => 'Cash',
                'bank'  => 'Bank transfer',
                'usdt'  => 'USDT',
                'other' => 'Other',
            ],

            'receipt' => [
                'title'                 => 'RECEIPT / CONFIRMATION',
                'page_title'            => 'Receipt / Confirmation',
                'eyebrow'               => 'Receipt / Confirmation',
                'txn_no'                => 'Txn #',

                'date'                  => 'Date',
                'received_from'         => 'Received from (Payer):',
                'received_by'           => 'Received by / On behalf of:',
                'received_by_company'   => '(Our company)',

                'recipient'             => 'Recipient (who signs):',
                'recipient_placeholder' => '(please fill in recipient name in the portal)',
                'received_from_payer'   => 'Received from (Payer):',
                'received_from_rep'     => 'Representative:',
                'nric_label'            => 'NRIC:',
                'received_by_on_behalf' => 'Received by / On behalf of:',
                'address'               => 'Address:',

                'amount'                => 'Amount:',
                'method'                => 'Payment method:',
                'ref_no'                => 'Reference no.:',
                'notes'                 => 'Notes:',
                'attachment'            => 'Attachment:',

                'footer_text'           => 'This receipt confirms the above amount.',

                'sig_receiver_title'    => 'Signature (Receiver / Customer)',
                'sig_receiver_none'     => 'No customer signature yet.',

                'sig_payer_title'       => 'Signature (Payer / Our company)',
                'sig_payer_none'        => 'No payer signature yet.',

                'name_label'            => 'Name:',
                'nric_short'            => 'NRIC:',
                'date_label'            => 'Date:',
            ],

            'sign' => [
                'saved'                => 'Signature and recipient saved.',

                'who_title'            => 'Who signs',
                'who_desc'             => 'Choose the person who signs on your side, then sign inside the box.',

                'recipient_name'       => 'Recipient name',
                'recipient_placeholder' => 'Type or pick from your login users...',
                'recipient_nric'       => 'Recipient NRIC',
                'auto_fill_hint'       => 'If you pick from the list above, NRIC will auto-fill. You can also type both name and NRIC manually.',

                'sign_here_title'      => 'Sign here',
                'sign_here_desc'       => 'Sign inside the box. Once saved, this payout will be marked as CONFIRMED.',

                'btn_save'             => 'Save recipient & signature',

                'page_title'           => 'Sign transaction',
                'eyebrow'              => 'Payout confirmation',
                'default_title'        => 'Payout',
                'subtitle'             => 'Confirm that you have received this payment by signing below.',

                'not_pending'          => 'This transaction is not pending your signature (maybe already confirmed, no signature required, or contra).',
                'thanks'               => 'Thank you. This payout has been signed and confirmed.',

                'details_title'        => 'Transaction details',
                'date'                 => 'Date',
                'method'               => 'Method',
                'amount'               => 'Amount',
                'ref_no'               => 'Reference no.',
                'recipient_name_label' => 'Recipient name (who signs)',
                'recipient_nric_label' => 'Recipient NRIC',
                'attachment'           => 'Attached document',

                'sign_box_title'       => 'Sign here',
                'sign_box_desc'        => 'Please sign inside the box using mouse or touch.',

                'btn_save_signature'   => 'Save signature',

                'error_sign_box'       => 'Please sign in the box before saving.',
            ],
        ],
    ],
];
