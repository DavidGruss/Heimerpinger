<?php
// Minimal downtime monitor for cloud.gruss.li with Telegram notifications
// Runs as a web endpoint. Configure an task to hit this URL.

// Timezone for the maintenance window
date_default_timezone_set('Europe/Zurich');

// Load config
$configPathCandidates = [
    __DIR__ . '/monitor.config.php',            // same dir
    __DIR__ . '/../monitor.config.php',         // one level above www
];
$config = null;
foreach ($configPathCandidates as $cfg) {
    if (file_exists($cfg)) {
        /** @noinspection PhpIncludeInspection */
        $config = include $cfg;
        break;
    }
}
if (!is_array($config)) {
    http_response_code(500);
    echo 'Config missing. Create monitor.config.php (see monitor.config.php.example).';
    exit;
}

$targetUrl = $config['TARGET_URL'] ?? 'https://cloud.gruss.li/';
$displayName = $config['DISPLAY_NAME'] ?? (parse_url($targetUrl, PHP_URL_HOST) ?: 'service');
$telegramBotToken = $config['TELEGRAM_BOT_TOKEN'] ?? '';
$telegramChatId = $config['TELEGRAM_CHAT_ID'] ?? '';
$requestTimeoutSeconds = isset($config['REQUEST_TIMEOUT_SECONDS']) ? (int)$config['REQUEST_TIMEOUT_SECONDS'] : 10;
$alertAfterSeconds = isset($config['ALERT_AFTER_SECONDS']) ? (int)$config['ALERT_AFTER_SECONDS'] : 120;
$debugAlways = !empty($config['DEBUG_ALWAYS']);

// Down reminder interval (seconds) for continued pings during downtime
$downReminderIntervalSeconds = isset($config['DOWN_REMINDER_INTERVAL_SECONDS']) ? (int)$config['DOWN_REMINDER_INTERVAL_SECONDS'] : 300;
// HTTP status to return once outage threshold exceeded (cron-job.org requires 200)
$downHttpStatus = isset($config['DOWN_HTTP_STATUS']) ? (int)$config['DOWN_HTTP_STATUS'] : 200;
if ($downHttpStatus < 100 || $downHttpStatus > 599) {
    $downHttpStatus = 200;
}

// Daily maintenance window to skip alerts (HH:MM in local timezone)
$maintenanceStart = $config['MAINTENANCE_START'] ?? '04:20';
$maintenanceEnd = $config['MAINTENANCE_END'] ?? '04:25';

// State file stored outside web root if possible
$statePathCandidates = [
    __DIR__ . '/../monitor_state.json',
    __DIR__ . '/monitor_state.json',
];
$statePath = null;
foreach ($statePathCandidates as $sp) {
    $dir = dirname($sp);
    if (is_dir($dir) && is_writable($dir)) {
        $statePath = $sp;
        break;
    }
}
if ($statePath === null) {
    http_response_code(500);
    echo 'No writable location for state.';
    exit;
}

function read_state($path) {
    if (!file_exists($path)) {
        return [
            'last_status' => 'unknown', // 'up' | 'down' | 'unknown'
            'down_since' => null,       // unix ts
            'last_alert_sent' => 'none',// 'none' | 'down' | 'recover'
            'last_check' => null,       // unix ts
            'muted' => false,           // whether reminders are muted until recover
            'last_reminder_sent_ts' => null, // unix ts of last down reminder
            'telegram_update_offset' => null, // last processed update id + 1
        ];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [
            'last_status' => 'unknown',
            'down_since' => null,
            'last_alert_sent' => 'none',
            'last_check' => null,
            'muted' => false,
            'last_reminder_sent_ts' => null,
            'telegram_update_offset' => null,
        ];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [
            'last_status' => 'unknown',
            'down_since' => null,
            'last_alert_sent' => 'none',
            'last_check' => null,
            'muted' => false,
            'last_reminder_sent_ts' => null,
            'telegram_update_offset' => null,
        ];
    }
    return $data;
}

