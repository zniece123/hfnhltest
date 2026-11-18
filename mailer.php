<?php
function sendLeagueEmail($toList, $subject, $body) {
    $smtpUser = getenv('SMTP_USER') ?: 'hfnhl.alerts@gmail.com';
    $smtpPass = getenv('SMTP_PASS') ?: '';

    if (empty($toList) || empty($smtpPass) || $smtpPass === 'YOUR_16_LETTER_APP_PASSWORD') {
        return;
    }

    $recipients = array_unique(array_filter(array_map('trim', (array)$toList), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (empty($recipients)) return;

    $fp = @stream_socket_client("tcp://://gmail.com", $errno, $errstr, 15);
    if (!$fp) return;

    $read = function() use ($fp) {
        $data = '';
        while ($str = fgets($fp, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        return $data;
    };
    $cmd = function($c) use ($fp, $read) {
        fwrite($fp, $c . "\r\n");
        return $read();
    };

    $read();
    $cmd("EHLO localhost");
    $cmd("STARTTLS");
    stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    $cmd("EHLO localhost");
    $cmd("AUTH LOGIN");
    $cmd(base64_encode($smtpUser));
    $cmd(base64_encode($smtpPass));

    foreach ($recipients as $to) {
        $cmd("MAIL FROM:<{$smtpUser}>");
        $cmd("RCPT TO:<{$to}>");
        $cmd("DATA");
        $headers  = "From: HFNHL League Office <{$smtpUser}>\r\n";
        $headers .= "To: <{$to}>\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $cmd($headers . $body . "\r\n.");
    }

    $cmd("QUIT");
    fclose($fp);
}
?>


