<?php
// config/company.php — 公司名称、地址、联络方式（发票/报价单/收据抬头）
declare(strict_types=1);

if (!function_exists('get_company')) {
    /**
     * @return array{name: string, reg_no: string, tax_no?: string, address: array<int, string>, phone: string, email: string}
     */
    function get_company(): array
    {
        return [
            'name'    => 'VISION MIX SDN BHD',
            'reg_no'  => '1622729-U',
            // 用在抬头：VISION MIX SDN BHD 202501021316 (1622729-U)
            'tax_no'  => '202501021316',
            'address' => [
                'LOT 3A-02A, 4TH FLOOR ENDAH PARADE,',
                'NO.1 JALAN 1/149E, BANDAR BARU SRI PETALING,',
                '57000 KUALA LUMPUR',
            ],
            'phone'   => '', // 公司联络号码 Tel，如：03-1234 5678
            'email'   => '', // 公司电邮 Email
        ];
    }
}
