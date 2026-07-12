<?php

declare(strict_types=1);

/**
 * fmlスプールの生メール(RFC822)を解析する純関数群。
 * DB・ファイルシステム(fml側)には一切依存しない。mlv_parse_mail_file() のみファイル読込を行う。
 */

final class MlvParseContext
{
    /** @var list<string> */
    public array $bodyParts = [];
    /** @var list<array{filename:string,mime:string,size:int}> */
    public array $attachments = [];
    public bool $partial = false;
}

/**
 * spool内のファイルを読み込んで解析する。読み込み失敗・予期せぬ例外は parse_status='error' で返す。
 *
 * @return array{message_id:?string,subject:string,from_addr:string,from_name:string,date_epoch:int,body_text:string,attachments:list<array{filename:string,mime:string,size:int}>,parse_status:string}
 */
function mlv_parse_mail_file(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return mlv_error_result();
    }

    $mtime = @filemtime($path);
    $fallbackEpoch = $mtime !== false ? $mtime : time();

    try {
        return mlv_parse_mail_string($raw, $fallbackEpoch);
    } catch (\Throwable $e) {
        if (function_exists('mlv_log')) {
            mlv_log('mail_parser: failed to parse ' . $path . ': ' . $e->getMessage());
        }
        return mlv_error_result();
    }
}

function mlv_error_result(): array
{
    return [
        'message_id' => null,
        'subject' => '',
        'from_addr' => '',
        'from_name' => '',
        'date_epoch' => 0,
        'body_text' => '',
        'attachments' => [],
        'parse_status' => 'error',
    ];
}

/**
 * 生メール文字列を解析する。この関数自体は例外を投げない設計とし、
 * 解析困難な入力は best-effort で処理したうえで parse_status='partial' を返す。
 *
 * @return array{message_id:?string,subject:string,from_addr:string,from_name:string,date_epoch:int,body_text:string,attachments:list<array{filename:string,mime:string,size:int}>,parse_status:string}
 */
function mlv_parse_mail_string(string $raw, int $fallbackEpoch): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);

    [$headerBlock] = mlv_split_message($raw);
    $headers = mlv_parse_headers($headerBlock);

    $partial = false;

    $subjectDecoded = mlv_decode_mime_header($headers['subject'] ?? '');
    if (!$subjectDecoded['ok']) {
        $partial = true;
    }

    $messageId = mlv_scrub_utf8(trim($headers['message-id'] ?? ''));
    $messageId = $messageId !== '' ? $messageId : null;

    $fromParsed = mlv_parse_from_header($headers['from'] ?? '');
    if (!$fromParsed['ok']) {
        $partial = true;
    }

    $dateRaw = $headers['date'] ?? '';
    $epoch = $dateRaw !== '' ? strtotime($dateRaw) : false;
    if ($epoch === false) {
        $epoch = $fallbackEpoch;
        $partial = true;
    }

    $ctx = new MlvParseContext();
    mlv_extract_entity($raw, $ctx, 0);
    if ($ctx->partial) {
        $partial = true;
    }

    $bodyText = trim(implode("\n\n", $ctx->bodyParts));

    return [
        'message_id' => $messageId,
        'subject' => $subjectDecoded['text'],
        'from_addr' => $fromParsed['addr'],
        'from_name' => $fromParsed['name'],
        'date_epoch' => (int) $epoch,
        'body_text' => $bodyText,
        'attachments' => $ctx->attachments,
        'parse_status' => $partial ? 'partial' : 'ok',
    ];
}

// ---------------------------------------------------------------------
// ヘッダ/本文分離・ヘッダ解析
// ---------------------------------------------------------------------

/**
 * @return array{0:string,1:string} [ヘッダブロック, 本文]
 */
function mlv_split_message(string $raw): array
{
    $pos = strpos($raw, "\n\n");
    if ($pos === false) {
        return [$raw, ''];
    }
    return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
}