function write_state($path, $state) {
    @file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function within_window($startHhmm, $endHhmm, $nowTs) {
    $date = date('Y-m-d', $nowTs);
    $startTs = strtotime($date . ' ' . $startHhmm . ':00');
    $endTs = strtotime($date . ' ' . $endHhmm . ':00');
    return $nowTs >= $startTs && $nowTs <= $endTs;
}

function check_url_up($url, $timeout) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_NOBODY => true,        // HEAD request
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'gruss-monitor/1.0',
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($err !== 0) {
        return false;
    }
    return $httpCode >= 200 && $httpCode < 400;
}

function send_telegram($token, $chatId, $message) {
    if ($token === '' || $chatId === '') {
        return false;
    }
    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
    $payload = http_build_query([
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => 1,
    ]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $err = curl_errno($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $err === 0 && $status >= 200 && $status < 300 && $resp !== false;
}

function process_telegram_commands($token, $chatId, &$state) {
	if ($token === '' || $chatId === '') {
		return;
	}
	$baseUrl = 'https://api.telegram.org/bot' . rawurlencode($token) . '/getUpdates';
	$params = [];
	if (!empty($state['telegram_update_offset'])) {
		$params['offset'] = (int)$state['telegram_update_offset'];
	}
	$params['timeout'] = 0;
	$url = $baseUrl . (empty($params) ? '' : ('?' . http_build_query($params)));
	$ch = curl_init();
	curl_setopt_array($ch, [
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 10,
	]);
	$resp = curl_exec($ch);
	$err = curl_errno($ch);
	curl_close($ch);
	if ($err !== 0 || $resp === false) {
		return;
	}
	$payload = json_decode($resp, true);
	if (!is_array($payload) || empty($payload['ok']) || empty($payload['result'])) {
		return;
	}
	$maxUpdateId = null;
	foreach ($payload['result'] as $update) {
		if (!isset($update['update_id'])) {
			continue;
		}
		$maxUpdateId = max($maxUpdateId ?? $update['update_id'], $update['update_id']);
		$msg = $update['message'] ?? $update['edited_message'] ?? null;
		if (!$msg) {
			continue;
		}
		$chat = $msg['chat']['id'] ?? null;
		$text = $msg['text'] ?? '';
		if ((string)$chat !== (string)$chatId) {
			continue; // ignore other chats
		}
		$cmd = trim(strtolower($text));
		if ($cmd === '/mute' || $cmd === '/mute@') {
			$state['muted'] = true;
			// optional ack
			send_telegram($token, $chatId, '🔕 Muted until recovery.');
		} elseif ($cmd === '/unmute' || $cmd === '/unmute@') {
			$state['muted'] = false;
			send_telegram($token, $chatId, '🔔 Unmuted.');
		}
	}
	if ($maxUpdateId !== null) {
		$state['telegram_update_offset'] = $maxUpdateId + 1;
	}
}

function maybe_send_debug($enabled, $token, $chatId, $statusCode, $payloadArray) {
    if (!$enabled) {
        return;
    }
    $json = json_encode($payloadArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    // Keep message concise; wrap JSON in code block
    $msg = "🧪 monitor.php debug (HTTP ${statusCode})\n```${json}```";
    // If message is too long, truncate
    if (strlen($msg) > 3800) {
        $msg = substr($msg, 0, 3700) . "\n...(truncated)```";
    }
    send_telegram($token, $chatId, $msg);
}

$now = time();
// Read state early to handle commands and suppression before any alerts
$state = read_state($statePath);
process_telegram_commands($telegramBotToken, $telegramChatId, $state);
write_state($statePath, $state);
if (within_window($maintenanceStart, $maintenanceEnd, $now)) {
    $state = read_state($statePath);
    // Do not alert inside the window; reset counters to avoid stale alerts
    $state['last_status'] = 'unknown';
    $state['down_since'] = null;
    $state['last_alert_sent'] = 'none';
    $state['last_check'] = $now;
    write_state($statePath, $state);
    http_response_code(200);
    header('Content-Type: application/json');
    header('X-Monitor-Status: maintenance');
    $payload = ['ok' => true, 'message' => 'Within maintenance window', 'time' => date(DATE_ATOM, $now)];
    maybe_send_debug($debugAlways || (isset($_GET['debug']) && $_GET['debug'] === '1'), $telegramBotToken, $telegramChatId, 200, $payload);
    echo json_encode($payload);
    exit;
}

$isUp = check_url_up($targetUrl, $requestTimeoutSeconds);

$state = read_state($statePath);

if ($isUp) {
    if ($state['last_status'] === 'down' && $state['last_alert_sent'] === 'down') {
        $duration = $state['down_since'] ? ($now - (int)$state['down_since']) : null;
        $mins = $duration !== null ? round($duration / 60, 1) : 'N/A';
        $msg = "✅ ${displayName} recovered. Downtime ~${mins} min.";
        // Always send recovery even if muted, then auto-unmute
        send_telegram($telegramBotToken, $telegramChatId, $msg);
        $state['last_alert_sent'] = 'recover';
        $state['muted'] = false;
        $state['last_reminder_sent_ts'] = null;
    }
    $state['last_status'] = 'up';
    $state['down_since'] = null;
    $state['last_check'] = $now;
    write_state($statePath, $state);
    http_response_code(200);
    header('Content-Type: application/json');
    header('X-Monitor-Status: up');
    $payload = ['ok' => true, 'status' => 'up', 'time' => date(DATE_ATOM, $now)];
    maybe_send_debug($debugAlways || (isset($_GET['debug']) && $_GET['debug'] === '1'), $telegramBotToken, $telegramChatId, 200, $payload);
    echo json_encode($payload);
    exit;
}

// Down path
if ($state['last_status'] !== 'down') {
    $state['down_since'] = $now;
}
$state['last_status'] = 'down';
$state['last_check'] = $now;

$downFor = $state['down_since'] ? ($now - (int)$state['down_since']) : 0;
$hardOutage = $downFor >= $alertAfterSeconds;
if ($hardOutage) {
	$mins = round($downFor / 60);
    if ($state['last_alert_sent'] !== 'down') {
        $msg = "❌ ${displayName} appears DOWN for ${mins} minutes.";
		if (!$state['muted']) {
			send_telegram($telegramBotToken, $telegramChatId, $msg);
		}
		$state['last_alert_sent'] = 'down';
		$state['last_reminder_sent_ts'] = $now;
	} else {
		// Already alerted; send periodic reminders unless muted
		$lastRem = isset($state['last_reminder_sent_ts']) ? (int)$state['last_reminder_sent_ts'] : null;
        if (!$state['muted'] && ($lastRem === null || ($now - $lastRem) >= $downReminderIntervalSeconds)) {
            $msg = "❌ ${displayName} still DOWN (~${mins} min).";
			send_telegram($telegramBotToken, $telegramChatId, $msg);
			$state['last_reminder_sent_ts'] = $now;
		}
	}
}

write_state($statePath, $state);
// Return 200 for short blips (< alertAfterSeconds). For hard outages return configured code (default 200 to keep cron-job.org polling).
$statusCode = $hardOutage ? $downHttpStatus : 200;
http_response_code($statusCode);
header('Content-Type: application/json');
header('X-Monitor-Status: down');
$extraHeaders = [
    'X-Monitor-Hard-Outage' => $hardOutage ? '1' : '0',
    'X-Monitor-Configured-Down-Http' => (string)$downHttpStatus,
];
foreach ($extraHeaders as $headerName => $headerValue) {
    header($headerName . ': ' . $headerValue);
}
$payload = [
    'ok' => true,
    'status' => 'down',
    'down_since' => $state['down_since'],
    'down_for_seconds' => $downFor,
    'threshold_seconds' => $alertAfterSeconds,
    'hard_outage' => $hardOutage,
    'http_status' => $statusCode,
    'time' => date(DATE_ATOM, $now),
];
maybe_send_debug($debugAlways || (isset($_GET['debug']) && $_GET['debug'] === '1'), $telegramBotToken, $telegramChatId, $statusCode, $payload);
echo json_encode($payload);
exit;


