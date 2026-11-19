<?php
function sendLeagueEmail($toList, $subject, $body) {
    global $pdo;

    $smtpUser = getenv('SMTP_USER') ?: 'hfnhl.alerts@gmail.com';
    $smtpPass = getenv('SMTP_PASS') ?: 'bqqf vxyy fotc xjnl';

    if ($smtpPass === '' && isset($pdo) && $pdo instanceof PDO) {
        try {
            $st = $pdo->query("SELECT setting_value FROM league_settings WHERE setting_key = 'smtp_app_password' LIMIT 1");
            $dbPass = $st->fetchColumn();
            if ($dbPass) $smtpPass = trim($dbPass);
        } catch (Exception $e) {}
    }

    if (empty($toList) || empty($smtpPass) || $smtpPass === 'YOUR_16_LETTER_APP_PASSWORD') {
        return false;
    }

    $recipients = array_unique(array_filter(
        array_map('trim', (array)$toList),
        fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
    ));
    if (empty($recipients)) return false;

    $fp = @stream_socket_client("tcp://://gmail.com", $errno, $errstr, 15);
    if (!$fp) return false;

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
    if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($fp);
        return false;
    }
    $cmd("EHLO localhost");
    $cmd("AUTH LOGIN");
    $cmd(base64_encode($smtpUser));
    $authResp = $cmd(base64_encode($smtpPass));

    if (strpos($authResp, '235') === false) {
        fclose($fp);
        return false;
    }

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
    return true;
}
?>