/**
 * 折返し(継続行)をunfoldしつつヘッダ名(小文字)=>値 の連想配列を作る。
 *
 * @return array<string,string>
 */
function mlv_parse_headers(string $block): array
{
    $lines = explode("\n", $block);
    $headers = [];
    $currentName = null;

    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }
        if (($line[0] === ' ' || $line[0] === "\t") && $currentName !== null) {
            $headers[$currentName] .= ' ' . trim($line);
            continue;
        }
        $colonPos = strpos($line, ':');
        if ($colonPos === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $colonPos)));
        $value = trim(substr($line, $colonPos + 1));
        $headers[$name] = $value;
        $currentName = $name;
    }

    return $headers;
}

// ---------------------------------------------------------------------
// RFC2047 (encoded-word) デコード
// ---------------------------------------------------------------------

/**
 * @return array{text:string,ok:bool}
 */
function mlv_decode_mime_header(string $value): array
{
    if ($value === '') {
        return ['text' => '', 'ok' => true];
    }

    // encoded-word(=?charset?B/Q?...?=)を含まない値にmb_decode_mimeheader()を使うと、
    // 生の8bit文字列(古い日本語MUAが出す非RFC2047のUTF-8/Shift-JIS等)を破壊することがあるため、
    // その場合は本文と同じ文字コード自動判定経路にそのまま通す。
    if (!str_contains($value, '=?')) {
        return ['text' => mlv_to_utf8($value, null), 'ok' => true];
    }

    $decoded = @mb_decode_mimeheader($value);
    if (!is_string($decoded) || $decoded === '') {
        return ['text' => mlv_to_utf8($value, null), 'ok' => false];
    }
    // デコード後もencoded-word形式が残っていれば、非対応charset等で失敗したとみなす
    $ok = preg_match('/=\?[^?]+\?[bBqQ]\?/', $decoded) !== 1;
    return ['text' => mlv_scrub_utf8($decoded), 'ok' => $ok];
}

// ---------------------------------------------------------------------
// Fromヘッダ分解: "表示名 <addr>" / "addr (コメント)" / 素のaddr
// ---------------------------------------------------------------------

/**
 * @return array{addr:string,name:string,ok:bool}
 */
function mlv_parse_from_header(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['addr' => '', 'name' => '', 'ok' => true];
    }

    if (preg_match('/^(.*)<\s*([^<>\s]+@[^<>\s]+)\s*>\s*$/', $raw, $m)) {
        $namePart = trim($m[1], " \t\"'");
        $addr = strtolower(trim($m[2]));
        $decoded = mlv_decode_mime_header($namePart);
        return ['addr' => $addr, 'name' => $decoded['text'], 'ok' => $decoded['ok']];
    }

    if (preg_match('/^([^\s()<>]+@[^\s()<>]+)\s*\(([^()]*)\)\s*$/', $raw, $m)) {
        $addr = strtolower(trim($m[1]));
        $decoded = mlv_decode_mime_header(trim($m[2]));
        return ['addr' => $addr, 'name' => $decoded['text'], 'ok' => $decoded['ok']];
    }

    if (preg_match('/^[^\s<>()]+@[^\s<>()]+$/', $raw)) {
        return ['addr' => strtolower($raw), 'name' => '', 'ok' => true];
    }

    return ['addr' => '', 'name' => mlv_scrub_utf8($raw), 'ok' => false];
}

// ---------------------------------------------------------------------
// Content-Type / Content-Disposition パラメータ解析（RFC2231対応）
// ---------------------------------------------------------------------

/**
 * "text/plain; charset=iso-2022-jp" のようなヘッダ値を解析する。
 * '_value' に主値（小文字化）、それ以外のキーにパラメータを格納する。
 *
 * @return array<string,string>
 */
