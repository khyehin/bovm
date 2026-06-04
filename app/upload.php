<?php
// app/upload.php
declare(strict_types=1);

if (!function_exists('app_upload_ini_bytes')) {
    function app_upload_ini_bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') return 0;

        $unit = strtolower(substr($value, -1));
        $number = (float)$value;

        switch ($unit) {
            case 'g':
                $number *= 1024;
                // no break
            case 'm':
                $number *= 1024;
                // no break
            case 'k':
                $number *= 1024;
                break;
        }

        return (int)$number;
    }
}

if (!function_exists('app_upload_format_bytes')) {
    function app_upload_format_bytes(int $bytes): string
    {
        if ($bytes <= 0) return 'unlimited';
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float)$bytes;
        $idx = 0;
        while ($size >= 1024 && $idx < count($units) - 1) {
            $size /= 1024;
            $idx++;
        }
        return rtrim(rtrim(number_format($size, 1), '0'), '.') . ' ' . $units[$idx];
    }
}

if (!function_exists('app_upload_limit_bytes')) {
    function app_upload_limit_bytes(): int
    {
        $uploadMax = app_upload_ini_bytes((string)ini_get('upload_max_filesize'));
        $postMax = app_upload_ini_bytes((string)ini_get('post_max_size'));

        if ($uploadMax > 0 && $postMax > 0) return min($uploadMax, $postMax);
        return max($uploadMax, $postMax);
    }
}

if (!function_exists('app_upload_limit_label')) {
    function app_upload_limit_label(): string
    {
        return app_upload_format_bytes(app_upload_limit_bytes());
    }
}

if (!function_exists('app_upload_is_oversized_post')) {
    function app_upload_is_oversized_post(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
        if (!empty($_POST) || !empty($_FILES)) return false;

        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        return $contentLength > 0;
    }
}

if (!function_exists('app_upload_oversized_post_message')) {
    function app_upload_oversized_post_message(): string
    {
        return 'Upload failed: file is too large. Current server limit is ' . app_upload_limit_label() . '.';
    }
}

if (!function_exists('app_upload_error_message')) {
    function app_upload_error_message(int $code, string $name = ''): string
    {
        $prefix = $name !== '' ? 'File "' . $name . '": ' : '';

        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $prefix . 'file is too large. Current server limit is ' . app_upload_limit_label() . '.';
            case UPLOAD_ERR_PARTIAL:
                return $prefix . 'upload was interrupted. Please try again.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return $prefix . 'server temporary upload folder is missing.';
            case UPLOAD_ERR_CANT_WRITE:
                return $prefix . 'server failed to write the uploaded file.';
            case UPLOAD_ERR_EXTENSION:
                return $prefix . 'upload was stopped by a server extension.';
            default:
                return $prefix . 'upload error (code ' . $code . ').';
        }
    }
}
