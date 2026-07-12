<?php

declare(strict_types=1);

/**
 * mail() を使ったメール送信。Fromは常に自ドメインの固定アドレス(config)を使い、
 * SPFアライメントを崩さないようにする。宛先は呼び出し元でactives突合済みのアドレスに限る。
 */

function mlv_send_token_mail(string $email, string $token): void
{
    $baseUrl = rtrim((string) mlv_config('base_url'), '/');
    $link = $baseUrl . '/#/set-password?token=' . urlencode($token);

    $subject = 'パスワード設定のご案内';
    $body = "以下のリンクからパスワードを設定してください（有効期限: 24時間）。\n\n"
        . $link . "\n\n"
        . "このメールに心当たりがない場合は、破棄してください。\n";

    mlv_send_mail($email, $subject, $body);
}

function mlv_send_mail(string $to, string $subject, string $body): void
{
    $fromAddr = (string) mlv_config('mail_from');
    $fromName = (string) mlv_config('mail_from_name');

    $encodedFromName = mb_encode_mimeheader($fromName, 'UTF-8', 'B');
    $headers = "From: {$encodedFromName} <{$fromAddr}>\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";

    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B');

    // エンベロープFromも自ドメイン固定アドレスにし、SPFアライメントを崩さない
    $additionalParams = '-f' . $fromAddr;

    $ok = @mail($to, $encodedSubject, $body, $headers, $additionalParams);
    if (!$ok) {
        mlv_log('mail() failed to send token mail');
    }
}
