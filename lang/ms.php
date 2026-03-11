<?php
// lang/ms.php
declare(strict_types=1);

return [

    // ----- Global common (admin + portal 通用) -----
    'common' => [
        'status' => 'Status',
        'cancel' => 'Batal',
        'save'   => 'Simpan',
        'yes'    => 'Ya',
        'no'     => 'Tidak',
        'apply'  => 'Guna',
        'reset'  => 'Set Semula',

        'date_range' => 'Julat Tarikh',
        'select_date_range' => 'Pilih julat tarikh',

        'mo' => 'Isn',
        'tu' => 'Sel',
        'we' => 'Rab',
        'th' => 'Kha',
        'fr' => 'Jum',
        'sa' => 'Sab',
        'su' => 'Ahd',

        'month_year' => 'Bulan YYYY',

        'today' => 'Hari ini',
        'yesterday' => 'Semalam',
        'this_week' => 'Minggu ini',
        'last_week' => 'Minggu lepas',
        'this_month' => 'Bulan ini',
        'last_month' => 'Bulan lepas',
        'this_year' => 'Tahun ini',
        'last_year' => 'Tahun lepas',
        'all' => 'Semua',
    ],

    // =========================
    // ADMIN BLOCK
    // =========================
    'admin' => [

        // ----- Header / Footer -----
        'header' => [
            'app_title'      => 'Admin',
            'toggle_sidebar' => 'Togol sidebar',
            'logout'         => 'Log keluar',
        ],
        'footer' => [
            'rights' => 'Hak cipta terpelihara.',
        ],

        // ----- Reusable common labels -----
        'common' => [
            'apply'     => 'Guna',
            'reset'     => 'Set Semula',
            'prev'      => 'Sebelum',
            'next'      => 'Seterusnya',
            'save'      => 'Simpan',
            'cancel'    => 'Batal',
            'search'    => 'Cari',
            'export'    => 'Eksport',
            'actions'   => 'Tindakan',
            'edit'      => 'Edit',
            'all'       => 'Semua',
            'page_line' => 'Muka %d / %d',
            'inactive'  => 'Tidak aktif',
            'active'    => 'Aktif',
            'back'      => 'Kembali',
            'yes'       => 'Ya',
            'no'        => 'Tidak',

            'delete'   => 'Padam',
            'view'     => 'Lihat',
            'status'   => 'Status',
        ],

        // ----- Navigation -----
        'nav' => [
            'section' => [
                'overview'  => 'RINGKASAN',
                'customers' => 'PELANGGAN',
                'payers'    => 'PEMBAYAR',
                'reports'   => 'LAPORAN',
                'security'  => 'KESELAMATAN',

                'bank' => 'BANK',
            ],

            'bank' => [
                'accounts'     => 'Akaun Bank',
                'transactions' => 'Transaksi Bank',
            ],

            'dashboard'          => 'Dashboard',
            'customer_list'      => 'Senarai Pelanggan',
            'payer_companies'    => 'Syarikat Pembayar',
            'payer_staff'        => 'Staf Pembayar',
            'transaction_report' => 'Laporan Transaksi',
            'customer_report'    => 'Laporan Pelanggan',
            'users'              => 'Pengguna',
            'roles'              => 'Peranan & Kebenaran',
            'audit_logs'         => 'Log Audit',
        ],

        // ----- Dashboard -----
        'dashboard' => [
            'title'       => 'Papan Pemuka',
            'eyebrow'     => 'Ringkasan',

            'pending_sig' => 'Belum selesai',

            'this_month'  => 'Bulan ini',
            'total_in'    => 'Jumlah IN',
            'total_out'   => 'Jumlah OUT',
            'pending'     => 'Belum Dibayar',

            'all_time'        => 'Sepanjang masa',
            'return_balance'  => 'Baki Pulangan',
            'bonus'           => 'Bonus',

            'bank_total' => 'Jumlah baki semasa bank',
            'no_bank'    => 'Tiada akaun bank aktif.',

            'charts'    => 'Carta',
            'pie_title' => 'IN vs OUT (bulan ini)',
            'bar_title' => '6 bulan terakhir (IN / OUT)',
            'total'     => 'Jumlah',

            'bank_in'    => 'Bank IN',
            'latest_in'  => 'Transaksi Bank IN terkini',
            'bank_out'   => 'Bank OUT',
            'latest_out' => 'Transaksi Bank OUT terkini',

            'col' => [
                'date'   => 'Tarikh',
                'bank'   => 'Bank',
                'desc'   => 'Keterangan',
                'amount' => 'Amaun',
                'ref'    => 'Ruj',
            ],

            'in_empty'  => 'Tiada transaksi Bank IN lagi.',
            'out_empty' => 'Tiada transaksi Bank OUT lagi.',
        ],

        // ----- Audit Logs -----
        'audit' => [
            'title'    => 'Log Audit',
            'eyebrow'  => 'Keselamatan',
            'subtitle' => 'Jejak siapa buat apa dan bila. Papar %d log setiap muka.',
            'filter' => [
                'user'        => 'Pengguna',
                'user_all'    => 'Semua pengguna',
                'action'      => 'Tindakan',
                'action_all'  => 'Semua tindakan',
                'keyword'     => 'Kata kunci',
                'keyword_ph'  => 'Cari penerangan / entiti / extra...',
            ],
            'total_line' => 'Jumlah: %d rekod · %d setiap muka',
            'page_line'  => 'Muka %d / %d',
            'col' => [
                'time'        => 'Masa',
                'user'        => 'Pengguna',
                'action'      => 'Tindakan',
                'entity'      => 'Entiti',
                'description' => 'Penerangan',
                'extra'       => 'Extra',
                'ip'          => 'IP',
            ],
            'empty'          => 'Tiada rekod audit untuk tapisan ini.',
            'system_unknown' => 'Sistem / Tidak diketahui',
        ],

        // ----- Customer common status -----
        'customer' => [
            'status' => [
                'active'   => 'Aktif',
                'inactive' => 'Tidak aktif',
            ],
        ],

        // =========================
        // ROLES & PERMISSIONS
        // =========================
        'roles' => [

            'list' => [
                'title'    => 'Peranan & Kebenaran',
                'eyebrow'  => 'Keselamatan',
                'subtitle' => 'Tetapkan kumpulan kebenaran dan assign kepada pengguna.',
                'new_btn'  => '+ Peranan Baru',
                'col' => [
                    'code'    => 'Kod',
                    'name'    => 'Nama',
                    'status'  => 'Status',
                    'actions' => 'Tindakan',
                ],
                'empty' => 'Tiada peranan ditemui.',
            ],

            'edit' => [
                'title_new'        => 'Peranan Baru',
                'title_edit'       => 'Edit Peranan',
                'title_new_label'  => 'Peranan Baru',

                'eyebrow'  => 'Peranan & Kebenaran',
                'subtitle' => 'Urus butiran peranan dan tandakan kebenaran yang perlu.',

                'badge_id' => 'ID: %d',
                'saved'    => 'Peranan & kebenaran telah disimpan.',

                'error' => [
                    'code_required'  => 'Kod wajib diisi.',
                    'name_required'  => 'Nama peranan wajib diisi.',
                    'code_unique'    => 'Kod ini telah digunakan oleh peranan lain.',
                    'save_failed'    => 'Gagal simpan',
                ],

                'section' => [
                    'details_title' => 'Butiran peranan',
                    'details_desc'  => 'Kod dan nama digunakan untuk kenal pasti peranan ini.',
                    'perms_title'   => 'Kebenaran',
                    'perms_desc'    => 'Tandakan / buang tanda kebenaran di bawah.',
                ],

                'field' => [
                    'code'        => 'Kod',
                    'name'        => 'Nama peranan',
                    'description' => 'Penerangan',
                    'active'      => 'Aktif',
                ],

                'group' => [
                    'all'  => 'Semua',
                    'none' => 'Tiada',
                ],

                'save_btn'        => 'Simpan kebenaran',
                'title_new_label' => 'Peranan Baru',
            ],
        ],

        // =========================
        // INTERNAL ADMIN USERS
        // =========================
        'users_admin' => [

            'list' => [
                'title'    => 'Pengguna Dalaman',
                'eyebrow'  => 'Keselamatan',
                'subtitle' => 'Urus akaun admin dan peranan mereka.',
                'new_btn'  => '+ Pengguna Baru',

                'search_ph' => 'Cari username / nama / email',

                'col' => [
                    'username' => 'Username',
                    'fullname' => 'Nama penuh',
                    'email'    => 'Email',
                    'roles'    => 'Peranan',
                    'status'   => 'Status',
                    'actions'  => 'Tindakan',
                ],

                'empty'    => 'Tiada pengguna ditemui.',
                'no_roles' => '(tiada peranan)',
            ],

            'edit' => [
                'eyebrow' => 'Keselamatan',

                'title_new'  => 'Pengguna baru',
                'title_edit' => 'Edit pengguna',

                'subtitle' => 'Akaun admin dalaman dan peranannya.',

                'back_to_list' => '← Kembali ke senarai pengguna',

                'section' => [
                    'account_title' => 'Akaun',
                    'account_desc'  => 'Username & password untuk log masuk.',

                    'profile_title' => 'Profil',
                    'roles_title'   => 'Peranan',
                    'roles_desc'    => 'Seorang pengguna boleh ada banyak peranan. Kebenaran datang dari peranan.',
                ],

                'field' => [
                    'username'         => 'Username',
                    'password_new'     => 'Password',
                    'password_change'  => 'Password baru',
                    'confirm_password' => 'Sahkan password',
                    'full_name'        => 'Nama penuh',
                    'email'            => 'Email',
                    'phone'            => 'Telefon',
                    'nric'             => 'NRIC',
                    'status'           => 'Status',
                    'status_active'    => 'Aktif',
                ],

                'error' => [
                    'username_required' => 'Username wajib diisi',
                    'fullname_required' => 'Nama penuh wajib diisi',
                    'password_required' => 'Password wajib diisi',
                    'password_mismatch' => 'Password dan pengesahan tidak sama',
                    'username_used'     => 'Username telah digunakan',
                ],

                'no_roles_defined' => 'Belum ada peranan didefinisikan. Sila cipta sekurang-kurangnya satu peranan dahulu.',
            ],
        ],

        // =========================
        // PAYER COMPANY
        // =========================
        'payer_company' => [
            'title'      => 'Syarikat Pembayar',
            'edit_title' => 'Edit Syarikat Pembayar',
            'new_title'  => 'Syarikat Pembayar Baru',
            'eyebrow'    => 'Data induk',
            'subtitle'   => 'Maklumat asas syarikat pembayar.',

            'error' => [
                'name_required' => 'Nama syarikat wajib diisi.',
            ],

            'field' => [
                'name'   => 'Nama syarikat',
                'reg_no' => 'No. pendaftaran',
            ],

            'action' => [
                'new' => '+ Syarikat Baru',
            ],

            'search' => [
                'ph' => 'Cari ikut nama / no. pendaftaran...',
            ],

            'col' => [
                'id'         => 'ID',
                'name'       => 'Nama syarikat',
                'reg_no'     => 'No. pendaftaran',
                'created_at' => 'Dicipta pada',
            ],

            'empty' => 'Tiada syarikat pembayar ditemui.',
        ],

        // =========================
        // PAYER STAFF
        // =========================
        'payer_staff' => [
            'title'      => 'Staf Pembayar',
            'edit_title' => 'Edit Staf Pembayar',
            'new_title'  => 'Staf Pembayar Baru',
            'eyebrow'    => 'Data induk',
            'subtitle'   => 'Staf penandatangan yang boleh dipilih untuk mana-mana syarikat pembayar.',

            'error' => [
                'name_required' => 'Nama staf wajib diisi.',
            ],

            'field' => [
                'name'   => 'Nama',
                'ic'     => 'IC / Passport',
                'phone'  => 'Telefon',
                'email'  => 'Email',
                'active' => 'Aktif',
            ],

            'action' => [
                'new' => '+ Staf Baru',
            ],

            'search' => [
                'ph' => 'Cari ikut nama / IC / telefon...',
            ],

            'col' => [
                'id'     => 'ID',
                'name'   => 'Nama',
                'ic'     => 'IC / Passport',
                'phone'  => 'Telefon',
                'email'  => 'Email',
                'status' => 'Status',
            ],

            'status' => [
                'active'   => 'Aktif',
                'inactive' => 'Tidak aktif',
            ],

            'empty' => 'Tiada staf pembayar ditemui.',
        ],

        // =========================
        // REPORTS (Customer + Transaction)
        // =========================
        'report' => [

            'eyebrow' => 'Laporan',

            'customer_list' => [
                'title' => 'Laporan Senarai Pelanggan',

                'filter' => [
                    'search'      => 'Cari',
                    'search_ph'   => 'Cari ikut kod / nama...',
                    'only_active' => 'Hanya aktif',
                ],

                'col' => [
                    'id'        => 'ID',
                    'customer'  => 'Pelanggan',
                    'code'      => 'Kod',
                    'in_after'  => 'Jumlah IN (selepas contra)',
                    'out_after' => 'Jumlah OUT (selepas contra)',
                    'net'       => 'Bersih (IN - OUT)',
                    'status'    => 'Status',
                ],

                'empty'       => 'Tiada pelanggan ditemui untuk tapisan ini.',
                'total_label' => 'JUMLAH',
            ],

            'transaction' => [
                'title' => 'Laporan Transaksi',

                'filter' => [
                    'customer'       => 'Pelanggan',
                    'customer_all'   => 'Semua pelanggan',
                    'type'           => 'Jenis',
                    'status'         => 'Status',
                    'method'         => 'Kaedah',
                    'contra'         => 'Contra',
                    'contra_only'    => 'Contra sahaja',
                    'contra_without' => 'Selepas contra (sorok fully allocated)',
                ],

                'metric' => [
                    'total_in_label'  => 'Jumlah IN (ikut tapisan)',
                    'total_in_sub'    => 'Semua transaksi IN ikut tapisan semasa.',
                    'total_out_label' => 'Jumlah OUT (ikut tapisan)',
                    'total_out_sub'   => 'Semua transaksi OUT ikut tapisan semasa.',
                    'net_label'       => 'Bersih (IN - OUT)',
                    'net_sub'         => 'Positif = lebih banyak IN daripada OUT dalam hasil tapisan.',
                ],

                'details' => [
                    'eyebrow' => 'Butiran',
                    'title'   => 'Transaksi',
                    'badge'   => 'Jumlah: %d rekod · %d setiap muka',
                ],

                'col' => [
                    'date'        => 'Tarikh',
                    'customer'    => 'Pelanggan',
                    'type'        => 'Jenis',
                    'amount'      => 'Amaun',
                    'method'      => 'Kaedah',
                    'ref'         => 'Rujukan',
                    'title_notes' => 'Tajuk / Nota',
                ],

                'flag' => [
                    'contra' => 'Contra',
                ],

                'empty' => 'Tiada transaksi ditemui untuk tapisan ini.',
            ],
        ],

        // =========================
        // Legacy payers (for compatibility)
        // =========================
        'payers' => [
            'company_list_title'   => 'Syarikat Pembayar',
            'company_edit_title'   => 'Edit Syarikat Pembayar',
            'company_new_title'    => 'Syarikat Pembayar Baru',
            'company_name'         => 'Nama syarikat',
            'company_reg_no'       => 'No. pendaftaran',
            'company_basic_info'   => 'Maklumat asas syarikat pembayar.',
            'company_add'          => '+ Syarikat Baru',

            'staff_list_title'     => 'Staf Pembayar',
            'staff_edit_title'     => 'Edit Staf Pembayar',
            'staff_new_title'      => 'Staf Pembayar Baru',
            'staff_name'           => 'Nama',
            'staff_ic'             => 'IC / Passport',
            'staff_phone'          => 'Telefon',
            'staff_email'          => 'Email',
            'staff_active'         => 'Aktif',
            'staff_inactive'       => 'Tidak aktif',
            'staff_add'            => '+ Staf Baru',
            'staff_basic_info'     => 'Staf penandatangan yang boleh dipilih untuk mana-mana syarikat pembayar',

            'search_placeholder_company' => 'Cari ikut nama / no. pendaftaran...',
            'search_placeholder_staff'   => 'Cari ikut nama / IC / telefon...',
        ],

        'reports' => [
            'customer_list_title' => 'Laporan Senarai Pelanggan',
            'transaction_title'   => 'Laporan Transaksi',

            'reports'       => 'Laporan',
            'details'       => 'Butiran',
            'export'        => 'Eksport',
            'search'        => 'Cari',
            'only_active'   => 'Hanya aktif',
            'customer'      => 'Pelanggan',

            'txn_type'      => 'Jenis',
            'txn_status'    => 'Status',
            'txn_method'    => 'Kaedah',
            'txn_contra'    => 'Contra',

            'all'           => 'Semua',
            'in_only'       => 'IN sahaja',
            'out_only'      => 'OUT sahaja',
            'contra_only'   => 'Contra sahaja',
            'after_contra'  => 'Selepas contra (sorok fully allocated)',

            'total_in'      => 'Jumlah IN (ikut tapisan)',
            'total_out'     => 'Jumlah OUT (ikut tapisan)',
            'net'           => 'Bersih (IN - OUT)',

            'no_data'       => 'Tiada data.',
        ],

        // =========================
        // CUSTOMERS
        // =========================
        'customers' => [
            'list' => [
                'title'     => 'Pelanggan',
                'eyebrow'   => 'Data induk',
                'new_btn'   => '+ Pelanggan Baru',
                'saved'     => 'Pelanggan disimpan.',
                'search_ph' => 'Cari ikut kod / nama / no. pendaftaran...',

                'col' => [
                    'code'      => 'Kod',
                    'name'      => 'Nama',
                    'in_after'  => 'IN (selepas contra)',
                    'out_after' => 'OUT (bayaran sebenar)',
                    'net_after' => 'Bersih (selepas contra)',
                    'status'    => 'Status',
                    'actions'   => 'Tindakan',
                ],

                'reg_prefix'           => 'Reg:',
                'empty'                => 'Tiada pelanggan ditemui.',
                'net_label_we_owe'     => 'Kami berhutang kepada pelanggan',
                'net_label_cust_owe'   => 'Pelanggan berhutang kepada kami',
                'net_label_balanced'   => 'Seimbang',
                'action_txn'           => 'Transaksi',
                'action_users'         => 'Pengguna login',
                'action_edit'          => 'Edit pelanggan',
            ],

            'edit' => [
                'title_new'       => 'Pelanggan Baru',
                'title_edit'      => 'Edit Pelanggan',
                'title_new_label' => 'Pelanggan Baru',
                'title_fallback'  => 'Pelanggan',

                'eyebrow_new'  => 'Cipta profil pelanggan',
                'eyebrow_edit' => 'Kemaskini profil pelanggan',
                'subtitle'     => 'Maklumat syarikat, orang hubungan dan info resit default.',

                'section' => [
                    'basic_title'    => 'Maklumat asas',
                    'basic_desc'     => 'Kod dalaman dan nama berdaftar syarikat.',
                    'contact_title'  => 'Hubungan & resit',
                    'contact_desc'   => 'Orang untuk dihubungi dan penandatangan resit.',
                    'address_title'  => 'Alamat',
                    'address_desc'   => 'Alamat berdaftar / pengebilan pelanggan.',
                ],

                'field' => [
                    'code'                 => 'Kod',
                    'name'                 => 'Nama',
                    'reg_no'               => 'No. pendaftaran',
                    'billing_name'         => 'Nama bil',
                    'contact_name'         => 'Nama orang hubungan',
                    'contact_phone'        => 'Telefon orang hubungan',
                    'contact_email'        => 'Email orang hubungan',
                    'default_receipt_name' => 'Nama resit default',
                    'default_receipt_nric' => 'NRIC resit default',
                    'address1'             => 'Alamat baris 1',
                    'address2'             => 'Alamat baris 2',
                    'address3'             => 'Alamat baris 3',
                    'postcode'             => 'Poskod',
                    'city'                 => 'Bandar',
                    'state'                => 'Negeri',
                    'country'              => 'Negara',
                    'status_active'        => 'Aktif',
                ],

                'error' => [
                    'code_required' => 'Kod wajib diisi',
                    'name_required' => 'Nama wajib diisi',
                    'code_unique'   => 'Kod sudah wujud',
                ],
            ],
        ],

        // =========================
        // TXN ALLOCATE 文案
        // =========================
        'txn_allocate' => [
            'page_title'               => 'Agih transaksi IN',
            'eyebrow_allocation'       => 'Agihan',
            'heading_allocate_in_from' => 'Agih IN daripada',
            'subtitle_allocation'      => 'Pelanggan sumber akan ada satu IN dan satu OUT (contra); pelanggan sasaran akan ada satu IN.',
            'label_txn_no'             => 'Txn',

            'section_source_txn_title' => 'Transaksi sumber',
            'section_source_txn_desc'  => 'IN asal daripada pelanggan ini.',

            'label_customer'   => 'Pelanggan:',
            'label_date'       => 'Tarikh:',
            'label_title'      => 'Tajuk:',
            'label_amount'     => 'Amaun:',
            'label_allocated'  => 'Telah diagih:',
            'label_remaining'  => 'Baki:',
            'label_attachment' => 'Lampiran:',
            'link_view_file'   => 'Lihat fail',

            'section_allocate_to_title' => 'Agih kepada',
            'section_allocate_to_desc'  => 'Pilih pelanggan sasaran. Kami akan berhutang kepada sasaran selepas agihan.',

            'field_target_customer'   => 'Pelanggan sasaran',
            'option_select_customer'  => 'Pilih pelanggan...',
            'field_alloc_amount'      => 'Jumlah agihan',
            'label_max'               => 'Maks:',

            'btn_allocate' => 'Agih',

            'note_allocated_from' => 'Diagih dari',
            'note_txn_hash'       => 'txn #',
            'note_allocated_to'   => 'Diagih ke',
            'note_allocation_to'  => 'Agihan ke',
            'note_from_in_txn'    => 'dari IN txn #',

            'error_target_customer_required' => 'Sila pilih pelanggan sasaran.',
            'error_amount_gt_zero'           => 'Jumlah agihan mesti lebih daripada 0.',
            'error_amount_exceeds_remaining' => 'Jumlah agihan tidak boleh melebihi baki.',
            'error_target_not_found'         => 'Pelanggan sasaran tidak dijumpai.',
            'error_allocation_failed'        => 'Agihan gagal',
            'error_target_same_as_source'    => 'Pelanggan sasaran tidak boleh sama dengan pelanggan sumber.',
        ],

        'txn_allocate_fifo' => [
            'page_title' => 'Agihan FIFO',
            'eyebrow'    => 'Agih baki IN (FIFO)',
            'subtitle'   => 'Gunakan baki transaksi IN pelanggan ini dan agihkan kepada pelanggan lain ikut FIFO (IN paling lama digunakan dahulu). Mata wang yang sama akan diagih bersama.',

            'badge_id' => 'ID:',

            'section_source_title' => 'Transaksi IN sumber (pool FIFO)',
            'section_source_desc'  => 'Transaksi IN ini masih ada baki dan akan digunakan mengikut FIFO. Mata wang yang sama akan diagih bersama.',
            'no_source'            => 'Tiada transaksi IN yang mempunyai baki. Tiada apa untuk diagihkan.',

            'col' => [
                'txn'       => 'Txn #',
                'date'      => 'Tarikh',
                'title'     => 'Tajuk',
                'amount'    => 'Amaun',
                'allocated' => 'Diagih',
                'remaining' => 'Baki',
            ],

            'total_available_in' => 'Jumlah IN tersedia',
            'title_fallback'     => 'Txn #',

            'section_allocate_to_title' => 'Agih kepada pelanggan lain',
            'section_allocate_to_desc'  => 'Pilih pelanggan sasaran, mata wang dan amaun untuk diagih guna FIFO. Hanya IN bagi mata wang dipilih akan digunakan.',

            'field_target_customer'  => 'Pelanggan sasaran',
            'option_select_customer' => '— Pilih pelanggan —',

            'field_currency'         => 'Mata wang',
            'option_select_currency' => '— Pilih mata wang —',
            'available'              => 'tersedia',

            'field_alloc_amount' => 'Amaun untuk diagih',
            'max_available'      => 'Maks tersedia:',

            'btn_allocate' => 'Agih (FIFO)',

            'note_fifo_alloc_from' => 'Agihan FIFO dari',
            'note_fifo_alloc_to'   => 'Agihan FIFO ke',
            'note_total'           => 'jumlah',
            'note_from_in_txn'     => 'dari IN txn',

            'error_target_same_as_source'     => 'Pelanggan sasaran tidak boleh sama dengan pelanggan sumber.',
            'error_target_customer_required'  => 'Pelanggan sasaran wajib diisi.',
            'error_currency_required'         => 'Mata wang wajib dipilih.',
            'error_currency_no_balance'       => 'Mata wang dipilih tiada baki.',
            'error_amount_gt_zero'            => 'Amaun mesti lebih daripada 0.',
            'error_amount_exceeds_available'  => 'Amaun melebihi baki tersedia.',
            'error_target_not_found'          => 'Pelanggan sasaran tidak dijumpai.',
            'error_allocation_failed'         => 'Agihan FIFO gagal',
        ],

        // =========================
        // CUSTOMER TXN
        // =========================
        'customer_txn' => [

            'page_title' => [
                'new'  => 'Transaksi Baru',
                'edit' => 'Edit Transaksi',
            ],

            'header' => [
                'eyebrow_new'   => 'Transaksi baru',
                'eyebrow_edit'  => 'Edit transaksi',
                'subtitle_new'  => 'Rekod pergerakan IN / OUT untuk pelanggan ini.',
                'subtitle_edit' => 'Kemaskini butiran transaksi ini.',
            ],

            'back_to_list' => 'Kembali ke transaksi',

            'basic' => [
                'title' => 'Butiran asas',
                'desc'  => 'Tarikh, jenis (IN / OUT), kaedah bayaran dan amaun.',
            ],

            'out_kind' => [
                'label'  => 'Jenis OUT',
                'normal' => 'OUT biasa',
                'loan'   => 'Pinjaman / Pendahuluan kepada pelanggan',
                'help'   => 'NORMAL = bayaran keluar; LOAN = pinjaman / pendahuluan',
            ],
            'status_help' => [
                'customer_autosent' => 'Jika "Pelanggan lain" dipilih, simpan akan auto jadi SENT (pending).',
            ],
            'pay_source' => [
                'label' => 'Sumber bayaran',
                'bank'  => 'Bank / Tunai',
                'customer' => 'Pelanggan lain (bayar bagi pihak)',
                'help' => 'Pilih "Pelanggan lain" = sistem akan cipta IN repayment baru untuk pelanggan tersebut (B) dan kurangkan return balance B.',
                'paying_customer' => 'Pelanggan pembayar',
                'paying_customer_ph' => '— Pilih pelanggan —',
            ],
            'fx' => [
                'amount_in_base_label' => 'Amaun dalam :base (untuk rujukan)',
            ],

            'field' => [
                'date'     => 'Tarikh',
                'type'     => 'Jenis',
                'method'   => 'Kaedah',
                'currency' => 'Mata wang',
                'amount'   => 'Amaun',
                'status'   => 'Status',
                'ref_no'   => 'No. rujukan',
                'title'    => 'Tajuk',
                'notes'    => 'Nota',

                'bank_account'     => 'Akaun bank / tunai',
                'bank_placeholder' => '— Pilih bank / tunai —',
            ],

            'type' => [
                'in'  => 'IN (wang masuk / diagih)',
                'out' => 'OUT (bayaran kepada pelanggan)',
            ],

            'method' => [
                'cash'  => 'Tunai',
                'bank'  => 'Bank',
                'usdt'  => 'USDT',
                'other' => 'Lain-lain',
            ],

            'status' => [
                'auto_confirm' => 'CONFIRMED (auto untuk IN)',
                'draft'        => 'DRAFT',
                'sent'         => 'SENT',
                'confirmed'    => 'CONFIRMED',
            ],

            'error' => [
                'date_required'   => 'Tarikh wajib diisi',
                'type_invalid'    => 'Jenis tidak sah',
                'method_invalid'  => 'Kaedah tidak sah',
                'amount_gt_zero'  => 'Amaun mesti lebih besar daripada 0',
                'paying_customer_required' => 'Pelanggan pembayar diperlukan.',
                'paying_customer_same'     => 'Pelanggan pembayar tidak boleh sama dengan pihak lawan.',
                'fx_rate_required'         => 'Kadar FX diperlukan apabila mata wang bukan :base.',
            ],

            'payer' => [
                'title' => 'Pembayar (pihak kami)',
                'desc'  => 'Syarikat mana yang membayar, dan siapa tandatangan pihak kami. Staf sama boleh digunakan untuk mana-mana syarikat.',

                'company'             => 'Syarikat pembayar',
                'company_placeholder' => '— Pilih syarikat —',
                'staff'               => 'Staf pembayar / penandatangan',
                'staff_placeholder'   => '— Pilih staf —',
            ],

            'parties' => [
                'title'        => 'Pihak terlibat',
                'desc'         => 'Counterparty ditetapkan ikut pelanggan; penerima (penandatangan) hanya untuk OUT.',
                'counterparty' => 'Counterparty (tetap)',
            ],

            'recipient' => [
                'name'        => 'Nama penerima (yang tandatangan)',
                'placeholder' => 'Taip atau pilih daripada pengguna login...',
                'nric'        => 'NRIC penerima',
                'tip'         => 'Jika pilih dari senarai login, NRIC auto isi. Anda juga boleh isi manual.',
            ],

            'desc' => [
                'title' => 'Penerangan & lampiran',
                'desc'  => 'Rujukan, tajuk, nota, dan lampiran PDF / imej.',
            ],

            'title' => [
                'placeholder_in'  => 'Default: nama pelanggan',
                'placeholder_out' => 'Default: Resit',
            ],

            'attach' => [
                'all'      => 'Lampiran (PDF / imej)',
                'helper'   => 'Anda boleh muat naik satu atau lebih fail sokongan di sini.',
                'existing' => 'Fail sedia ada',
                'delete'   => 'Padam',

                'multi_error'      => 'Fail "%s" ralat upload (kod %d).',
                'multi_invalid'    => 'Fail "%s" dilangkau (jenis tidak sah).',
                'multi_move_fail'  => 'Fail "%s" gagal dipindahkan.',
                'multi_db_fail'    => 'Sebahagian lampiran gagal disimpan ke DB.',
            ],

            'sign' => [
                'title'       => 'Keperluan tandatangan',
                'desc'        => 'Hanya terpakai untuk OUT yang bukan contra. IN akan abaikan.',
                'require'     => 'Perlu tandatangan pelanggan pada resit',
                'contra_note' => 'OUT ini adalah contra (dijana oleh agihan). Tandatangan tidak diperlukan.',
                'in_note'     => 'Tandatangan auto dimatikan untuk transaksi IN.',
            ],

            'badge' => [
                'contra' => 'Contra',
                'loan'             => 'Pinjaman / Pendahuluan',
                'paid_by_customer' => 'Dibayar oleh pelanggan (B)',
            ],

            'select' => [
                'eyebrow'       => 'Transaksi baharu',
                'subtitle'      => 'Pilih sama ada IN, OUT atau agih baki IN (FIFO).',
                'section_title' => 'Pilih tindakan',
                'section_desc'  => 'IN = terimaan. OUT = bayaran. Allocate = guna baki IN (FIFO) untuk contra dengan pelanggan lain.',

                'in_title' => 'IN (wang masuk)',
                'in_desc'  => 'Guna bila pelanggan bayar masuk.',
                'in_btn'   => '+ Cipta transaksi IN',

                'out_title' => 'OUT (bayaran keluar)',
                'out_desc'  => 'Guna bila anda bayar kepada pelanggan.',
                'out_btn'   => '+ Cipta transaksi OUT',

                'alloc_title' => 'Agih (FIFO)',
                'alloc_desc'  => 'Agih baki IN pelanggan ini kepada pelanggan lain guna FIFO.',
                'alloc_btn'   => '→ Agih guna FIFO',
            ],

            'out' => [
                'subtitle' => 'Rekod / kemaskini bayaran kepada pelanggan (refund, withdrawal, settlement, dll).',
                'save_btn' => 'Simpan OUT',
            ],

            'bank' => [
                'load_error' => 'Ralat muat akaun bank: %s',
                'none'       => 'Tiada akaun bank dalam company_bank_accounts.',
                'helper_out' => 'Pilih akaun bank / tunai untuk bayaran ini.',
            ],

            'fx' => [
                'label'             => 'Kadar FX ke :base (1 {CUR} = ? :base)',
                'example'           => 'Contoh: 1 USD = 4.700000 :base → masukkan 4.700000',
                'base_amount_label' => 'Amaun dalam :base (untuk info)',
            ],
            'view' => [
                'page_title'   => 'Resit / Pengesahan',
                'eyebrow_in'   => 'Transaksi IN',
                'eyebrow_out'  => 'Pratonton Resit',
                'txn_label'    => 'Transaksi',
                'print_btn'    => 'Cetak / PDF',
                'sign_saved'   => 'Tandatangan telah disimpan。',

                'invoice_info' => "Halaman ini digunakan untuk pratonton resit OUT / RETURN / BONUS.\nUntuk jenis INVOICE, sila gunakan halaman cetakan invois asal anda.",
                'no_items'     => 'Tiada item resit untuk dipaparkan。',

                'in' => [
                    'title'    => 'Butiran Transaksi IN',
                    'desc'     => 'IN tidak memerlukan resit; lampiran digunakan sebagai dokumen sokongan。',
                    'no_notes' => 'Tiada nota',
                ],

                'attach' => [
                    'tip'   => 'Klik fail untuk lihat atau muat turun。',
                    'none'  => 'Tiada lampiran',
                    'title' => 'Lampiran：',
                ],

                'receipt_title'   => 'Resit',
                'received_from'   => 'Diterima daripada (Pembayar)：',
                'rep'             => 'Wakil：',
                'nric'            => 'NRIC：',
                'received_by'     => 'Diterima oleh / Bagi pihak：',
                'address'         => 'Alamat：',
                'recipient'       => 'Penerima (Penandatangan)：',
                'recipient_fill'  => '(Sila isi nama penandatangan pelanggan)',
                'receipt_confirm' => 'Resit ini mengesahkan jumlah di atas telah diterima。',

                'method_bank'  => 'Pindahan bank',
                'method_other' => 'Lain-lain',

                // ✅ OUT：customer=receiver, payer=our company
                'sig_customer_title_out' => 'Tandatangan (Penerima / Pelanggan)',
                'sig_payer_title_out'    => 'Tandatangan (Pembayar / Syarikat kami)',

                // ✅ IN：receiver=our company, payer=customer
                'sig_customer_title_in'  => 'Tandatangan (Penerima / Syarikat kami)',
                'sig_payer_title_in'     => 'Tandatangan (Pembayar / Pelanggan)',

                'sig_customer_none'  => 'Belum ada tandatangan pelanggan。',
                'sig_payer_none'     => 'Belum ada tandatangan pembayar。',

                'name_nric' => 'Nama / NRIC：',
                'sign_date' => 'Tarikh：',

                // 旧 key（兼容）
                'name_label' => 'Nama：',
                'date_label' => 'Tarikh：',

                'sign_here_title' => 'Tandatangan di sini',
                'sign_here_desc'  => 'Mana-mana pihak boleh tandatangan dahulu. Status hanya akan menjadi CONFIRMED selepas pelanggan tandatangan。',

                'canvas_customer_tip' => 'Pelanggan – sila tandatangan dalam kotak',
                'canvas_payer_tip'    => 'Syarikat kami – sila tandatangan dalam kotak',
                'clear_btn'           => 'Padam',
                'save_signatures'     => 'Simpan tandatangan',
                'sign_done'           => 'Tandatangan telah direkod / tidak perlu tandatangan lagi。',

                'error' => [
                    'sign_required' => 'Sila lengkapkan sekurang-kurangnya satu tandatangan sebelum simpan。',
                ],
            ],
            'list' => [
                'title'   => 'Transaksi Pelanggan',
                'eyebrow' => 'Pelanggan',
                'subtitle' => 'Lihat invois / bayaran keluar, bayaran pelanggan dan peruntukan contra.',
                'new_btn' => '+ Transaksi Baharu',
                'back_to_customers' => 'Kembali ke pelanggan',
                'user_detail_btn'   => 'Butiran pengguna',

                'save_ok'   => 'Transaksi berjaya disimpan.',
                'delete_ok' => 'Transaksi berjaya dipadam.',
                'alloc_ok'  => 'Peruntukan selesai (sumber dikemas kini & rekod contra dicipta).',

                'empty' => 'Tiada transaksi untuk penapis ini. Cuba julat tarikh lain.',

                'filter' => [
                    'type'        => 'Jenis',
                    'search'      => 'Carian',
                    'contra_view' => 'Paparan contra',
                ],

                'summary' => [
                    'eyebrow'        => 'Ringkasan',
                    'after'          => 'Selepas contra',
                    'before'         => 'Tanpa contra (tidak digunakan)',
                    'total_in'       => 'Jumlah IN',
                    'total_out'      => 'Jumlah OUT',
                    'net_normal'     => 'Bersih',
                    'pending'        => 'Bayaran tertunggak',
                    'return_balance' => 'Return',
                    'total_bonus'    => 'Jumlah BONUS',
                    'summary_in'     => 'Ringkasan jumlah IN',
                    'summary_out'    => 'Ringkasan jumlah OUT',
                    'summary_net'    => 'Ringkasan bersih',
                ],

                'return_still_owing' => 'Pelanggan masih memegang modal kami (tertunggak)',
                'return_profit'      => 'Modal telah dipulangkan sepenuhnya',
                'return_balanced'    => 'Modal telah dipulangkan sepenuhnya',

                'paid_label'        => 'Dibayar oleh pelanggan:',
                'alloc_avail_label' => 'Baki untuk allocate (dibayar, MYR):',

                'payer_label' => 'Pembayar:',
                'staff_label' => 'Staf:',

                'pending' => 'Tertunggak',

                'action_view'       => 'Lihat',
                'action_receipt_in' => 'Resit IN',
                'action_allocate'   => 'Allocate',

                'contra_summary_title'   => 'Agihan transaksi (jumlah contra)',
                'contra_summary_company' => 'Contra ke syarikat:',
                'contra_summary_desc'    => 'Jumlah keseluruhan yang di-allocate (contra) untuk tarikh ini.',
            ],

            'type' => [
                'in'             => 'IN',
                'out'            => 'OUT',
                'contra_summary' => 'CONTRA',
            ],
        ],

        // =========================
        // BANK (aligned with admin/bank/*.php)
        // =========================
        'banks' => [
            'list' => [
                'title'    => 'Bank Syarikat',
                'eyebrow'  => 'Kewangan',
                'subtitle' => 'Urus akaun bank syarikat untuk transaksi IN / OUT.',
                'new_btn'  => '+ Bank Baru',
                'empty'    => 'Tiada akaun bank.',

                'filter' => [
                    'q'    => 'Cari',
                    'q_ph' => 'Bank / nama akaun / nombor',
                ],

                'action_txn'        => 'Transaksi',
                'action_statements' => 'Penyata',
            ],

            'field' => [
                'bank_name'    => 'Nama bank',
                'account_name' => 'Nama akaun',
                'account_no'   => 'No. akaun',
                'currency'     => 'Mata wang',
            ],
        ],

        'bank' => [

            'cash' => [
                'title'        => 'Akaun tunai',
                'account_name' => 'Tunai',
            ],

            'txn' => [
                'page_title' => 'Transaksi bank',
                'eyebrow'    => 'Bank',

                'btn_statement' => 'Penyata bank',
                'new_btn'       => '+ Transaksi baru',

                'type_in'  => 'IN',
                'type_out' => 'OUT',

                'err_missing_bank'   => 'bank_id tiada',
                'err_bank_not_found' => 'Akaun bank tidak dijumpai',

                'summary' => [
                    'eyebrow'        => 'Ringkasan',
                    'title'          => 'Gambaran baki',
                    'opening_simple' => 'Baki pembukaan',
                    'in'             => 'IN tempoh ini',
                    'out'            => 'OUT tempoh ini',
                    'net'            => 'Pergerakan bersih',
                    'current_simple' => 'Baki semasa',
                ],

                'filter' => [
                    'type'     => 'Jenis',
                    'view_cur' => 'Paparan mata wang',
                    'q'        => 'Cari kata kunci',
                    'q_ph'     => 'No. rujukan / Penerangan',
                ],

                'view_cur_account' => '{cur} (akaun)',
                'view_cur_myr'     => 'MYR (ditukar)',

                'row' => [
                    'opening' => 'Baki pembukaan sebelum tempoh',
                ],

                'col' => [
                    'date'    => 'Tarikh',
                    'type'    => 'Jenis',
                    'ref'     => 'Ruj',
                    'desc'    => 'Penerangan',
                    'cur'     => 'Mata wang',
                    'amount'  => 'Amaun',
                    'myr'     => 'MYR',
                    'balance' => 'Baki ({cur})',
                ],

                'empty' => 'Tiada transaksi untuk tapisan ini.',
            ],

            'txn_edit' => [
                'eyebrow'   => 'TRANSAKSI BANK',
                'subtitle'  => 'Rekod pergerakan bank / USDT untuk akaun ini.',

                'title_new' => 'Transaksi bank baru',
                'title_edit' => 'Edit transaksi bank',

                'saved'     => 'Transaksi disimpan.',
                'save_btn'  => 'Simpan transaksi',

                'pick' => [
                    'title'       => 'Pilih akaun bank',
                    'desc'        => 'Sila pilih bank / wallet untuk mencipta transaksi.',
                    'field'       => 'Akaun bank',
                    'ph'          => '— Sila pilih —',
                    'id_fallback' => 'ID {id}',
                ],

                'section' => [
                    'main' => 'Butiran transaksi',
                ],

                'field' => [
                    'date'        => 'Tarikh',
                    'type'        => 'Jenis',
                    'description' => 'Penerangan',
                    'ref_no'      => 'No. rujukan',
                    'amount'      => 'Amaun',
                    'currency'    => 'Mata wang',
                    'rate_to_myr' => 'Kadar → MYR',
                ],

                'help' => [
                    'rate' => 'Untuk USDT, masukkan 1 USDT = ? MYR.',
                ],

                'type_allocate' => 'Agih ke bank lain',

                'allocate' => [
                    'target_label'   => 'Pindah ke akaun bank',
                    'target_ph'      => '— Pilih bank sasaran —',
                    'tip'            => 'Akaun mata wang sama sahaja. Sistem auto-cipta OUT (bank ini) dan IN (bank sasaran).',
                    'currency_fixed' => 'Mata wang dikunci kepada akaun ini untuk agihan.',
                ],

                'allocate_from' => 'Agih dari {name}',

                'attach' => [
                    'title'     => 'Lampiran',
                    'desc'      => 'Muat naik fail PDF / imej sebagai dokumen sokongan.',
                    'upload'    => 'Muat naik fail',
                    'existing'  => 'Fail sedia ada',
                    'tip_types' => 'PDF, PNG, JPG, GIF',

                    'err_upload' => 'Fail "{name}" ralat upload (kod {code}).',
                    'err_type'   => 'Fail "{name}" dilangkau (jenis tidak sah: {type}).',
                    'err_move'   => 'Fail "{name}" gagal dipindahkan.',
                ],

                'err_bank_not_found' => 'Akaun bank tidak dijumpai',
                'err_txn_not_found'  => 'Transaksi bank tidak dijumpai',

                'error' => [
                    'date_required'          => 'Tarikh wajib diisi.',
                    'amount_required'        => 'Amaun tidak boleh kosong.',
                    'rate_required'          => 'Kadar ke MYR wajib diisi untuk mata wang bukan MYR.',
                    'save_failed'            => 'Gagal simpan',

                    'target_required'        => 'Sila pilih bank sasaran.',
                    'target_same'            => 'Tidak boleh agih ke bank yang sama.',
                    'target_invalid'         => 'Bank sasaran tidak sah.',
                    'allocate_same_currency' => 'Agihan hanya menyokong akaun mata wang sama buat masa ini.',
                ],
            ],

            'stmt' => [
                'page_title' => 'Penyata bank',
                'eyebrow'    => 'PENYATA BANK',

                'opening' => 'Baki pembukaan',
                'current' => 'Baki semasa',

                'upload_title' => 'Muat naik penyata',
                'upload_btn'   => 'Muat naik',
                'upload_ok'    => 'Penyata dimuat naik.',

                'month'  => 'Bulan',
                'label'  => 'Label',
                'remark' => 'Catatan',
                'file'   => 'Fail penyata',

                'label_ph'  => 'cth: Julai 2025',
                'remark_ph' => 'cth: Maybank e-statement',

                'label_tip' => 'Jika kosong, sistem auto-isi seperti "Julai 2025".',
                'file_tip'  => 'PDF, PNG, JPG, GIF',

                'search'    => 'Cari',
                'search_ph' => 'Cari label / catatan / fail',

                'col' => [
                    'month'       => 'Bulan',
                    'label'       => 'Label',
                    'remark'      => 'Catatan',
                    'file'        => 'Fail',
                    'size'        => 'Saiz',
                    'uploaded_at' => 'Tarikh muat naik',
                ],

                'empty' => 'Belum ada penyata.',
                'confirm_delete' => 'Padam penyata ini?',

                'err' => [
                    'month'         => 'Sila pilih bulan yang sah.',
                    'file_required' => 'Sila pilih fail penyata.',
                    'file_type'     => 'Jenis fail tidak sah.',
                    'upload'        => 'Ralat muat naik (kod {code}).',
                    'general'       => 'Muat naik gagal: ',
                ],
            ],

            'all_txn' => [
                'page_title' => 'Semua transaksi bank',
                'eyebrow'    => 'RINGKASAN BANK',
                'subtitle'   => 'Paparan gabungan semua akaun bank / wallet dalam MYR.',

                'summary' => 'Ringkasan',

                'opening'    => 'Baki pembukaan',
                'current'    => 'Baki semasa',
                'total_in'   => 'Jumlah IN',
                'total_out'  => 'Jumlah OUT',
                'net'        => 'Bersih',

                'filter' => [
                    'type'      => 'Jenis',
                    'search'    => 'Cari',
                    'search_ph' => 'Penerangan / Ruj / Bank / No akaun',
                    'accounts'  => 'Akaun',
                ],

                'no_accounts' => 'Tiada akaun bank aktif.',
                'empty'       => 'Tiada transaksi untuk tapisan ini.',

                'ref'         => 'Ruj',
                'attachments' => 'Lampiran',

                'col' => [
                    'date'    => 'Tarikh',
                    'type'    => 'Jenis',
                    'account' => 'Akaun',
                    'desc'    => 'Penerangan',
                    'amount'  => 'Amaun',
                    'rate'    => 'Kadar → MYR',
                    'myr'     => 'MYR',
                ],
            ],
        ],

        // =========================
        // TXN IN
        // =========================
        'txn_in' => [
            'page_title' => 'Transaksi IN',
            'eyebrow'    => 'Transaksi IN',
            'subtitle'   => 'Cipta / edit satu order IN dengan beberapa pembayaran.',
            'back'       => '← Kembali ke transaksi',
            'saved'      => 'Transaksi IN disimpan.',

            'basic' => [
                'title' => 'Butiran asas',
                'desc'  => 'Tarikh, jenis, amaun dan mata wang utama.',
            ],

            'field' => [
                'date'       => 'Tarikh',
                'in_type'    => 'Jenis IN',
                'invoice_no' => 'No Invois',
                'amount'     => 'Amaun',
            ],

            'in_kind' => [
                'invoice' => 'Invois / IN biasa',
                'return'  => 'Bayaran balik / pulang modal',
                'bonus'   => 'Bonus / agihan keuntungan',
                'help'    => 'INVOICE = invois; RETURN = modal; BONUS = lain-lain',
            ],

            'invoice_ph' => 'Kosongkan untuk auto VMYYMM-XXX',
            'invoice_disabled_help' => 'RETURN / BONUS tidak guna no. invois.',
            'currency_ph' => 'MYR / USD / SGD ...',
            'amount_help' => 'Amaun & mata wang akan jadi mata wang utama untuk IN ini. Paid / Pending dikira dalam mata wang yang sama.',

            'paid_so_far' => 'Dibayar setakat ini',
            'pending'     => 'Baki belum bayar',

            'payments' => [
                'title'    => 'Pembayaran untuk order ini',
                'desc'     => 'Tambah satu atau lebih baris pembayaran. Setiap baris akan cipta transaksi bank (rujukan MYR hanya dalam jadual bank).',
                'add_line' => '+ Tambah baris',
            ],

            'col' => [
                'or_no'     => 'No Resit Rasmi',
                'pay_date'  => 'Tarikh',
                'amount'    => 'Amaun',
                'bank'      => 'Bank',
                'currency'  => 'Mata wang',
                'fx_rate'   => 'FX → MYR',
                'attach'    => 'Lampiran pembayaran',
                'receipt'   => 'Resit / Lihat',
                'invoice'   => 'Invois',
                'action'    => 'Tindakan',
            ],

            'select'  => '— Pilih —',
            'or_ph'   => 'Auto jika kosong',
            'fx_ph'   => 'cth: 4.700000',
            'after_save' => 'Selepas simpan',

            'attach' => [
                'add'          => '+ Tambah',
                'saved'        => 'Disimpan:',
                'delete'       => 'Padam',
                'default_name' => 'Lampiran %d',
            ],

            'notes_sign' => [
                'title' => 'Nota & Tandatangan',
                'desc'  => 'Nota dalaman, lampiran dan tandatangan.',
            ],

            'notes' => 'Nota',

            'txn_attach' => [
                'label' => 'Lampiran (untuk IN ini)',
                'help'  => 'Lampirkan invois, DO, kontrak, dll. (boleh banyak fail).',
            ],

            'our' => [
                'title'        => 'Pihak kami (penerima)',
                'company'      => 'Syarikat kami',
                'company_ph'   => '— Pilih syarikat —',
                'staff'        => 'Staf / penandatangan kami',
                'staff_ph'     => '— Pilih staf —',
                'staff_help'   => 'Pilih siapa menerima / tandatangan pihak kami. Digunakan bersama “Sign receive”.',
            ],

            'recipient' => [
                'name'     => 'Nama penerima (yang tandatangan)',
                'name_ph'  => 'Taip atau pilih dari pengguna login...',
                'nric'     => 'NRIC / pasport penerima',
                'tip'      => 'Bila pilih pengguna login, NRIC auto isi. Anda juga boleh isi manual.',
            ],

            'sign' => [
                'sign_receive'      => 'Sign receive (tandatangan pihak kami pada resit)',
                'sign_receive_help' => 'Tanda = papar “Received by” + cop syarikat. Tidak tanda = hanya cop syarikat.',
                'need_customer'     => 'Perlu tandatangan pelanggan',
                'need_customer_help' => 'Tanda = pelanggan mesti tandatangan. Tidak tanda = tidak perlu tandatangan & status tidak dikunci.',
            ],

            'btn' => [
                'cancel' => 'Batal',
                'save'   => 'Simpan',
            ],

            'view_invoice' => 'Lihat invois',
            'view_receipt' => 'Lihat resit',

            'err' => [
                'not_found'          => 'Transaksi IN tidak dijumpai',
                'customer_mismatch'  => 'Pelanggan tidak sepadan',
                'missing_customer'   => 'customer_id tiada',
                'customer_not_found' => 'Pelanggan tidak dijumpai',
                'date_required'      => 'Tarikh wajib diisi',
                'amount_gt_zero'     => 'Amaun mesti lebih daripada 0',
                'save_failed'        => 'Ralat simpan: %s',
            ],
        ],

        // =========================
        // CUSTOMER LOGIN USERS (portal account)
        // =========================
        'customer_user' => [

            'edit' => [
                'page_title'           => 'Pengguna login pelanggan',
                'eyebrow'              => 'Pengguna login',
                'title_edit'           => 'Edit pengguna login',
                'title_new'            => 'Pengguna login baru',
                'subtitle'             => 'Urus login portal untuk pelanggan ini.',
                'saved'                => 'Pengguna disimpan.',
                'section_account'      => 'Akaun',
                'section_account_desc' => 'Username dan maklumat hubungan asas untuk login ini.',
            ],

            'field' => [
                'username'      => 'Username',
                'full_name'     => 'Nama penuh',
                'nric'          => 'NRIC / IC',
                'email'         => 'Email',
                'phone'         => 'Telefon',
                'password_new'  => 'Password *',
                'password_edit' => 'Password (kosong = tiada perubahan)',
                'active'        => 'Aktif',
            ],

            'error' => [
                'username_required'      => 'Username wajib diisi',
                'full_name_required'     => 'Nama wajib diisi',
                'username_exists'        => 'Username sudah wujud',
                'username_exists_global' => 'Username sudah wujud',
                'password_required_new'  => 'Password wajib untuk pengguna baru',
                'password_required'      => 'Password wajib diisi',
            ],

            'list' => [
                'page_title'     => 'Login Pelanggan: ',
                'eyebrow'        => 'Login pelanggan',
                'saved'          => 'Pengguna disimpan.',
                'existing_title' => 'Login sedia ada',
                'no_users'       => 'Belum ada pengguna login.',
                'btn_edit'       => 'Edit / Reset',
                'add_title'      => 'Tambah login baru',
                'btn_create'     => 'Cipta login',
            ],

            'col' => [
                'id'       => 'ID',
                'username' => 'Username',
                'name'     => 'Nama',
                'nric'     => 'NRIC / IC',
                'email'    => 'Email',
                'phone'    => 'Telefon',
                'active'   => 'Aktif',
                'action'   => 'Tindakan',
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
            'app_title'     => 'Portal Pelanggan',
            'logout'        => 'Log keluar',
            'signed_in_as'  => 'Log masuk sebagai',
        ],

        'footer' => [
            'powered_by' => 'Dikuasakan oleh Vision Mix',
        ],

        'sidebar' => [
            'section_main'     => 'Utama',
            'section_settings' => 'Tetapan',
            'dashboard'        => 'Dashboard',
            'txns'             => 'Transaksi / Laporan',
            'users'            => 'Pengguna login',
        ],

        'status' => [
            'confirmed' => 'CONFIRMED',
            'sent'      => 'SENT',
            'draft'     => 'DRAFT',
        ],

        'dashboard' => [
            'pending_eyebrow'   => 'Tertunda',
            'pending_title'     => 'Tandatangan tertunda',
            'pending_subtitle'  => 'Bayaran ini menunggu tandatangan anda.',
            'pending_view_all'  => 'Lihat semua tertunda',
            'pending_sign_btn'  => 'Tandatangan',
            'pending_type_receipt' => 'Resit (kami bayar anda)',
            'pending_type_invoice' => 'Invois (anda bayar kami)',

            'table' => [
                'date'    => 'Tarikh',
                'title'   => 'Tajuk',
                'amount'  => 'Amaun',
                'status'  => 'Status',
                'actions' => 'Tindakan',
                'type' => 'Jenis',
            ],

            'row1' => [
                'total_out' => 'Jumlah OUT (anda bayar kami)',
                'total_in'  => 'Jumlah IN (kami bayar anda)',
                'balance'   => 'Baki (OUT - IN)',
            ],

            'row2' => [
                'repayment' => 'Bayaran balik (Repayment)',
                'bonus'     => 'Bonus',
            ],

            'row3' => [
                'summary_out' => 'Jumlah ringkasan OUT',
                'summary_in'  => 'Jumlah ringkasan IN',
                'summary_net' => 'Baki ringkasan (OUT - IN)',
            ],

            'customer_eyebrow'  => 'Pelanggan',
            'customer_subtitle' => 'Ringkasan transaksi dan baki anda.',

            'summary_eyebrow'   => 'Ringkasan',
            'summary_title'     => 'Selepas contra',
            'summary_out_label' => 'Jumlah OUT (anda bayar kami)',
            'summary_in_label'  => 'Jumlah IN (kami bayar anda)',
            'summary_bal_label' => 'Baki (OUT - IN)',
            'summary_we_owe'    => 'Kami berhutang kepada anda',
            'summary_you_owe'   => 'Anda berhutang kepada kami',
            'summary_balanced'  => 'Seimbang',

            'recent_eyebrow'     => 'Terkini',
            'recent_title'       => '10 transaksi terakhir',
            'recent_full_report' => 'Laporan penuh',
            'recent_in_title'    => 'IN — Kami bayar anda',
            'recent_out_title'   => 'OUT — Anda bayar kami',
            'recent_no_payout'   => 'Tiada rekod bayaran.',
            'recent_no_payment'  => 'Tiada rekod pembayaran.',
        ],
    ],

    // ===== Portal-side status shortcuts =====
    'status' => [
        'confirmed' => 'CONFIRMED',
        'sent'      => 'SENT',
        'draft'     => 'DRAFT',
        'pending'   => 'PENDING',
    ],

    // ===== Portal: Users =====
    'users' => [
        'list' => [
            'page_title'     => 'Pengguna login',
            'eyebrow'        => 'Pengguna login',
            'subtitle'       => 'Urus login portal untuk syarikat anda.',
            'created'        => 'Pengguna dicipta / dikemaskini.',
            'existing_title' => 'Login sedia ada',
            'no_users'       => 'Belum ada pengguna login.',
            'add_title'      => 'Tambah login baru',
            'btn_edit'       => 'Edit / Reset',
            'btn_create'     => 'Cipta login',
            'col' => [
                'id'       => 'ID',
                'username' => 'Username',
                'name'     => 'Nama',
                'email'    => 'Email',
                'phone'    => 'Telefon',
                'active'   => 'Aktif',
                'action'   => 'Tindakan',
            ],
            'ref_prefix' => 'Ruj:',
        ],

        'edit' => [
            'page_title'            => 'Edit pengguna login',
            'eyebrow'               => 'Pengguna login',
            'title'                 => 'Edit pengguna login',
            'subtitle'              => 'Kemaskini maklumat login pengguna portal.',
            'saved'                 => 'Pengguna disimpan.',
            'section_account_title' => 'Akaun',
            'section_account_desc'  => 'Username dan maklumat hubungan untuk login ini.',
        ],

        'field' => [
            'username'      => 'Username',
            'password'      => 'Password',
            'password_hint' => 'Password (kosong = tiada perubahan)',
            'full_name'     => 'Nama penuh',
            'email'         => 'Email',
            'phone'         => 'Telefon',
            'active'        => 'Aktif',
            'nric'          => 'NRIC',
        ],

        'error' => [
            'username_required'  => 'Username wajib diisi',
            'full_name_required' => 'Nama wajib diisi',
            'password_required'  => 'Password wajib diisi',
            'username_exists'    => 'Username sudah wujud',
            'general_failed'     => 'Gagal simpan. Sila cuba lagi.',
        ],

        'btn' => [
            'back' => 'Kembali',
            'save' => 'Simpan',
        ],

        'col' => [
            'id'       => 'ID',
            'username' => 'Username',
            'name'     => 'Nama',
            'email'    => 'Email',
            'phone'    => 'Telefon',
            'active'   => 'Aktif',
            'action'   => 'Tindakan',
            'nric'     => 'NRIC',
        ],
    ],

    // ===== Portal: Transactions list & view =====
    'txn' => [
        'type' => [
            'in'  => 'IN (kami bayar anda)',
            'out' => 'OUT (anda bayar kami)',
        ],

        'badge' => [
            'contra' => 'Contra',
        ],

        'list' => [
            'page_title'       => 'Transaksi / Laporan',
            'eyebrow_customer' => 'Pelanggan',
            'subtitle'         => 'Transaksi & laporan ringkas. IN = kami bayar anda, OUT = anda bayar kami.',

            'filter' => [
                'type'        => 'Jenis',
                'type_all'    => 'Semua',
                'type_in'     => 'IN (kami bayar anda)',
                'type_out'    => 'OUT (anda bayar kami)',

                'status'      => 'Status',
                'status_all'  => 'Semua',

                'method'       => 'Kaedah',
                'method_all'   => 'Semua',
                'method_cash'  => 'Tunai',
                'method_bank'  => 'Bank',
                'method_usdt'  => 'USDT',
                'method_other' => 'Lain-lain',

                'keyword'    => 'Kata kunci',
                'keyword_ph' => 'Tajuk / No. rujukan...',
            ],

            'col' => [
                'date'    => 'Tarikh',
                'type'    => 'Jenis',
                'method'  => 'Kaedah',
                'title'   => 'Tajuk',
                'amount'  => 'Amaun',
                'status'  => 'Status',
                'action'  => 'Tindakan',
                'balance' => 'Baki',
                'pending' => 'Tertunggak',
            ],

            'btn_view'   => 'Lihat',
            'ref_prefix' => 'Ruj:',
            'empty'      => 'Tiada transaksi untuk tapisan ini.',
        ],

        'view' => [
            'page_title' => 'Butiran transaksi',
            'eyebrow'    => 'Transaksi',

            'type_label_in'  => 'IN — Kami bayar anda',
            'type_label_out' => 'OUT — Anda bayar kami',

            'field' => [
                'type'               => 'Jenis',
                'amount'             => 'Amaun',
                'status'             => 'Status',
                'date'               => 'Tarikh',
                'ref_no'             => 'No. rujukan',
                'method'             => 'Kaedah',
                'title'              => 'Tajuk',
                'notes'              => 'Nota',
                'recipient_name'     => 'Nama penerima',
                'recipient_nric'     => 'NRIC penerima',
                'signature_required' => 'Perlu tandatangan',
            ],

            'section_details_title' => 'Butiran',
            'section_details_desc'  => 'Maklumat asas transaksi ini.',

            'section_recipient_title' => 'Penerima / Tandatangan',
            'section_recipient_desc'  => 'Maklumat siapa menerima wang.',

            'section_attach_title' => 'Lampiran',
            'section_attach_desc'  => 'Buka / muat turun dokumen (PDF / imej).',

            'section_in_title' => 'Butiran transaksi IN',
            'section_in_desc'  => 'Jumlah order, pembayaran dan lampiran.',

            'paid_myr'    => 'Dibayar setakat ini (MYR)',
            'pending_myr' => 'Tertunggak (MYR)',

            'section_payments_title'      => 'Pembayaran',
            'section_notes_attach_title'  => 'Lampiran nota',

            'col' => [
                'or_no'    => 'No Resit Rasmi',
                'pay_date' => 'Tarikh',
                'amount'   => 'Amaun',
                'bank'     => 'Bank',
                'currency' => 'Mata wang',
                'fx_rate'  => 'FX → MYR',
                'attach'   => 'Lampiran pembayaran',
                'invoice'  => 'Invois',
            ],

            'attach_default'    => 'Lampiran',
            'attach_none_short' => 'Tiada lampiran',
            'payments_none'     => 'Belum ada pembayaran.',

            'alert_pending'   => 'Bayaran ini menunggu pengesahan / tandatangan anda. Sila klik “View receipt” di atas untuk semak dan tandatangan bersama staf kami.',
            'alert_confirmed' => 'Bayaran ini telah disahkan dan ditandatangani.',
            'alert_contra'    => 'Entri ini dijana melalui agihan (contra). Tandatangan tidak diperlukan.',

            'sig_status_signed'      => 'Sudah ditandatangani / disahkan',
            'sig_status_pending_you' => 'Menunggu tandatangan anda',
            'sig_status_pending'     => 'Menunggu',

            'attach_tip'  => 'Klik nama fail untuk lihat atau muat turun.',
            'attach_none' => 'Tiada lampiran untuk transaksi ini.',

            'btn_back'    => '← Kembali ke transaksi',
            'btn_receipt' => 'Lihat resit',
            'btn_invoice' => 'Lihat invois',
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
                'back'         => 'Kembali',
                'back_to_txns' => 'Kembali ke transaksi',
                'back_to_list' => 'Kembali ke senarai',
                'cancel'       => 'Batal',
                'clear'        => 'Padam',
            ],
            'error' => [
                'save_failed' => 'Gagal simpan. Sila cuba lagi.',
            ],
        ],

        'txn' => [
            'type_label' => [
                'in'  => 'IN — Kami bayar anda',
                'out' => 'OUT — Anda bayar kami',
            ],

            'badge' => [
                'contra' => 'Contra',
            ],

            'btn' => [
                'print_pdf' => 'Cetak / PDF',
            ],

            'method' => [
                'cash'  => 'Tunai',
                'bank'  => 'Pindahan bank',
                'usdt'  => 'USDT',
                'other' => 'Lain-lain',
            ],

            'receipt' => [
                'title'                 => 'RESIT / PENGESAHAN',
                'page_title'            => 'Resit / Pengesahan',
                'eyebrow'               => 'Resit / Pengesahan',
                'txn_no'                => 'Txn #',

                'date'                  => 'Tarikh',
                'received_from'         => 'Diterima daripada (Pembayar):',
                'received_by'           => 'Diterima oleh / Bagi pihak:',
                'received_by_company'   => '(Syarikat kami)',

                'recipient'             => 'Penerima (yang tandatangan):',
                'recipient_placeholder' => '(sila isi nama penerima di portal)',
                'received_from_payer'   => 'Diterima daripada (Pembayar):',
                'received_from_rep'     => 'Wakil:',
                'nric_label'            => 'NRIC:',
                'received_by_on_behalf' => 'Diterima oleh / Bagi pihak:',
                'address'               => 'Alamat:',

                'amount'                => 'Amaun:',
                'method'                => 'Kaedah bayaran:',
                'ref_no'                => 'No. rujukan:',
                'notes'                 => 'Nota:',
                'attachment'            => 'Lampiran:',

                'footer_text'           => 'Resit ini mengesahkan amaun di atas.',

                'sig_receiver_title'    => 'Tandatangan (Penerima / Pelanggan)',
                'sig_receiver_none'     => 'Belum ada tandatangan pelanggan.',

                'sig_payer_title'       => 'Tandatangan (Pembayar / Syarikat kami)',
                'sig_payer_none'        => 'Belum ada tandatangan pembayar.',

                'name_label'            => 'Nama:',
                'nric_short'            => 'NRIC:',
                'date_label'            => 'Tarikh:',
            ],

            'sign' => [
                'saved'                => 'Tandatangan dan penerima telah disimpan.',

                'who_title'            => 'Siapa tandatangan',
                'who_desc'             => 'Pilih orang yang tandatangan bagi pihak anda, kemudian tandatangan dalam kotak.',

                'recipient_name'       => 'Nama penerima',
                'recipient_placeholder' => 'Taip atau pilih daripada pengguna login...',
                'recipient_nric'       => 'NRIC penerima',
                'auto_fill_hint'       => 'Jika pilih dari senarai, NRIC auto isi. Anda juga boleh isi manual.',

                'sign_here_title'      => 'Tandatangan di sini',
                'sign_here_desc'       => 'Tandatangan dalam kotak. Selepas simpan, bayaran ini ditanda CONFIRMED.',

                'btn_save'             => 'Simpan penerima & tandatangan',

                'page_title'           => 'Tandatangan transaksi',
                'eyebrow'              => 'Pengesahan bayaran',
                'default_title'        => 'Bayaran',
                'subtitle'             => 'Sahkan anda telah menerima bayaran ini dengan tandatangan di bawah.',

                'not_pending'          => 'Transaksi ini bukan menunggu tandatangan anda (mungkin sudah confirmed, tidak perlu tandatangan, atau contra).',
                'thanks'               => 'Terima kasih. Bayaran ini telah ditandatangani dan disahkan.',

                'details_title'        => 'Butiran transaksi',
                'date'                 => 'Tarikh',
                'method'               => 'Kaedah',
                'amount'               => 'Amaun',
                'ref_no'               => 'No. rujukan',
                'recipient_name_label' => 'Nama penerima (yang tandatangan)',
                'recipient_nric_label' => 'NRIC penerima',
                'attachment'           => 'Dokumen lampiran',

                'sign_box_title'       => 'Tandatangan di sini',
                'sign_box_desc'        => 'Sila tandatangan dalam kotak menggunakan mouse atau sentuhan.',

                'btn_save_signature'   => 'Simpan tandatangan',

                'error_sign_box'       => 'Sila tandatangan dalam kotak sebelum simpan.',
            ],
        ],
    ],

];