function mlv_parse_header_params(string $value): array
{
    $parts = mlv_split_header_params($value);
    $main = array_shift($parts) ?? '';
    $result = ['_value' => strtolower(trim($main))];

    $continuations = [];

    foreach ($parts as $part) {
        if (!str_contains($part, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $part, 2);
        $key = trim($key);
        $val = mlv_strip_quotes(trim($val));

        if (preg_match('/^([a-zA-Z0-9_.-]+)\*(\d+)\*?$/', $key, $m)) {
            $continuations[strtolower($m[1])][(int) $m[2]] = $val;
            continue;
        }

        if (preg_match('/^([a-zA-Z0-9_.-]+)\*$/', $key, $m)) {
            $result[strtolower($m[1])] = mlv_decode_rfc2231_value($val);
            continue;
        }

        $key = strtolower($key);
        if (!isset($result[$key])) {
            $result[$key] = $val;
        }
    }

    foreach ($continuations as $baseKey => $segments) {
        ksort($segments);
        $result[$baseKey] = mlv_decode_rfc2231_value(implode('', $segments));
    }

    return $result;
}

/**
 * ";" 区切りだが引用符(バックスラッシュエスケープ対応)内は分割しない。
 *
 * @return list<string>
 */
function mlv_split_header_params(string $value): array
{
    $parts = [];
    $current = '';
    $inQuotes = false;
    $len = strlen($value);

    for ($i = 0; $i < $len; $i++) {
        $ch = $value[$i];
        if ($ch === '\\' && $inQuotes && $i + 1 < $len) {
            $current .= $ch . $value[$i + 1];
            $i++;
            continue;
        }
        if ($ch === '"') {
            $inQuotes = !$inQuotes;
            $current .= $ch;
            continue;
        }
        if ($ch === ';' && !$inQuotes) {
            $parts[] = $current;
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    if (trim($current) !== '') {
        $parts[] = $current;
    }

    return $parts;
}

function mlv_strip_quotes(string $val): string
{
    if (strlen($val) >= 2 && $val[0] === '"' && $val[-1] === '"') {
        $val = substr($val, 1, -1);
        $val = str_replace(['\\"', '\\\\'], ['"', '\\'], $val);
    }
    return $val;
}

/** charset'lang'pct-encoded-value 形式（RFC2231）をデコードする */
function mlv_decode_rfc2231_value(string $raw): string
{
    if (preg_match("/^([^']*)'([^']*)'(.*)$/", $raw, $m)) {
        $charset = $m[1] !== '' ? $m[1] : 'UTF-8';
        $decoded = rawurldecode($m[3]);
        return mlv_to_utf8($decoded, $charset);
    }
    return $raw;
}

// ---------------------------------------------------------------------
// MIMEエンティティの再帰的な展開
// ---------------------------------------------------------------------

const MLV_MAX_MIME_DEPTH = 5;
const MLV_MAX_PART_BYTES = 1024 * 1024; // 1MB

function mlv_extract_entity(string $entityRaw, MlvParseContext $ctx, int $depth): void
{
    if ($depth > MLV_MAX_MIME_DEPTH) {
        $ctx->partial = true;
        return;
    }

    [$headerBlock, $body] = mlv_split_message($entityRaw);
    $headers = mlv_parse_headers($headerBlock);

    $ct = mlv_parse_header_params($headers['content-type'] ?? 'text/plain; charset=us-ascii');
    $mainType = $ct['_value'] !== '' ? $ct['_value'] : 'text/plain';
    $cte = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));

    $cdRaw = $headers['content-disposition'] ?? '';
    $cd = $cdRaw !== '' ? mlv_parse_header_params($cdRaw) : ['_value' => ''];

    if (str_starts_with($mainType, 'multipart/')) {
        mlv_extract_multipart($mainType, $ct, $body, $ctx, $depth);
        return;
    }

    $filename = mlv_extract_filename($ct, $cd);
    $isTextType = str_starts_with($mainType, 'text/');
    $isAttachment = ($cd['_value'] === 'attachment') || (!$isTextType && $filename !== null);

    if ($isAttachment) {
        $ctx->attachments[] = [
            'filename' => $filename ?? '(no name)',
            'mime' => $mainType,
            'size' => mlv_decoded_size($body, $cte),
        ];
        return;
    }

    if (!$isTextType) {
        return; // 添付でもテキストでもないパート（例: 署名情報）は無視
    }

    $decoded = mlv_decode_body($body, $cte, $ctx);
    if ($decoded === null) {
        $ctx->partial = true;
        return;
    }

    $text = mlv_to_utf8($decoded, $ct['charset'] ?? null);
    if ($mainType === 'text/html') {
        $text = mlv_html_to_text($text);
    }

    $text = trim(mlv_scrub_utf8($text));
    if ($text !== '') {
        $ctx->bodyParts[] = $text;
    }
}

function mlv_extract_multipart(string $mainType, array $ct, string $body, MlvParseContext $ctx, int $depth): void
{
    $boundary = $ct['boundary'] ?? '';
    if ($boundary === '') {
        $ctx->partial = true;
        return;
    }

    $subParts = mlv_split_by_boundary($body, $boundary);
    if (empty($subParts)) {
        $ctx->partial = true;
        return;
    }

    if ($mainType === 'multipart/alternative') {
        $chosen = mlv_pick_alternative($subParts);
        if ($chosen !== null) {
            mlv_extract_entity($chosen, $ctx, $depth + 1);
            return;
        }
        // plain/htmlどちらも見つからない稀なケースは全パートを試す
    }

    foreach ($subParts as $part) {
        mlv_extract_entity($part, $ctx, $depth + 1);
    }
}

/**
 * @param list<string> $subParts
 */
function mlv_split_by_boundary(string $body, string $boundary): array
{
    $delimiter = '--' . $boundary;
    $segments = explode($delimiter, $body);
    array_shift($segments); // preamble破棄

    $parts = [];
    foreach ($segments as $seg) {
        $trimmed = ltrim($seg, "\n");
        if (str_starts_with($trimmed, '--')) {
            break; // 終端デリミタ以降(epilogue)
        }
        $seg = preg_replace('/^\n/', '', $seg, 1) ?? $seg;
        if (trim($seg) === '') {
            continue;
        }
        $parts[] = $seg;
    }

    return $parts;
}

/**
 * @param list<string> $subParts
 */
function mlv_pick_alternative(array $subParts): ?string
{
    $htmlPart = null;
    foreach ($subParts as $part) {
        [$headerBlock] = mlv_split_message($part);
        $headers = mlv_parse_headers($headerBlock);
        $ct = mlv_parse_header_params($headers['content-type'] ?? 'text/plain');
        if ($ct['_value'] === 'text/plain') {
            return $part;
        }
        if ($ct['_value'] === 'text/html' && $htmlPart === null) {
            $htmlPart = $part;
        }
    }
    return $htmlPart;
}

function mlv_extract_filename(array $ct, array $cd): ?string
{
    $raw = $cd['filename'] ?? $ct['name'] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    if (str_contains($raw, '=?')) {
        return mlv_decode_mime_header($raw)['text'];
    }
    return mlv_to_utf8($raw, null);
}

// ---------------------------------------------------------------------
// Content-Transfer-Encoding デコード（サイズ上限つき）
// ---------------------------------------------------------------------

function mlv_decode_body(string $raw, string $cte, MlvParseContext $ctx): ?string
{
    switch ($cte) {
        case 'base64':
            $clean = preg_replace('/[^A-Za-z0-9+\/=]/', '', $raw) ?? '';
            if ($clean === '') {
                return '';
            }
            $estimatedLen = (int) (strlen($clean) * 3 / 4);
            if ($estimatedLen > MLV_MAX_PART_BYTES) {
                $clean = substr($clean, 0, (int) (MLV_MAX_PART_BYTES * 4 / 3));
                $ctx->partial = true;
            }
            $decoded = base64_decode($clean, true);
            return $decoded === false ? null : $decoded;

        case 'quoted-printable':
            if (strlen($raw) > MLV_MAX_PART_BYTES * 4) {
                $raw = substr($raw, 0, MLV_MAX_PART_BYTES * 4);
                $ctx->partial = true;
            }
            $decoded = quoted_printable_decode($raw);
            if (strlen($decoded) > MLV_MAX_PART_BYTES) {
                $decoded = substr($decoded, 0, MLV_MAX_PART_BYTES);
                $ctx->partial = true;
            }
            return $decoded;

        case '7bit':
        case '8bit':
        case 'binary':
        case '':
            if (strlen($raw) > MLV_MAX_PART_BYTES) {
                $raw = substr($raw, 0, MLV_MAX_PART_BYTES);
                $ctx->partial = true;
            }
            return $raw;

        default:
            // 未知のCTE。生のまま扱い partial とする。
            if (strlen($raw) > MLV_MAX_PART_BYTES) {
                $raw = substr($raw, 0, MLV_MAX_PART_BYTES);
            }
            $ctx->partial = true;
            return $raw;
    }
}

/** 添付の実体は保存せず、デコード後サイズだけを計算する */
function mlv_decoded_size(string $raw, string $cte): int
{
    switch ($cte) {
        case 'base64':
            $clean = preg_replace('/[^A-Za-z0-9+\/=]/', '', $raw) ?? '';
            $padding = 0;
            if (str_ends_with($clean, '==')) {
                $padding = 2;
            } elseif (str_ends_with($clean, '=')) {
                $padding = 1;
            }
            return max(0, (int) (strlen($clean) / 4 * 3) - $padding);
        case 'quoted-printable':
            return strlen(quoted_printable_decode($raw));
        default:
            return strlen($raw);
    }
}

// ---------------------------------------------------------------------
// 文字コード正規化
// ---------------------------------------------------------------------

function mlv_normalize_charset_name(string $charset): string
{
    $c = strtolower(trim($charset));
    return match (true) {
        in_array($c, ['us-ascii', 'ascii'], true) => 'ASCII',
        in_array($c, ['utf-8', 'utf8'], true) => 'UTF-8',
        in_array($c, ['shift_jis', 'shift-jis', 'sjis', 'windows-31j', 'ms932', 'cp932'], true) => 'SJIS-win',
        in_array($c, ['euc-jp', 'eucjp'], true) => 'EUC-JP',
        in_array($c, ['iso-2022-jp', 'iso2022jp', 'iso-2022-jp-1'], true) => 'ISO-2022-JP',
        default => $charset,
    };
}

function mlv_to_utf8(string $data, ?string $charset): string
{
    if ($charset !== null && $charset !== '') {
        $converted = mlv_try_convert($data, mlv_normalize_charset_name($charset));
        if ($converted !== null) {
            return mlv_scrub_utf8($converted);
        }
    }

    $detected = @mb_detect_encoding($data, ['ASCII', 'JIS', 'UTF-8', 'ISO-2022-JP', 'EUC-JP', 'SJIS-win'], true);
    $converted = mlv_try_convert($data, $detected !== false ? $detected : 'UTF-8');

    return mlv_scrub_utf8($converted ?? $data);
}

function mlv_try_convert(string $data, string $fromEncoding): ?string
{
    try {
        $converted = @mb_convert_encoding($data, 'UTF-8', $fromEncoding);
    } catch (\Throwable) {
        return null;
    }
    return is_string($converted) && $converted !== '' ? $converted : ($data === '' ? '' : null);
}

/** NUL・C0制御文字（改行・タブ以外）を除去し、不正なUTF-8バイト列をエスケープする */
function mlv_scrub_utf8(string $s): string
{
    $clean = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    if (!is_string($clean)) {
        $clean = $s;
    }
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $clean) ?? $clean;
}

// ---------------------------------------------------------------------
// HTML本文のテキスト化
// ---------------------------------------------------------------------

function mlv_html_to_text(string $html): string
{
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
    $html = preg_replace('#</p>#i', "\n\n", $html) ?? $html;
    $text = strip_tags($html);
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
