<?php
/**
 * RAKIB X — Server-side dual load tester + auto capacity checker.
 *
 * Upload this file to your OWN PHP hosting. Open it in a browser.
 * The test runs on the SERVER in a detached PHP process — not in
 * your browser tab — so closing the browser, switching apps, or
 * reloading the page does NOT stop a running test. Reopen this page
 * any time and it shows the current status of Slot A, Slot B, and
 * the Auto Capacity Check.
 *
 * ONLY point this at a website you own or have explicit permission
 * to test. Sending high-volume traffic at a site you do not control
 * can be treated as a denial-of-service attack and may be illegal.
 *
 * Requirements: PHP with curl + curl_multi enabled. Shared/free
 * hosting enforces its own execution-time and outbound-connection
 * limits that this script cannot override — real throughput is
 * bounded by what your host allows, not just by this code. Tune
 * RAKIBX_MAX_CONCURRENCY below if your host can take more.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('UTC');

// Raise this if your host can sustain more concurrent outbound
// connections. Shared/free hosts usually choke well before this.
define('RAKIBX_MAX_CONCURRENCY', 3000);

// If a status file hasn't been updated in this many seconds while
// still marked "running", the client treats it as stalled (the host
// most likely killed the background process) and offers Force Reset.
define('RAKIBX_STALE_SEC', 8);

// Steps used by the Auto Capacity Check (requests/sec), each held
// for RAKIBX_AUTO_STEP_SEC seconds before moving to the next level.
define('RAKIBX_AUTO_STEP_SEC', 10);
$RAKIBX_AUTO_STEPS = [100, 250, 500, 1000, 1500, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000];

// ---------------------------------------------------------------------
// Storage
// ---------------------------------------------------------------------
$dataDir = __DIR__ . '/rakibx_data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}

function rx_status_path($slot) {
    global $dataDir;
    $slot = preg_replace('/[^A-Za-z0-9]/', '', $slot);
    return $dataDir . '/status_' . $slot . '.json';
}

function rx_stop_path($slot) {
    global $dataDir;
    $slot = preg_replace('/[^A-Za-z0-9]/', '', $slot);
    return $dataDir . '/stop_' . $slot . '.flag';
}

function rx_write_status($slot, $data) {
    $data['updated_at'] = microtime(true);
    $tmp = rx_status_path($slot) . '.tmp';
    @file_put_contents($tmp, json_encode($data));
    @rename($tmp, rx_status_path($slot));
}

function rx_read_status($slot) {
    $path = rx_status_path($slot);
    if (!is_file($path)) {
        return [
            'slot' => $slot, 'state' => 'idle', 'mode' => 'manual',
            'target_url' => '', 'target_rate' => 0, 'duration_sec' => 0,
            'elapsed' => 0, 'dispatched' => 0, 'success' => 0, 'fail' => 0,
            'current_rate' => 0, 'verdict' => '', 'recent_log' => [],
            'auto_steps' => [], 'safe_rate' => null,
        ];
    }
    $raw = @file_get_contents($path);
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['slot' => $slot, 'state' => 'idle'];
}

// ---------------------------------------------------------------------
// API routing
// ---------------------------------------------------------------------
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

if ($action === 'status') {
    header('Content-Type: application/json');
    $slot = isset($_GET['slot']) ? $_GET['slot'] : 'A';
    echo json_encode(rx_read_status($slot));
    exit;
}

if ($action === 'stop') {
    header('Content-Type: application/json');
    $slot = isset($_GET['slot']) ? $_GET['slot'] : 'A';
    @file_put_contents(rx_stop_path($slot), '1');
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'reset') {
    // Hard reset: clears a slot's status/stop files even if the
    // background process that owned it was already killed by the
    // host. Use this when a run looks stuck — Stop not responding,
    // no new log lines, counters frozen.
    header('Content-Type: application/json');
    $slot = isset($_GET['slot']) ? $_GET['slot'] : 'A';
    $targets = ($slot === 'ALL') ? ['A', 'B', 'C'] : [preg_replace('/[^A-Za-z0-9]/', '', $slot)];
    foreach ($targets as $s) {
        @unlink(rx_status_path($s));
        @unlink(rx_stop_path($s));
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'start') {
    header('Content-Type: application/json');

    $slot = isset($_POST['slot']) ? $_POST['slot'] : 'A';
    $slot = preg_replace('/[^A-Za-z0-9]/', '', $slot);
    $mode = (isset($_POST['mode']) && $_POST['mode'] === 'auto') ? 'auto' : 'manual';
    $targetUrl = isset($_POST['url']) ? trim($_POST['url']) : '';
    $targetRate = isset($_POST['rate']) ? max(1, (int) $_POST['rate']) : 1000;
    $durationSec = isset($_POST['duration']) ? (int) $_POST['duration'] : 60;
    $confirmed = isset($_POST['confirm']) && $_POST['confirm'] === '1';

    if (!$confirmed) {
        echo json_encode(['ok' => false, 'error' => 'Confirmation required.']);
        exit;
    }
    if (!preg_match('/^https?:\/\//i', $targetUrl)) {
        $targetUrl = 'https://' . $targetUrl;
    }
    if (!filter_var($targetUrl, FILTER_VALIDATE_URL)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid URL.']);
        exit;
    }

    $HARD_MAX_SEC = 21600; // 6 hour safety backstop even for "unlimited"
    if ($mode === 'manual') {
        if ($durationSec <= 0 || $durationSec > $HARD_MAX_SEC) {
            $durationSec = $durationSec <= 0 ? $HARD_MAX_SEC : min($durationSec, $HARD_MAX_SEC);
        }
    } else {
        $durationSec = count($GLOBALS['RAKIBX_AUTO_STEPS']) * RAKIBX_AUTO_STEP_SEC;
    }

    @unlink(rx_stop_path($slot));
    rx_write_status($slot, [
        'slot' => $slot, 'state' => 'running', 'mode' => $mode,
        'target_url' => $targetUrl, 'target_rate' => $targetRate,
        'duration_sec' => $durationSec, 'elapsed' => 0,
        'dispatched' => 0, 'success' => 0, 'fail' => 0, 'current_rate' => 0,
        'verdict' => '', 'recent_log' => [], 'auto_steps' => [], 'safe_rate' => null,
        'started_at' => time(),
    ]);

    ignore_user_abort(true);
    set_time_limit(0);
    while (ob_get_level() > 0) { ob_end_clean(); }
    ob_start();
    echo json_encode(['ok' => true, 'slot' => $slot]);
    $len = ob_get_length();
    header('Content-Length: ' . $len);
    header('Connection: close');
    ob_end_flush();
    @flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    if ($mode === 'auto') {
        rx_run_auto_engine($slot, $targetUrl);
    } else {
        rx_run_engine($slot, $targetUrl, $targetRate, $durationSec);
    }
    exit;
}

// ---------------------------------------------------------------------
// Shared dispatch primitives
// ---------------------------------------------------------------------
function rx_new_handle($targetUrl) {
    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => function ($chunkHandle, $data) { return strlen($data); },
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'RakibX-LoadTester/2.0',
        CURLOPT_HTTPHEADER => ['Connection: close'],
    ]);
    return $ch;
}

// ---------------------------------------------------------------------
// Manual-rate engine (Slot A / Slot B)
// ---------------------------------------------------------------------
function rx_current_rate($elapsed, $targetRate, $durationSec) {
    $rampSec = min(8, max(1, $durationSec * 0.2));
    if ($elapsed < $rampSec) {
        $base = max(5, (int) ($targetRate * 0.05));
        return $base + ($targetRate - $base) * ($elapsed / $rampSec);
    }
    $jitter = 0.9 + (mt_rand(0, 200) / 1000);
    return min($targetRate * 1.0, $targetRate * $jitter);
}

function rx_run_engine($slot, $targetUrl, $targetRate, $durationSec) {
    $stopFlag = rx_stop_path($slot);
    $startTime = microtime(true);
    $lastWrite = 0;
    $dispatched = 0; $success = 0; $fail = 0;
    $recentLog = [];
    $secondBuckets = [];

    $maxConcurrent = max(20, min(RAKIBX_MAX_CONCURRENCY, (int) ($targetRate / 3)));
    $mh = curl_multi_init();
    $active = [];

    while (true) {
        $elapsed = microtime(true) - $startTime;
        if ($elapsed >= $durationSec) break;
        if (is_file($stopFlag)) break;

        $sec = (int) floor($elapsed);
        if (!isset($secondBuckets[$sec])) $secondBuckets[$sec] = ['dispatched' => 0, 'fail' => 0];

        $rate = rx_current_rate($elapsed, $targetRate, $durationSec);
        $tickInterval = 0.02;
        $toAddThisTick = max(1, (int) round($rate * $tickInterval));
        $capacity = $maxConcurrent - count($active);
        $toAdd = max(0, min($toAddThisTick, $capacity));

        for ($i = 0; $i < $toAdd; $i++) {
            $ch = rx_new_handle($targetUrl);
            curl_multi_add_handle($mh, $ch);
            $key = (int) $ch;
            $active[$key] = ['ch' => $ch, 'sec' => $sec, 't0' => microtime(true)];
            $dispatched++;
            $secondBuckets[$sec]['dispatched']++;
        }

        do { $mrc = curl_multi_exec($mh, $stillRunning); } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        curl_multi_select($mh, 0.02);

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = (int) $ch;
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $t0 = isset($active[$key]['t0']) ? $active[$key]['t0'] : microtime(true);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $ok = ($code >= 200 && $code < 400);
            if ($ok) { $success++; } else {
                $fail++;
                $bSec = isset($active[$key]['sec']) ? $active[$key]['sec'] : $sec;
                if (isset($secondBuckets[$bSec])) $secondBuckets[$bSec]['fail']++;
            }
            $recentLog[] = ['code' => $ok ? $code : ($code ?: 'ERR'), 'ms' => $ms, 'idx' => $dispatched];
            if (count($recentLog) > 30) array_shift($recentLog);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($active[$key]);
        }

        $now = microtime(true);
        if ($now - $lastWrite > 0.3) {
            rx_write_status($slot, [
                'slot' => $slot, 'state' => 'running', 'mode' => 'manual',
                'target_url' => $targetUrl, 'target_rate' => $targetRate,
                'duration_sec' => $durationSec, 'elapsed' => round($elapsed, 1),
                'dispatched' => $dispatched, 'success' => $success, 'fail' => $fail,
                'current_rate' => (int) round($rate), 'verdict' => '',
                'recent_log' => $recentLog, 'auto_steps' => [], 'safe_rate' => null,
            ]);
            $lastWrite = $now;
        }
        usleep(5000);
    }

    rx_drain($mh, $active, $success, $fail);
    curl_multi_close($mh);

    ksort($secondBuckets);
    $breakRate = null; $breakFailPct = null;
    foreach ($secondBuckets as $b) {
        if ($b['dispatched'] >= 15 && ($b['fail'] / $b['dispatched']) > 0.15) {
            $breakRate = $b['dispatched'];
            $breakFailPct = round(($b['fail'] / $b['dispatched']) * 100);
            break;
        }
    }

    $failPct = $dispatched > 0 ? round(($fail / $dispatched) * 100) : 0;
    if ($breakRate !== null) {
        $verdict = "Failure rate crossed 15% at roughly {$breakRate} requests/sec (about {$breakFailPct}% failing at that point). That is approximately the ceiling this target held up under.";
    } elseif ($failPct > 15) {
        $verdict = "Failure rate stayed high ({$failPct}%) for the whole run — the target looked stressed from early on, even at this rate.";
    } else {
        $verdict = "Failure rate stayed low ({$failPct}%) across the full run, up to about {$targetRate} requests/sec — the target held up well. Try a higher rate, or run Auto Capacity Check, to find its real ceiling.";
    }

    rx_write_status($slot, [
        'slot' => $slot, 'state' => 'done', 'mode' => 'manual',
        'target_url' => $targetUrl, 'target_rate' => $targetRate,
        'duration_sec' => $durationSec, 'elapsed' => round(microtime(true) - $startTime, 1),
        'dispatched' => $dispatched, 'success' => $success, 'fail' => $fail,
        'current_rate' => 0, 'verdict' => $verdict, 'recent_log' => $recentLog,
        'auto_steps' => [], 'safe_rate' => null,
    ]);
    @unlink($stopFlag);
}

function rx_drain($mh, &$active, &$success, &$fail) {
    $drainStart = microtime(true);
    while (count($active) > 0 && (microtime(true) - $drainStart) < 12) {
        do { $mrc = curl_multi_exec($mh, $stillRunning); } while ($mrc === CURLM_CALL_MULTI_PERFORM);
        curl_multi_select($mh, 0.1);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = (int) $ch;
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code >= 200 && $code < 400) { $success++; } else { $fail++; }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($active[$key]);
        }
    }
    foreach ($active as $a) {
        curl_multi_remove_handle($mh, $a['ch']);
        curl_close($a['ch']);
        $fail++;
    }
}

// ---------------------------------------------------------------------
// Auto Capacity Check engine — steps up the rate and finds the ceiling
// ---------------------------------------------------------------------
function rx_run_auto_engine($slot, $targetUrl) {
    global $RAKIBX_AUTO_STEPS;
    $stepSec = RAKIBX_AUTO_STEP_SEC;
    $stopFlag = rx_stop_path($slot);
    $startTime = microtime(true);
    $lastWrite = 0;
    $dispatched = 0; $success = 0; $fail = 0;
    $recentLog = [];
    $stepResults = [];
    $safeRate = null;
    $breakRate = null;
    $consecutiveBad = 0;

    $mh = curl_multi_init();
    $active = [];

    foreach ($RAKIBX_AUTO_STEPS as $stepRate) {
        if (is_file($stopFlag)) break;

        $stepStart = microtime(true);
        $stepDispatched = 0; $stepSuccess = 0; $stepFail = 0;
        $maxConcurrent = max(20, min(RAKIBX_MAX_CONCURRENCY, (int) ($stepRate / 3)));

        while (true) {
            $stepElapsed = microtime(true) - $stepStart;
            if ($stepElapsed >= $stepSec) break;
            if (is_file($stopFlag)) break;

            $rampSec = 2.0;
            $rate = $stepElapsed < $rampSec
                ? max(10, $stepRate * 0.2) + ($stepRate - max(10, $stepRate * 0.2)) * ($stepElapsed / $rampSec)
                : $stepRate;

            $tickInterval = 0.02;
            $toAddThisTick = max(1, (int) round($rate * $tickInterval));
            $capacity = $maxConcurrent - count($active);
            $toAdd = max(0, min($toAddThisTick, $capacity));

            for ($i = 0; $i < $toAdd; $i++) {
                $ch = rx_new_handle($targetUrl);
                curl_multi_add_handle($mh, $ch);
                $key = (int) $ch;
                $active[$key] = ['ch' => $ch, 't0' => microtime(true)];
                $dispatched++; $stepDispatched++;
            }

            do { $mrc = curl_multi_exec($mh, $stillRunning); } while ($mrc === CURLM_CALL_MULTI_PERFORM);
            curl_multi_select($mh, 0.02);

            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $key = (int) $ch;
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $t0 = isset($active[$key]['t0']) ? $active[$key]['t0'] : microtime(true);
                $ms = (int) round((microtime(true) - $t0) * 1000);
                $ok = ($code >= 200 && $code < 400);
                if ($ok) { $success++; $stepSuccess++; } else { $fail++; $stepFail++; }
                $recentLog[] = ['code' => $ok ? $code : ($code ?: 'ERR'), 'ms' => $ms, 'idx' => $dispatched];
                if (count($recentLog) > 30) array_shift($recentLog);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($active[$key]);
            }

            $now = microtime(true);
            if ($now - $lastWrite > 0.3) {
                rx_write_status($slot, [
                    'slot' => $slot, 'state' => 'running', 'mode' => 'auto',
                    'target_url' => $targetUrl, 'target_rate' => $stepRate,
                    'duration_sec' => count($RAKIBX_AUTO_STEPS) * $stepSec,
                    'elapsed' => round(microtime(true) - $startTime, 1),
                    'dispatched' => $dispatched, 'success' => $success, 'fail' => $fail,
                    'current_rate' => (int) round($rate), 'verdict' => '',
                    'recent_log' => $recentLog, 'auto_steps' => $stepResults, 'safe_rate' => $safeRate,
                ]);
                $lastWrite = $now;
            }
            usleep(5000);
        }

        $stepFailPct = $stepDispatched > 0 ? round(($stepFail / $stepDispatched) * 100, 1) : 0;
        $stepResults[] = ['rate' => $stepRate, 'dispatched' => $stepDispatched, 'fail_pct' => $stepFailPct];

        if ($stepFailPct > 20) {
            $consecutiveBad++;
            if ($breakRate === null) $breakRate = $stepRate;
        } else {
            $consecutiveBad = 0;
            $safeRate = $stepRate;
        }

        if ($consecutiveBad >= 2) {
            break; // stop escalating once it clearly can't keep up
        }
    }

    rx_drain($mh, $active, $success, $fail);
    curl_multi_close($mh);

    if ($safeRate !== null && $breakRate !== null) {
        $verdict = "Your target handled traffic reliably up to about {$safeRate} requests/sec. Past that, failures climbed sharply starting around {$breakRate} requests/sec. Treat {$safeRate}/sec as a realistic safe capacity estimate.";
    } elseif ($safeRate !== null) {
        $verdict = "Your target stayed reliable through every step tested, up to " . end($RAKIBX_AUTO_STEPS) . " requests/sec, without a clear breaking point. It can likely handle at least that much.";
    } else {
        $verdict = "Your target struggled even at the lowest tested rate (100 requests/sec). It may need a faster host, more resources, or caching before it can absorb meaningful load.";
    }

    rx_write_status($slot, [
        'slot' => $slot, 'state' => 'done', 'mode' => 'auto',
        'target_url' => $targetUrl, 'target_rate' => 0,
        'duration_sec' => count($RAKIBX_AUTO_STEPS) * $stepSec,
        'elapsed' => round(microtime(true) - $startTime, 1),
        'dispatched' => $dispatched, 'success' => $success, 'fail' => $fail,
        'current_rate' => 0, 'verdict' => $verdict, 'recent_log' => $recentLog,
        'auto_steps' => $stepResults, 'safe_rate' => $safeRate,
    ]);
    @unlink($stopFlag);
}

// ---------------------------------------------------------------------
// HTML page
// ---------------------------------------------------------------------
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Rakib X</title>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&family=Manrope:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:#05070d;
    --fg:#f1f3fb; --muted:#9aa3bd;
    --accent:#7c8dff; --accent-2:#36e2d6; --accent-3:#ff6fd8;
    --success:#3ddc84; --danger:#ff5c72; --warning:#ffc15e;
    --glass-bg:rgba(255,255,255,0.055);
    --glass-bg-2:rgba(255,255,255,0.085);
    --glass-border:rgba(255,255,255,0.14);
    --glass-highlight:rgba(255,255,255,0.35);
    --font-display:'Sora', system-ui, sans-serif;
    --font-body:'Manrope', system-ui, sans-serif;
    --font-mono:'JetBrains Mono', ui-monospace, monospace;
    color-scheme: dark;
  }
  *{ box-sizing:border-box; }
  html,body{ background:var(--bg); margin:0; }
  body{
    color:var(--fg); font-family:var(--font-body);
    padding:0 max(16px, env(safe-area-inset-left)) 44px max(16px, env(safe-area-inset-right));
    overflow-x:hidden;
    min-height:100vh;
  }
  @media (prefers-reduced-motion: no-preference){
    .blob{ animation: drift 22s ease-in-out infinite; }
  }
  @keyframes drift{
    0%,100%{ transform:translate(0,0) scale(1); }
    50%{ transform:translate(-4%,3%) scale(1.08); }
  }
  .bg-blobs{ position:fixed; inset:0; z-index:-1; overflow:hidden; }
  .blob{ position:absolute; border-radius:50%; filter:blur(70px); opacity:.55; }
  .blob.b1{ width:420px; height:420px; top:-140px; left:-100px; background:radial-gradient(circle, var(--accent), transparent 70%); }
  .blob.b2{ width:360px; height:360px; top:10%; right:-120px; background:radial-gradient(circle, var(--accent-2), transparent 70%); animation-delay:-7s; }
  .blob.b3{ width:380px; height:380px; bottom:-120px; left:20%; background:radial-gradient(circle, var(--accent-3), transparent 70%); animation-delay:-14s; }

  h1,h2{ font-family:var(--font-display); margin:0; }
  .mono{ font-family:var(--font-mono); font-variant-numeric:tabular-nums; }
  #app{ max-width:540px; margin:0 auto; padding-top:20px; display:flex; flex-direction:column; gap:16px; }

  .glass{
    position:relative;
    background:var(--glass-bg);
    border:1px solid var(--glass-border);
    border-radius:20px;
    backdrop-filter: blur(22px) saturate(160%);
    -webkit-backdrop-filter: blur(22px) saturate(160%);
    box-shadow: 0 1px 0 rgba(255,255,255,.08) inset, 0 20px 50px -28px rgba(0,0,0,.7);
  }
  .glass::before{
    content:""; position:absolute; top:0; left:12%; right:12%; height:1px;
    background:linear-gradient(90deg, transparent, var(--glass-highlight), transparent);
    border-radius:1px;
  }

  header.top{ display:flex; align-items:center; gap:12px; padding:14px 16px; }
  .glyph{
    width:36px; height:36px; border-radius:11px; flex:none;
    background:linear-gradient(135deg, var(--accent), var(--accent-2));
    display:flex; align-items:center; justify-content:center;
    font-family:var(--font-display); font-weight:800; font-size:14px; color:#04060c;
    box-shadow:0 8px 20px rgba(124,141,255,.35);
  }
  header.top h1{ font-size:18px; font-weight:800; }
  header.top .byline{ font-family:var(--font-mono); font-size:10.5px; color:var(--muted); letter-spacing:.07em; }

  .warn-banner{ font-size:11.5px; color:var(--warning); padding:12px 14px; line-height:1.5; }

  .tabs{ display:flex; gap:8px; padding:6px; }
  .tab{ flex:1; text-align:center; padding:10px 6px; border-radius:14px; cursor:pointer; font-family:var(--font-mono); font-size:11.5px; color:var(--muted); }
  .tab.active{ color:#04060c; background:linear-gradient(135deg,var(--accent),var(--accent-2)); }
  .tab .badge{ display:block; font-size:9px; margin-top:2px; letter-spacing:.06em; }

  .panel{ padding:18px; display:flex; flex-direction:column; gap:13px; }
  .field-label{ font-family:var(--font-mono); font-size:10.5px; color:var(--muted); letter-spacing:.07em; text-transform:uppercase; margin-bottom:5px; display:block; }

  input[type=text], input[type=number]{
    width:100%; background:rgba(255,255,255,.05); border:1px solid var(--glass-border); color:var(--fg);
    font-family:var(--font-mono); font-size:13.5px; padding:11px 12px; border-radius:12px; outline:none;
    backdrop-filter: blur(6px);
  }
  input::placeholder{ color:#6c7591; }
  input:focus{ border-color:var(--accent); box-shadow:0 0 0 3px rgba(124,141,255,.18); }

  .dur-presets{ display:flex; flex-wrap:wrap; gap:6px; }
  .dur-chip{ font-family:var(--font-mono); font-size:11px; padding:7px 11px; border-radius:10px; background:rgba(255,255,255,.05); border:1px solid var(--glass-border); color:var(--muted); cursor:pointer; }
  .dur-chip.active{ color:#04060c; background:linear-gradient(135deg,var(--accent),var(--accent-2)); border-color:transparent; }
  .custom-dur{ display:flex; align-items:center; gap:8px; margin-top:8px; }
  .custom-dur input{ flex:1; }
  .unit{ font-family:var(--font-mono); font-size:11px; color:var(--muted); }
  .unlimited-toggle{ display:flex; align-items:center; gap:8px; margin-top:8px; cursor:pointer; font-family:var(--font-mono); font-size:11px; color:var(--muted); }
  .unlimited-toggle input{ accent-color:var(--accent-2); width:14px; height:14px; }

  label.confirm{ display:flex; gap:8px; align-items:flex-start; font-size:11.5px; color:var(--muted); line-height:1.4; cursor:pointer; }
  label.confirm input{ margin-top:2px; accent-color:var(--accent); width:14px; height:14px; flex:none; }

  .btn-row{ display:flex; gap:8px; }
  button.go, button.stop{ flex:1; font-family:var(--font-display); font-weight:700; font-size:13px; border:none; border-radius:13px; padding:12px 14px; cursor:pointer; }
  button.go{ background:linear-gradient(135deg,var(--accent),var(--accent-2)); color:#04060c; box-shadow:0 10px 24px -10px rgba(124,141,255,.55); }
  button.go:disabled, button.stop:disabled{ opacity:.35; cursor:not-allowed; box-shadow:none; }
  button.stop{ background:rgba(255,92,114,.1); color:var(--danger); border:1px solid rgba(255,92,114,.4); }

  .state-row{ display:flex; align-items:center; justify-content:space-between; }
  .state-pill{ font-family:var(--font-mono); font-size:10.5px; padding:4px 10px; border-radius:999px; border:1px solid var(--glass-border); color:var(--muted); background:rgba(255,255,255,.04); }
  .state-pill.running{ color:var(--success); border-color:rgba(61,220,132,.4); }
  .state-pill.done{ color:var(--accent-2); border-color:rgba(54,226,214,.4); }
  .state-pill.stalled{ color:var(--warning); border-color:rgba(255,193,94,.5); }

  #settingsBtn{
    margin-left:auto; width:36px; height:36px; border-radius:11px; flex:none;
    background:rgba(255,255,255,.06); border:1px solid var(--glass-border); color:var(--muted);
    font-size:16px; cursor:pointer;
  }
  #settingsBtn:hover{ color:var(--fg); }

  #settingsOverlay{
    display:none; position:fixed; inset:0; background:rgba(4,6,12,.72);
    backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);
    align-items:flex-end; justify-content:center; z-index:90;
  }
  #settingsOverlay.show{ display:flex; }
  #settingsSheet{
    width:100%; max-width:540px; border-radius:22px 22px 0 0;
    padding:18px max(18px, env(safe-area-inset-left)) max(22px, calc(env(safe-area-inset-bottom) + 18px)) max(18px, env(safe-area-inset-right));
    max-height:80vh; overflow-y:auto;
  }
  .settings-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
  .settings-head h2{ font-size:16px; font-weight:800; }
  #closeSettings{ width:30px; height:30px; border-radius:9px; background:rgba(255,255,255,.06); border:1px solid var(--glass-border); color:var(--muted); font-size:13px; cursor:pointer; }
  .reset-list{ display:flex; flex-direction:column; gap:8px; margin-top:12px; }
  .reset-list button{ width:100%; }

  .stalled-banner{ padding:13px 14px; display:none; }
  .stalled-banner .msg{ font-size:12px; color:var(--warning); line-height:1.5; margin-bottom:9px; }
  .stalled-banner button{ width:100%; }

  .stats{ display:grid; grid-template-columns:repeat(2,1fr); gap:8px; }
  .stat{ background:rgba(255,255,255,.04); border:1px solid var(--glass-border); border-radius:12px; padding:9px 11px; }
  .stat .k{ font-family:var(--font-mono); font-size:9.5px; color:var(--muted); letter-spacing:.06em; text-transform:uppercase; }
  .stat .v{ font-family:var(--font-mono); font-size:17px; font-weight:700; margin-top:2px; }
  .stat .v.success{ color:var(--success); }
  .stat .v.danger{ color:var(--danger); }
  .stat .v.accent{ color:var(--accent-2); }

  .progress{ height:4px; border-radius:4px; background:rgba(255,255,255,.07); overflow:hidden; }
  .progress-fill{ height:100%; width:0%; background:linear-gradient(90deg,var(--accent),var(--accent-2),var(--accent-3)); }

  .log{ background:rgba(0,0,0,.35); border:1px solid var(--glass-border); border-radius:12px; height:150px; overflow-y:auto; padding:7px 9px; font-family:var(--font-mono); font-size:10.5px; line-height:1.55; }
  .log .empty{ color:var(--muted); }
  .log-line{ display:flex; gap:6px; color:var(--muted); white-space:nowrap; }
  .log-line .code{ flex:none; font-weight:700; width:34px; }
  .log-line .code.ok{ color:var(--success); }
  .log-line .code.fail{ color:var(--danger); }

  .verdict{ font-size:13px; line-height:1.65; padding:14px; border-radius:14px; background:rgba(124,141,255,.08); border:1px solid rgba(124,141,255,.25); }
  .safe-rate-display{ text-align:center; padding:16px; }
  .safe-rate-display .label{ font-family:var(--font-mono); font-size:10.5px; color:var(--muted); letter-spacing:.1em; text-transform:uppercase; }
  .safe-rate-display .num{ font-family:var(--font-display); font-weight:800; font-size:42px; background:linear-gradient(135deg,var(--accent),var(--accent-2)); -webkit-background-clip:text; background-clip:text; color:transparent; }
  .safe-rate-display .unit2{ font-family:var(--font-mono); font-size:12px; color:var(--muted); }

  .step-grid{ display:flex; flex-direction:column; gap:5px; }
  .step-row{ display:flex; align-items:center; gap:8px; font-family:var(--font-mono); font-size:11px; }
  .step-row .rate{ width:70px; color:var(--fg); flex:none; }
  .step-bar-track{ flex:1; height:6px; border-radius:3px; background:rgba(255,255,255,.07); overflow:hidden; min-width:0; }
  .step-bar-fill{ height:100%; background:var(--success); }
  .step-bar-fill.bad{ background:var(--danger); }
  .step-pct{ width:44px; text-align:right; color:var(--muted); flex:none; }

  .auto-note{ font-size:11.5px; color:var(--muted); line-height:1.5; }

  footer.sig{ text-align:center; font-family:var(--font-mono); font-size:11px; color:var(--muted); padding-top:4px; }
</style>
</head>
<body>
<div class="bg-blobs"><div class="blob b1"></div><div class="blob b2"></div><div class="blob b3"></div></div>

<div id="app">
  <header class="top glass">
    <div class="glyph">RX</div>
    <div>
      <h1>Rakib X</h1>
      <div class="byline">SERVER-SIDE LOAD SYSTEM</div>
    </div>
  </header>

  <div class="glass warn-banner">Only test a website you own or have explicit permission to test. This runs real traffic from this server.</div>

  <div class="glass tabs">
    <div class="tab active" data-slot="A" id="tabA">SLOT A<span class="badge" id="badgeA">IDLE</span></div>
    <div class="tab" data-slot="B" id="tabB">SLOT B<span class="badge" id="badgeB">IDLE</span></div>
    <div class="tab" data-slot="C" id="tabC">AUTO CHECK<span class="badge" id="badgeC">IDLE</span></div>
    <div class="tab" data-slot="S" id="tabS">SETTINGS</div>
  </div>

  <div class="glass stalled-banner" id="stalledBanner">
    <div class="msg">No update from this slot's process in a while &mdash; your host likely killed it due to its own resource limits. Stop may not respond. Force reset to clear it and launch again.</div>
    <button class="stop" id="forceResetBtn">FORCE RESET THIS SLOT</button>
  </div>

  <div class="glass panel" id="manualPanel">
    <div class="state-row">
      <span class="field-label" style="margin:0;">Target URL</span>
      <span class="state-pill" id="statePill">IDLE</span>
    </div>
    <input type="text" id="urlInput" inputmode="url" placeholder="https://your-own-site.com/" />

    <div>
      <span class="field-label">Target Rate (requests/sec, any number)</span>
      <input type="number" id="rateInput" min="1" placeholder="e.g. 4000, 6000, 10000" value="2000" />
    </div>

    <div>
      <span class="field-label">Duration</span>
      <div class="dur-presets" id="durPresets">
        <div class="dur-chip" data-sec="60">1m</div>
        <div class="dur-chip active" data-sec="300">5m</div>
        <div class="dur-chip" data-sec="900">15m</div>
        <div class="dur-chip" data-sec="1800">30m</div>
        <div class="dur-chip" data-sec="3600">60m</div>
      </div>
      <div class="custom-dur">
        <input type="number" id="customDur" min="1" placeholder="Custom minutes" />
        <span class="unit">min</span>
      </div>
      <label class="unlimited-toggle">
        <input type="checkbox" id="unlimited" />
        <span>Unlimited &mdash; run until stopped (safety cap: 6 hours)</span>
      </label>
    </div>

    <label class="confirm">
      <input type="checkbox" id="confirmCheck" />
      <span>This is my own website, or I have permission to test it.</span>
    </label>

    <div class="btn-row">
      <button class="go" id="startBtn">LAUNCH</button>
      <button class="stop" id="stopBtn" disabled>STOP</button>
    </div>

    <div class="progress"><div class="progress-fill" id="progressFill"></div></div>

    <div class="stats">
      <div class="stat"><div class="k">Dispatched</div><div class="v mono" id="statDispatched">0</div></div>
      <div class="stat"><div class="k">Success</div><div class="v mono success" id="statSuccess">0</div></div>
      <div class="stat"><div class="k">Fail</div><div class="v mono danger" id="statFail">0</div></div>
      <div class="stat"><div class="k">Live Rate</div><div class="v mono accent" id="statRate">0/s</div></div>
    </div>

    <div class="log" id="logPanel"><div class="empty">// idle</div></div>
    <div class="verdict" id="verdictBox" style="display:none;"></div>
  </div>

  <div class="glass panel" id="autoPanel" style="display:none;">
    <div class="state-row">
      <span class="field-label" style="margin:0;">Target URL</span>
      <span class="state-pill" id="autoStatePill">IDLE</span>
    </div>
    <input type="text" id="autoUrlInput" inputmode="url" placeholder="https://your-own-site.com/" />
    <div class="auto-note">Steps from 100 up to 10,000 requests/sec, ~10 seconds each. Stops automatically once failures climb, and tells you the safe capacity &mdash; no need to guess a rate yourself.</div>

    <label class="confirm">
      <input type="checkbox" id="autoConfirmCheck" />
      <span>This is my own website, or I have permission to test it.</span>
    </label>

    <div class="btn-row">
      <button class="go" id="autoStartBtn">RUN CAPACITY CHECK</button>
      <button class="stop" id="autoStopBtn" disabled>STOP</button>
    </div>

    <div class="progress"><div class="progress-fill" id="autoProgressFill"></div></div>

    <div class="step-grid" id="stepGrid"></div>

    <div class="safe-rate-display" id="safeRateDisplay" style="display:none;">
      <div class="label">Estimated Safe Capacity</div>
      <div class="num" id="safeRateNum">&mdash;</div>
      <div class="unit2">requests / second</div>
    </div>

    <div class="verdict" id="autoVerdictBox" style="display:none;"></div>
  </div>

  <div class="glass panel" id="settingsPanel" style="display:none;">
    <div class="field-label" style="margin:0;">System Reset</div>
    <div class="auto-note">Resetting a slot wipes its saved status completely and puts it back to a fresh, untouched state &mdash; exactly as if you had never run a test there. Use this when a run looks stuck: Stop not responding, no new log lines, counters frozen. That almost always means your host killed the background process on its own; Reset just clears the leftover record of it. It will not interrupt a run that is genuinely still healthy.</div>
    <div class="reset-list">
      <button class="stop" id="resetA">RESET SLOT A</button>
      <button class="stop" id="resetB">RESET SLOT B</button>
      <button class="stop" id="resetC">RESET AUTO CHECK</button>
      <button class="stop" id="resetAll" style="border-color:var(--danger); color:var(--danger);">RESET EVERYTHING</button>
    </div>
  </div>

  <footer class="sig">Rakib X &mdash; runs on this server, independent of your browser</footer>
</div>

<script>
(function(){
  "use strict";

  var SCRIPT_URL = window.location.pathname;
  var activeTab = 'A';
  var slotData = { A:{}, B:{}, C:{} };

  var tabA = document.getElementById('tabA');
  var tabB = document.getElementById('tabB');
  var tabC = document.getElementById('tabC');
  var tabS = document.getElementById('tabS');
  var badgeA = document.getElementById('badgeA');
  var badgeB = document.getElementById('badgeB');
  var badgeC = document.getElementById('badgeC');
  var manualPanel = document.getElementById('manualPanel');
  var autoPanel = document.getElementById('autoPanel');
  var settingsPanel = document.getElementById('settingsPanel');
  var stalledBanner = document.getElementById('stalledBanner');
  var forceResetBtn = document.getElementById('forceResetBtn');

  // manual panel elements
  var urlInput = document.getElementById('urlInput');
  var rateInput = document.getElementById('rateInput');
  var durPresets = document.getElementById('durPresets');
  var customDur = document.getElementById('customDur');
  var unlimited = document.getElementById('unlimited');
  var confirmCheck = document.getElementById('confirmCheck');
  var startBtn = document.getElementById('startBtn');
  var stopBtn = document.getElementById('stopBtn');
  var statePill = document.getElementById('statePill');
  var progressFill = document.getElementById('progressFill');
  var statDispatched = document.getElementById('statDispatched');
  var statSuccess = document.getElementById('statSuccess');
  var statFail = document.getElementById('statFail');
  var statRate = document.getElementById('statRate');
  var logPanel = document.getElementById('logPanel');
  var verdictBox = document.getElementById('verdictBox');

  // auto panel elements
  var autoUrlInput = document.getElementById('autoUrlInput');
  var autoConfirmCheck = document.getElementById('autoConfirmCheck');
  var autoStartBtn = document.getElementById('autoStartBtn');
  var autoStopBtn = document.getElementById('autoStopBtn');
  var autoStatePill = document.getElementById('autoStatePill');
  var autoProgressFill = document.getElementById('autoProgressFill');
  var stepGrid = document.getElementById('stepGrid');
  var safeRateDisplay = document.getElementById('safeRateDisplay');
  var safeRateNum = document.getElementById('safeRateNum');
  var autoVerdictBox = document.getElementById('autoVerdictBox');

  function selectedDurationSec(){
    if (unlimited.checked) return 0;
    if (customDur.value) return Math.max(1, parseFloat(customDur.value)) * 60;
    var active = durPresets.querySelector('.dur-chip.active');
    return active ? parseInt(active.getAttribute('data-sec'), 10) : 300;
  }

  Array.prototype.forEach.call(durPresets.children, function(chip){
    chip.addEventListener('click', function(){
      Array.prototype.forEach.call(durPresets.children, function(c){ c.classList.remove('active'); });
      chip.classList.add('active');
      customDur.value = '';
      unlimited.checked = false;
    });
  });
  customDur.addEventListener('input', function(){
    if (customDur.value){
      Array.prototype.forEach.call(durPresets.children, function(c){ c.classList.remove('active'); });
      unlimited.checked = false;
    }
  });
  unlimited.addEventListener('change', function(){
    if (unlimited.checked){
      Array.prototype.forEach.call(durPresets.children, function(c){ c.classList.remove('active'); });
      customDur.value = '';
    }
  });

  function switchTab(slot){
    activeTab = slot;
    tabA.classList.toggle('active', slot==='A');
    tabB.classList.toggle('active', slot==='B');
    tabC.classList.toggle('active', slot==='C');
    tabS.classList.toggle('active', slot==='S');
    manualPanel.style.display = (slot==='A' || slot==='B') ? 'flex' : 'none';
    autoPanel.style.display = (slot==='C') ? 'flex' : 'none';
    settingsPanel.style.display = (slot==='S') ? 'flex' : 'none';
    if (slot==='A' || slot==='B') renderManual(slot);
    else if (slot==='C') renderAuto();
    updateStalledBanner();
  }
  tabA.addEventListener('click', function(){ switchTab('A'); });
  tabB.addEventListener('click', function(){ switchTab('B'); });
  tabC.addEventListener('click', function(){ switchTab('C'); });
  tabS.addEventListener('click', function(){ switchTab('S'); });

  function updateStalledBanner(){
    var s = slotData[activeTab] || {};
    stalledBanner.style.display = (s.stalled && activeTab !== 'S') ? 'block' : 'none';
  }

  function resetSlot(slot){
    fetch(SCRIPT_URL + '?action=reset&slot=' + slot, { cache:'no-store' })
      .then(function(){
        if (slot === 'ALL'){
          slotData.A = { state:'idle' }; slotData.B = { state:'idle' }; slotData.C = { state:'idle' };
        } else {
          slotData[slot] = { state:'idle' };
        }
        pollStatus('A'); pollStatus('B'); pollStatus('C');
        if (activeTab==='C') renderAuto(); else if (activeTab==='A' || activeTab==='B') renderManual(activeTab);
        updateStalledBanner();
      });
  }

  document.getElementById('resetA').addEventListener('click', function(){ resetSlot('A'); });
  document.getElementById('resetB').addEventListener('click', function(){ resetSlot('B'); });
  document.getElementById('resetC').addEventListener('click', function(){ resetSlot('C'); });
  document.getElementById('resetAll').addEventListener('click', function(){ resetSlot('ALL'); });
  forceResetBtn.addEventListener('click', function(){ resetSlot(activeTab); });

  function syncStartEnabled(){
    var s = slotData[activeTab] || {};
    startBtn.disabled = !(urlInput.value.trim().length > 0 && confirmCheck.checked) || s.state === 'running';
  }
  urlInput.addEventListener('input', syncStartEnabled);
  confirmCheck.addEventListener('change', syncStartEnabled);

  function syncAutoStartEnabled(){
    var s = slotData.C || {};
    autoStartBtn.disabled = !(autoUrlInput.value.trim().length > 0 && autoConfirmCheck.checked) || s.state === 'running';
  }
  autoUrlInput.addEventListener('input', syncAutoStartEnabled);
  autoConfirmCheck.addEventListener('change', syncAutoStartEnabled);

  function renderManual(slot){
    var s = slotData[slot] || {};
    var isRunning = s.state === 'running' && !s.stalled;
    statePill.textContent = s.stalled ? 'STALLED' : (s.state || 'idle').toUpperCase();
    statePill.className = 'state-pill ' + (s.stalled ? 'stalled' : (s.state === 'running' ? 'running' : s.state === 'done' ? 'done' : ''));

    startBtn.disabled = isRunning || !(urlInput.value.trim().length>0 && confirmCheck.checked);
    stopBtn.disabled = !(s.state === 'running');
    updateStalledBanner();

    var pct = (s.duration_sec && s.duration_sec > 0) ? Math.min(100, (s.elapsed / s.duration_sec) * 100) : (isRunning ? 100 : 0);
    progressFill.style.width = pct + '%';

    statDispatched.textContent = s.dispatched || 0;
    statSuccess.textContent = s.success || 0;
    statFail.textContent = s.fail || 0;
    statRate.textContent = (s.current_rate || 0) + '/s';

    if (s.recent_log && s.recent_log.length){
      logPanel.innerHTML = '';
      var frag = document.createDocumentFragment();
      s.recent_log.slice(-30).forEach(function(item){
        var ok = item.code >= 200 && item.code < 400;
        var line = document.createElement('div');
        line.className = 'log-line';
        line.innerHTML = '<span class="code ' + (ok?'ok':'fail') + '">' + item.code + '</span><span>' + item.ms + 'ms &nbsp; #' + item.idx + '</span>';
        frag.appendChild(line);
      });
      logPanel.appendChild(frag);
      logPanel.scrollTop = logPanel.scrollHeight;
    } else if (!isRunning && s.state !== 'done') {
      logPanel.innerHTML = '<div class="empty">// idle</div>';
    }

    if (s.state === 'done' && s.verdict){
      verdictBox.style.display = 'block';
      verdictBox.textContent = s.verdict;
    } else {
      verdictBox.style.display = 'none';
    }

    if (s.target_url !== undefined && document.activeElement !== urlInput) urlInput.value = s.target_url || '';
  }

  function renderAuto(){
    var s = slotData.C || {};
    var isRunning = s.state === 'running' && !s.stalled;
    autoStatePill.textContent = s.stalled ? 'STALLED' : (s.state || 'idle').toUpperCase();
    autoStatePill.className = 'state-pill ' + (s.stalled ? 'stalled' : (s.state === 'running' ? 'running' : s.state === 'done' ? 'done' : ''));

    autoStartBtn.disabled = isRunning || !(autoUrlInput.value.trim().length>0 && autoConfirmCheck.checked);
    autoStopBtn.disabled = !(s.state === 'running');
    updateStalledBanner();

    var pct = (s.duration_sec && s.duration_sec > 0) ? Math.min(100, (s.elapsed / s.duration_sec) * 100) : 0;
    autoProgressFill.style.width = pct + '%';

    var steps = s.auto_steps || [];
    stepGrid.innerHTML = '';
    steps.forEach(function(step){
      var row = document.createElement('div');
      row.className = 'step-row';
      var bad = step.fail_pct > 20;
      row.innerHTML =
        '<span class="rate">' + step.rate + '/s</span>' +
        '<span class="step-bar-track"><span class="step-bar-fill' + (bad?' bad':'') + '" style="width:' + Math.min(100, step.fail_pct) + '%"></span></span>' +
        '<span class="step-pct">' + step.fail_pct + '%</span>';
      stepGrid.appendChild(row);
    });

    if (s.safe_rate){
      safeRateDisplay.style.display = 'block';
      safeRateNum.textContent = s.safe_rate.toLocaleString();
    } else {
      safeRateDisplay.style.display = 'none';
    }

    if (s.state === 'done' && s.verdict){
      autoVerdictBox.style.display = 'block';
      autoVerdictBox.textContent = s.verdict;
    } else {
      autoVerdictBox.style.display = 'none';
    }

    if (s.target_url !== undefined && document.activeElement !== autoUrlInput) autoUrlInput.value = s.target_url || '';
  }

  var STALE_SEC = 8;

  function pollStatus(slot){
    fetch(SCRIPT_URL + '?action=status&slot=' + slot, { cache:'no-store' })
      .then(function(r){ return r.json(); })
      .then(function(data){
        var ageSec = data.updated_at ? (Date.now()/1000 - data.updated_at) : 0;
        data.stalled = (data.state === 'running' && ageSec > STALE_SEC);
        slotData[slot] = data;
        var badge = slot === 'A' ? badgeA : slot === 'B' ? badgeB : badgeC;
        badge.textContent = data.stalled ? 'STALLED' : (data.state || 'idle').toUpperCase();
        if (slot === activeTab){
          if (slot === 'C') renderAuto(); else renderManual(slot);
        }
      })
      .catch(function(){});
  }

  setInterval(function(){ pollStatus('A'); pollStatus('B'); pollStatus('C'); }, 1000);
  pollStatus('A'); pollStatus('B'); pollStatus('C');

  startBtn.addEventListener('click', function(){
    var url = urlInput.value.trim();
    var rate = Math.max(1, parseInt(rateInput.value, 10) || 2000);
    var durSec = selectedDurationSec();

    var form = new URLSearchParams();
    form.set('slot', activeTab);
    form.set('mode', 'manual');
    form.set('url', url);
    form.set('rate', rate);
    form.set('duration', durSec);
    form.set('confirm', confirmCheck.checked ? '1' : '0');

    startBtn.disabled = true;
    fetch(SCRIPT_URL + '?action=start', { method:'POST', body: form })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res.ok){ alert(res.error || 'Could not start.'); startBtn.disabled = false; return; }
        pollStatus(activeTab);
      })
      .catch(function(){ startBtn.disabled = false; });
  });

  stopBtn.addEventListener('click', function(){
    fetch(SCRIPT_URL + '?action=stop&slot=' + activeTab, { cache:'no-store' }).then(function(){ pollStatus(activeTab); });
  });

  autoStartBtn.addEventListener('click', function(){
    var url = autoUrlInput.value.trim();
    var form = new URLSearchParams();
    form.set('slot', 'C');
    form.set('mode', 'auto');
    form.set('url', url);
    form.set('confirm', autoConfirmCheck.checked ? '1' : '0');

    autoStartBtn.disabled = true;
    fetch(SCRIPT_URL + '?action=start', { method:'POST', body: form })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res.ok){ alert(res.error || 'Could not start.'); autoStartBtn.disabled = false; return; }
        pollStatus('C');
      })
      .catch(function(){ autoStartBtn.disabled = false; });
  });

  autoStopBtn.addEventListener('click', function(){
    fetch(SCRIPT_URL + '?action=stop&slot=C', { cache:'no-store' }).then(function(){ pollStatus('C'); });
  });

  renderManual('A');
})();
</script>
</body>
</html>
