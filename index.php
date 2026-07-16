<?php
/**
 * Kevinz Dowloader — single-file PHP app
 * Redesign pass: fixes broken "xem thử / nghe thử" playback, hardens the
 * backend (timeouts, real error surfaces, streaming proxy for hotlink-
 * protected CDNs), and reworks the frontend for reliability + a11y while
 * keeping the Windows 98 (98.css) identity intact.
 *
 * Root cause of the "can't preview" bug: TikTok / Instagram / Pinterest /
 * Spotify CDNs check the Referer header and reject cross-site hotlinking,
 * so a <video src="their-cdn-url"> loaded straight from this page silently
 * fails. Fix: stream media through our own /?proxy=... endpoint with the
 * correct Referer/User-Agent set server-side, with Range support so
 * seeking + <audio>/<video> both work. Every play/download button now
 * goes through the proxy instead of hot-linking the raw CDN URL directly.
 */

error_reporting(0);
ini_set('display_errors', '0');

// ---------------------------------------------------------------------
// STREAMING PROXY (GET) — fixes playback + makes downloads same-origin
// ---------------------------------------------------------------------
if (isset($_GET['proxy'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    set_time_limit(0);

    $raw = strtr((string)$_GET['proxy'], '-_', '+/');
    $target = base64_decode($raw, true);

    if (!$target || !preg_match('#^https?://#i', $target)) {
        http_response_code(400);
        echo 'Bad proxy target';
        exit;
    }

    $host = parse_url($target, PHP_URL_HOST);
    $scheme = parse_url($target, PHP_URL_SCHEME);
    $forceDownload = isset($_GET['dl']);

    $ch = curl_init($target);
    $reqHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
        'Referer: ' . $scheme . '://' . $host . '/',
        'Accept: */*',
    ];
    if (!empty($_SERVER['HTTP_RANGE'])) {
        $reqHeaders[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    }

    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADERFUNCTION => function ($curl, $header) use ($forceDownload) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) < 2) return $len;
            $name = strtolower(trim($parts[0]));
            $passthrough = ['content-type', 'content-length', 'content-range', 'accept-ranges', 'last-modified', 'etag'];
            if (in_array($name, $passthrough, true)) {
                header(trim($header), false);
            }
            return $len;
        },
        CURLOPT_WRITEFUNCTION => function ($curl, $data) {
            echo $data;
            @flush();
            return strlen($data);
        },
    ]);

    if ($forceDownload) {
        $fname = basename(parse_url($target, PHP_URL_PATH)) ?: 'download';
        if (strlen($fname) > 80 || !preg_match('/\.[a-z0-9]{2,5}$/i', $fname)) {
            $fname = 'kevinz-download';
        }
        header('Content-Disposition: attachment; filename="' . $fname . '"');
    }

    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($code === 0) {
        http_response_code(502);
        echo 'Upstream unreachable: ' . htmlspecialchars($err);
        exit;
    }
    http_response_code($code === 206 ? 206 : ($code ?: 200));
    exit;
}

// ---------------------------------------------------------------------
// JSON API (POST)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $req = json_decode(file_get_contents('php://input'), true);
    $u = trim($req['u'] ?? '');
    $s = $req['s'] ?? '';
    $res = ['ok' => false, 'author' => 'AnhzTuan', 'msg' => 'Error', 'data' => null];

    if (empty($u)) {
        $res['msg'] = 'Vui lòng nhập Link hoặc Từ khóa!';
        echo json_encode($res); exit;
    }

    function fG($url, $hd = []) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($hd) curl_setopt($ch, CURLOPT_HTTPHEADER, $hd);
        $r = curl_exec($ch);
        curl_close($ch);
        return $r;
    }

    function fP($url, $b, $hd = []) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($b),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        $h = ['Content-Type: application/json'];
        if ($hd) $h = array_merge($h, $hd);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        $r = curl_exec($ch);
        curl_close($ch);
        return json_decode($r, true);
    }

    switch ($s) {
        // --- TIKTOK APIs ---
        case 'tk1':
            $a = fP('https://puruboy-api.vercel.app/api/downloader/snaptik', ['url' => $u]);
            if (isset($a['success']) && $a['success']) {
                $dls = [];
                foreach ($a['result']['download_links'] ?? [] as $link) {
                    $dls[] = ['type' => $link['type'], 'url' => $link['url'], 'format' => 'video'];
                }
                $res['ok'] = true;
                $res['data'] = ['type' => 'single', 'title' => $a['result']['video_info']['title'] ?? '', 'author' => $a['result']['video_info']['author'] ?? '', 'thumb' => $a['result']['video_info']['thumbnail'] ?? '', 'dls' => $dls];
            }
            break;
        case 'tk2':
            $a = fP('https://puruboy-api.vercel.app/api/downloader/tiktok', ['url' => $u]);
            if (isset($a['success']) && $a['success']) {
                $d = $a['result']['detail'];
                $dls = [];
                if (!empty($d['play_url'])) $dls[] = ['type' => 'Video (Logo)', 'url' => $d['play_url'], 'format' => 'video'];
                if (!empty($d['download_url'])) $dls[] = ['type' => 'Video (No Logo)', 'url' => $d['download_url'], 'format' => 'video'];
                if (!empty($d['music_info']['play'])) $dls[] = ['type' => 'Audio (MP3)', 'url' => $d['music_info']['play'], 'format' => 'audio'];
                $res['ok'] = true;
                $res['data'] = ['type' => 'single', 'title' => $d['title'] ?? '', 'author' => $d['author']['nickname'] ?? '', 'thumb' => $d['cover'] ?? '', 'dls' => $dls];
            }
            break;
        case 'tk3':
            $a = fP('https://puruboy-api.vercel.app/api/downloader/tiktok-v2', ['url' => $u]);
            if (isset($a['success']) && $a['success']) {
                $dls = [];
                foreach ($a['result']['downloads'] ?? [] as $link) {
                    $fmt = strpos(strtolower($link['type']), 'mp3') !== false ? 'audio' : 'video';
                    $dls[] = ['type' => $link['type'], 'url' => $link['url'], 'format' => $fmt];
                }
                $res['ok'] = true;
                $res['data'] = ['type' => 'single', 'title' => $a['result']['title'] ?? '', 'author' => '', 'thumb' => $a['result']['thumbnail'] ?? '', 'dls' => $dls];
            }
            break;

        // --- YOUTUBE APIs ---
        case 'yt_savetube':
            $body = ['url' => $u];
            if (!empty($req['quality'])) $body['quality'] = $req['quality'];
            if (!empty($req['type'])) $body['type'] = $req['type'];

            $a = fP('https://puruboy-api.vercel.app/api/downloader/savetube', $body);
            if (isset($a['success']) && $a['success']) {
                $r = $a['result'];
                $dls = [];
                if (!empty($r['downloadUrl'])) {
                    $fmt = ($req['type'] === 'audio' || strpos($r['downloadUrl'], '.mp3') !== false) ? 'audio' : 'video';
                    $dls[] = ['type' => 'Tải ' . ($req['quality'] ?? 'Media'), 'url' => $r['downloadUrl'], 'format' => $fmt];
                } else {
                    foreach ($r['video_formats'] ?? [] as $v) {
                        $dls[] = ['type' => 'Video ' . $v['quality'] . 'p', 'url' => $v['url'], 'format' => 'video'];
                    }
                    foreach ($r['audio_formats'] ?? [] as $au) {
                        $dls[] = ['type' => 'Audio ' . $au['quality'] . 'kbps', 'url' => $au['url'], 'format' => 'audio'];
                    }
                }
                $res['ok'] = true;
                $res['data'] = ['type' => 'single', 'title' => $r['title'] ?? 'YouTube Media', 'author' => 'Savetube', 'thumb' => $r['thumbnail'] ?? '', 'dls' => $dls];
            }
            break;

        case 'yt_dl':
            $a = fP('https://puruboy-api.vercel.app/api/downloader/youtube', ['url' => $u]);
            if (isset($a['success']) && $a['success']) {
                $r = $a['result'];
                $res['ok'] = true;
                $res['data'] = ['type' => 'single', 'title' => $r['title'] ?? '', 'author' => 'YouTube', 'thumb' => $r['thumbnail'] ?? '', 'dls' => [['type' => 'Video ' . ($r['quality'] ?? '720p'), 'url' => $r['downloadUrl'], 'format' => 'video']]];
            }
            break;

        case 'yt_mp3':
            $a = fP('https://puruboy-api.vercel.app/api/downloader/ytmp3', ['url' => $u]);
            if (isset($a['success']) && $a['success']) {
                $r = $a['result'];
                $res['ok'] = true;
                $res['data'] = ['type' => 'single', 'title' => $r['title'] ?? '', 'author' => 'YouTube', 'thumb' => $r['thumbnail'] ?? '', 'dls' => [['type' => 'Audio MP3 ' . ($r['quality'] ?? '128kbps'), 'url' => $r['downloadUrl'], 'format' => 'audio']]];
            }
            break;

        case 'yt_search':
            $q = urlencode($u);
            $j = json_decode(fG("https://puruboy-api.vercel.app/api/search/youtube?q=$q"), true);
            if (isset($j['success']) && $j['success'] && !empty($j['result'])) {
                $res['ok'] = true;
                $items = [];
                foreach (array_slice($j['result'], 0, 15) as $item) {
                    $items[] = [
                        'title' => $item['title'] ?? '',
                        'image' => $item['image'] ?? $item['thumbnail'] ?? '',
                        'url' => $item['url'] ?? $item['pin_url'] ?? '',
                        'desc' => $item['author'] ?? ($item['pinner']['username'] ?? ''),
                    ];
                }
                $res['data'] = ['type' => 'grid', 'items' => $items];
            }
            break;

        // --- OTHER PLATFORMS ---
        case 'tw':
            $h = fG('https://www.xsaver.io/x-downloader/download.php?url=' . urlencode($u));
            preg_match('/class="[^"]*video-title[^"]*"[^>]*>(.*?)<\//is', $h, $mt);
            preg_match_all('/href="save-url\.php\?url=([^"]+)"/is', $h, $ml);
            $dls = [];
            foreach ($ml[1] ?? [] as $i => $l) $dls[] = ['type' => 'Video ' . ($i + 1), 'url' => urldecode($l), 'format' => 'video'];
            if ($dls) {
                $res['ok'] = true;
                $res['data'] = ['type' => 'single', 'title' => trim(strip_tags($mt[1] ?? 'Twitter Video')), 'author' => 'Twitter/X', 'thumb' => '', 'dls' => $dls];
            }
            break;

        case 'ig':
            $h = fG('https://vdfr.app/download/?url=' . urlencode($u));
            preg_match('/href="([^"]+downloads\.acxcdn\.com[^"]+)"/is', $h, $ml);
            if (!empty($ml[1])) {
                $res['ok'] = true;
                $res['data'] = ['type' => 'single', 'title' => 'Instagram Video', 'author' => 'Instagram', 'thumb' => '', 'dls' => [['type' => 'Download MP4', 'url' => $ml[1], 'format' => 'video']]];
            }
            break;

        case 'sp':
            $ch = curl_init('https://spotmate.online/en1');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_HEADER => 1,
                CURLOPT_USERAGENT => 'Mozilla/5.0',
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
            ]);
            $o = curl_exec($ch);
            $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            preg_match('/name="csrf-token" content="([^"]+)"/', substr($o, $hs), $mc);
            preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', substr($o, 0, $hs), $mco);
            $hd = ["x-csrf-token: " . ($mc[1] ?? ''), "cookie: " . implode('; ', $mco[1] ?? []), "x-requested-with: XMLHttpRequest", "origin: https://spotmate.online"];
            $td = fP('https://spotmate.online/getTrackData', ['spotify_url' => $u], $hd);
            $cd = fP('https://spotmate.online/convert', ['urls' => $u], $hd);
            if (!empty($cd['url'])) {
                $res['ok'] = true;
                $res['data'] = ['type' => 'single', 'title' => $td['name'] ?? 'Spotify Track', 'author' => 'Spotify', 'thumb' => $td['album']['images'][0]['url'] ?? '', 'dls' => [['type' => 'Download MP3', 'url' => $cd['url'], 'format' => 'audio']]];
            }
            break;

        case 'pi_search':
            $q = urlencode($u);
            $j = json_decode(fG("https://puruboy-api.vercel.app/api/search/pinterest?q=$q"), true);
            if (isset($j['success']) && $j['success'] && !empty($j['result'])) {
                $res['ok'] = true;
                $items = [];
                foreach (array_slice($j['result'], 0, 15) as $item) {
                    $items[] = [
                        'title' => $item['title'] ?? '',
                        'image' => $item['image'] ?? '',
                        'url' => $item['pin_url'] ?? '',
                        'desc' => $item['pinner']['fullName'] ?? '',
                    ];
                }
                $res['data'] = ['type' => 'grid', 'items' => $items];
            }
            break;

        case 'pi_dl':
            $meta = fP('https://pin.vinayop.cloud/pin', ['url' => $u]);
            $imgDl = "https://pin.vinayop.cloud/v1/pin/img?url=" . urlencode($u);
            $vidDl = "https://pin.vinayop.cloud/v1/pin/video?url=" . urlencode($u);
            $thumb = $meta['data']['image'] ?? $meta['image'] ?? $imgDl;
            $title = $meta['data']['title'] ?? $meta['title'] ?? 'Pinterest Media';
            $res['ok'] = true;
            $res['data'] = ['type' => 'single', 'title' => $title, 'author' => 'Pinterest', 'thumb' => $thumb, 'dls' => [
                ['type' => 'Ảnh Max Res', 'url' => $imgDl, 'format' => 'image'],
                ['type' => 'Video (Nếu có)', 'url' => $vidDl, 'format' => 'video'],
            ]];
            break;

        default:
            $res['msg'] = 'Không xác định được nguồn API (' . htmlspecialchars($s) . ')';
    }

    if (!$res['ok'] && $res['msg'] === 'Error') {
        $res['msg'] = 'Nguồn này hiện không phản hồi hoặc link không hợp lệ.';
    }
    echo json_encode($res);
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="referrer" content="no-referrer">
<title>Kevinz Dowloader</title>
<link rel="stylesheet" href="https://unpkg.com/98.css">
<style>
  :root{
    --k-paper:#c0c0c0;
    --k-paper-dark:#808080;
    --k-navy:#000080;
    --k-teal:#008080;
    --k-ink:#000;
    --k-ok:#006400;
    --k-err:#a00000;
    --k-focus:#000080;
    --k-radius:0;
    --k-gap-sm:8px;
    --k-gap-md:16px;
    --k-gap-lg:24px;
  }
  *{box-sizing:border-box;}
  html,body{overflow-x:clip;}
  body{
    background:var(--k-teal) url('https://i.pinimg.com/736x/11/01/db/1101db710eebcb6badf9755f126db50c.jpg') no-repeat center center fixed;
    background-size:cover;
    display:flex;justify-content:center;align-items:flex-start;
    min-height:100vh;margin:0;padding:clamp(10px,3vw,32px);
    font-family:'Pixelated MS Sans Serif',Arial,sans-serif;
  }
  .window{width:100%;max-width:960px;box-shadow:8px 8px 25px rgba(0,0,0,.9);margin:auto;}
  .title-bar-text{font-size:clamp(15px,2.4vw,20px);font-weight:bold;overflow-wrap:anywhere;min-width:0;}
  .window-body{padding:clamp(12px,3vw,20px);}

  input,select{
    color:#000!important;background:#fff!important;
    font-size:16px!important;padding:10px!important;
    min-height:44px;
  }
  input:focus-visible,select:focus-visible,button:focus-visible{
    outline:2px solid var(--k-focus);outline-offset:1px;
  }

  .input-group{display:flex;gap:var(--k-gap-md);margin-bottom:var(--k-gap-md);flex-wrap:wrap;}
  .input-group input{flex:1 1 240px;min-width:0;}

  .field-row{display:flex;gap:var(--k-gap-md);align-items:center;flex-wrap:wrap;}
  .field-row select{flex:1 1 220px;min-width:0;}
  .field-row button{font-size:16px;padding:10px 20px;font-weight:bold;min-height:44px;white-space:nowrap;}

  .advanced-options{
    background:var(--k-paper);border:2px inset #fff;padding:var(--k-gap-md);margin-top:var(--k-gap-md);
    display:none;gap:var(--k-gap-md);flex-wrap:wrap;
  }
  .advanced-options input{flex:1 1 200px;min-width:0;}

  /* status / feedback */
  .status-bar{margin-top:var(--k-gap-md);padding:10px 12px;font-size:15px;font-weight:bold;
    border:2px inset #fff;display:none;align-items:center;gap:10px;}
  .status-bar.is-loading{display:flex;color:var(--k-navy);background:#fff;}
  .status-bar.is-ok{display:flex;color:var(--k-ok);background:#e9ffe9;}
  .status-bar.is-error{display:flex;color:var(--k-err);background:#ffecec;}
  .spinner{
    width:14px;height:14px;border:3px solid var(--k-navy);border-top-color:transparent;
    border-radius:50%;animation:spin .7s linear infinite;flex:0 0 auto;
  }
  @keyframes spin{to{transform:rotate(360deg);}}
  .fail-list{margin:6px 0 0;padding-left:18px;font-weight:normal;font-size:13px;}

  /* result card */
  .result-single{margin-top:var(--k-gap-lg);display:none;padding:var(--k-gap-md);}
  .result-single legend{font-size:18px;font-weight:bold;padding:0 6px;}
  .result-info{display:flex;gap:var(--k-gap-md);margin-bottom:var(--k-gap-md);flex-wrap:wrap;}
  .result-info img{width:100%;max-width:260px;min-width:0;border:4px inset #fff;object-fit:cover;background:#eee;aspect-ratio:1/1;}
  #r-title{font-size:16px;line-height:1.5;font-weight:bold;overflow-wrap:anywhere;}
  #r-author{font-size:18px;color:var(--k-navy);overflow-wrap:anywhere;}

  .dl-item{display:flex;gap:8px;margin-bottom:10px;width:100%;flex-wrap:wrap;}
  .dl-item .btn-dl{flex:1 1 160px;min-width:0;}
  .dl-item .btn-play{flex:0 0 auto;width:96px;}
  .dl-item button{font-size:15px;padding:12px 8px;font-weight:bold;min-height:44px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .dl-item button[disabled]{opacity:.6;cursor:wait;}
  .dl-item button.is-error{background:#ffd0d0!important;}

  /* media player */
  .media-player{
    width:100%;background:#000;border:3px inset #fff;margin-top:var(--k-gap-md);
    display:none;flex-direction:column;align-items:center;padding:10px;
  }
  .media-player video{width:100%;max-height:60vh;display:none;background:#000;}
  .media-player audio{width:100%;display:none;margin-top:10px;}
  .media-player .player-msg{color:#fff;font-size:14px;padding:20px 10px;text-align:center;display:none;}
  .player-controls{display:flex;gap:10px;margin-top:10px;width:100%;justify-content:center;flex-wrap:wrap;}
  .player-controls button{padding:8px 16px;font-weight:bold;min-height:40px;}
  .close-player{background:#a00000;color:#fff;border:2px outset #fff;}

  /* grid (search) */
  .result-grid{display:none;flex-wrap:wrap;gap:var(--k-gap-md);margin-top:var(--k-gap-lg);justify-content:center;}
  .grid-item{width:calc(33.333% - 16px);min-width:0;flex:1 1 240px;max-width:300px;padding:10px;text-align:center;}
  .grid-item img{width:100%;height:200px;object-fit:cover;border:3px inset #fff;margin-bottom:8px;background:#eee;}
  .grid-item p{font-size:14px;min-height:38px;overflow:hidden;font-weight:bold;overflow-wrap:anywhere;}
  .grid-item .grid-btns{display:flex;gap:6px;}
  .grid-item button{flex:1 1 0;min-width:0;padding:10px 4px;font-size:13px;font-weight:bold;min-height:44px;
    white-space:normal;line-height:1.2;}

  /* toast */
  .toast{
    position:fixed;left:50%;bottom:24px;transform:translate(-50%,10px);
    background:var(--k-navy);color:#fff;padding:10px 18px;font-size:14px;font-weight:bold;
    border:2px outset #fff;opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:999;
    max-width:90vw;text-align:center;
  }
  .toast.is-visible{opacity:1;transform:translate(-50%,0);}

  .footer{text-align:center;margin-top:var(--k-gap-lg);font-size:14px;background:rgba(255,255,255,.9);
    padding:10px;border:3px inset #fff;font-weight:bold;}

  @media (max-width:600px){
    .grid-item{width:100%;max-width:none;}
    .result-info{flex-direction:column;align-items:center;text-align:center;}
    .result-info img{max-width:200px;}
  }
</style>
</head>
<body>

<div class="window">
  <div class="title-bar">
    <div class="title-bar-text">Kevinz Dowloader.exe</div>
    <div class="title-bar-controls">
      <button aria-label="Minimize"></button>
      <button aria-label="Maximize"></button>
      <button aria-label="Close"></button>
    </div>
  </div>

  <div class="window-body">
    <form id="dl-form">
      <div class="input-group">
        <input type="text" id="url-input" placeholder="Dán Link hoặc nhập từ khoá tìm kiếm..." required autocomplete="off">
      </div>

      <div class="field-row">
        <label for="server-select">API:</label>
        <select id="server-select">
          <option value="auto" style="font-weight:bold;color:blue;">✨ Tự động nhận diện (Khuyên dùng)</option>
          <option value="yt_savetube">YouTube (Savetube - Tuỳ chỉnh)</option>
          <option value="tk1">TikTok (Snaptik)</option>
          <option value="tw">Twitter / X (Xsaver)</option>
          <option value="ig">Instagram (Vdfr)</option>
          <option value="sp">Spotify (Spotmate)</option>
          <option value="pi_dl">Pinterest (Tải Link)</option>
          <option value="yt_search">YouTube (Tìm kiếm)</option>
          <option value="pi_search">Pinterest (Tìm kiếm)</option>
        </select>
        <button type="submit" id="btn-execute">Execute</button>
      </div>

      <div class="advanced-options" id="advanced-yt">
        <label>Quality:</label>
        <input type="text" id="yt-quality" placeholder="Bỏ trống = lấy tất cả (VD: 1080, 720, 128)">
        <label>Type:</label>
        <select id="yt-type">
          <option value="">Cả hai (Mặc định)</option>
          <option value="video">Chỉ Video</option>
          <option value="audio">Chỉ Audio (MP3)</option>
        </select>
      </div>
    </form>

    <div class="status-bar" id="status-bar" role="status" aria-live="polite">
      <span class="spinner" id="status-spinner"></span>
      <span id="status-text"></span>
    </div>

    <div class="media-player" id="media-player">
      <video id="web-video" controls preload="metadata" playsinline></video>
      <audio id="web-audio" controls preload="metadata"></audio>
      <div class="player-msg" id="player-msg"></div>
      <div class="player-controls">
        <button type="button" class="close-player" id="btn-close-player">Đóng Trình Phát</button>
      </div>
    </div>

    <fieldset class="result-single window" id="result-single">
      <legend>Thông Tin & Tải Xuống</legend>
      <div class="result-info">
        <img id="r-thumb" src="" alt="" loading="lazy">
        <div style="flex:1;min-width:0;">
          <b id="r-author"></b><br><br>
          <span id="r-title"></span><br><br>
          <button type="button" id="btn-view">Mở Link Gốc</button>
        </div>
      </div>
      <div id="r-buttons"></div>
    </fieldset>

    <div class="result-grid" id="result-grid"></div>

    <div class="footer">© 2026 Kevinz Dowloader — API Author: AnhzTuan</div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
(() => {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const serverSelect = $('server-select');
  const advancedYt = $('advanced-yt');
  const statusBar = $('status-bar');
  const statusSpinner = $('status-spinner');
  const statusText = $('status-text');
  const resSingle = $('result-single');
  const resGrid = $('result-grid');
  const rButtons = $('r-buttons');
  const mediaPlayer = $('media-player');
  const webVideo = $('web-video');
  const webAudio = $('web-audio');
  const playerMsg = $('player-msg');
  const toastEl = $('toast');
  const btnExecute = $('btn-execute');
  const form = $('dl-form');

  let currentAbort = null;
  let toastTimer = null;

  // ---- helpers -------------------------------------------------------
  function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }

  function b64url(str) {
    return btoa(unescape(encodeURIComponent(str)))
      .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  // Route every media URL through our own streaming proxy so hotlink /
  // referrer-protected CDNs (TikTok, IG, Pinterest, Spotify...) actually
  // play instead of failing silently.
  function proxyUrl(rawUrl, download = false) {
    return 'index.php?proxy=' + encodeURIComponent(b64url(rawUrl)) + (download ? '&dl=1' : '');
  }

  function showToast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('is-visible'), 2200);
  }

  function setStatus(kind, text) {
    statusBar.className = 'status-bar' + (kind ? ' is-' + kind : '');
    statusSpinner.style.display = kind === 'loading' ? 'inline-block' : 'none';
    statusText.textContent = text;
  }

  function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(() => showToast('Đã copy link!'));
      return;
    }
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); showToast('Đã copy link!'); }
    catch { showToast('Không thể copy — hãy copy thủ công.'); }
    document.body.removeChild(ta);
  }

  function closePlayer() {
    mediaPlayer.style.display = 'none';
    webVideo.pause(); webVideo.removeAttribute('src'); webVideo.style.display = 'none';
    webAudio.pause(); webAudio.removeAttribute('src'); webAudio.style.display = 'none';
    playerMsg.style.display = 'none';
  }

  function playMedia(rawUrl, type) {
    closePlayer();
    mediaPlayer.style.display = 'flex';
    const src = proxyUrl(rawUrl, false);
    const el = type === 'video' ? webVideo : webAudio;
    el.style.display = 'block';
    playerMsg.style.display = 'none';

    const onError = () => {
      playerMsg.textContent = 'Nguồn này chặn phát trực tiếp. Hãy dùng nút "Tải" để tải file về máy.';
      playerMsg.style.display = 'block';
      el.style.display = 'none';
    };
    el.onerror = onError;
    el.src = src;
    el.play().catch(() => { /* autoplay может bị chặn, không phải lỗi thật */ });
    mediaPlayer.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function downloadFile(rawUrl, btn) {
    const original = btn.textContent;
    btn.disabled = true;
    btn.dataset.state = 'loading';
    btn.textContent = 'Đang tải…';
    const a = document.createElement('a');
    a.href = proxyUrl(rawUrl, true);
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => { btn.disabled = false; btn.textContent = original; btn.dataset.state = 'default'; }, 1200);
  }

  // ---- advanced options toggle ---------------------------------------
  serverSelect.addEventListener('change', () => {
    advancedYt.style.display = serverSelect.value === 'yt_savetube' ? 'flex' : 'none';
  });

  // ---- delegated clicks (result card + grid + player) ----------------
  rButtons.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const url = btn.dataset.url;
    if (btn.dataset.action === 'download') downloadFile(url, btn);
    else if (btn.dataset.action === 'play') playMedia(url, btn.dataset.format);
  });

  resGrid.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const url = btn.dataset.url;
    if (btn.dataset.action === 'open') window.open(url, '_blank', 'noopener');
    else if (btn.dataset.action === 'copy') copyToClipboard(url);
  });

  $('btn-close-player').addEventListener('click', closePlayer);

  // ---- render helpers --------------------------------------------------
  function renderSingle(data, sourceUrl) {
    resSingle.style.display = 'block';
    $('r-thumb').src = data.thumb || '';
    $('r-thumb').alt = data.title || 'thumbnail';
    $('r-title').textContent = data.title || 'No Title';
    $('r-author').textContent = data.author ? '👤 ' + data.author : '';
    $('btn-view').onclick = () => window.open(sourceUrl, '_blank', 'noopener');
  }

  function appendDlButtons(dls) {
    (dls || []).forEach((link) => {
      if (!link.url || rButtons.querySelector(`[data-url="${CSS.escape(link.url)}"]`)) return;
      const div = document.createElement('div');
      div.className = 'dl-item';

      const dlBtn = document.createElement('button');
      dlBtn.type = 'button';
      dlBtn.className = 'btn-dl';
      dlBtn.dataset.action = 'download';
      dlBtn.dataset.url = link.url;
      dlBtn.textContent = 'Tải ' + (link.type || '');
      div.appendChild(dlBtn);

      if (link.format === 'video' || link.format === 'audio') {
        const playBtn = document.createElement('button');
        playBtn.type = 'button';
        playBtn.className = 'btn-play';
        playBtn.dataset.action = 'play';
        playBtn.dataset.url = link.url;
        playBtn.dataset.format = link.format;
        playBtn.textContent = link.format === 'video' ? '▶ Xem' : '▶ Nghe';
        div.appendChild(playBtn);
      }
      rButtons.appendChild(div);
    });
  }

  function renderGrid(items) {
    resGrid.style.display = 'flex';
    resGrid.innerHTML = '';
    items.forEach((item) => {
      const titleStr = (item.title || item.desc || 'No Title').slice(0, 60);
      const div = document.createElement('div');
      div.className = 'grid-item window';
      div.innerHTML = `
        <div class="window-body" style="padding:8px;">
          <img src="${escapeHtml(item.image)}" alt="${escapeHtml(titleStr)}" loading="lazy">
          <p>${escapeHtml(titleStr)}</p>
          <div class="grid-btns">
            <button type="button" data-action="open">Mở</button>
            <button type="button" data-action="copy">Copy</button>
          </div>
        </div>`;
      div.querySelectorAll('button').forEach((b) => (b.dataset.url = item.url));
      resGrid.appendChild(div);
    });
  }

  // ---- main submit -----------------------------------------------------
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const u = $('url-input').value.trim();
    if (!u) return;
    const s = serverSelect.value;
    const ytQ = $('yt-quality').value.trim();
    const ytT = $('yt-type').value;

    if (currentAbort) currentAbort.abort();
    currentAbort = new AbortController();
    const { signal } = currentAbort;

    const isSearch = !/^https?:\/\//i.test(u);
    let apisToRun = [];

    if (s === 'auto') {
      if (isSearch) apisToRun = [{ s: 'yt_search' }];
      else if (u.includes('youtube') || u.includes('youtu.be')) apisToRun = [{ s: 'yt_savetube' }, { s: 'yt_dl' }, { s: 'yt_mp3' }];
      else if (u.includes('tiktok') || u.includes('douyin')) apisToRun = [{ s: 'tk1' }, { s: 'tk2' }, { s: 'tk3' }];
      else if (u.includes('twitter') || u.includes('x.com')) apisToRun = [{ s: 'tw' }];
      else if (u.includes('instagram')) apisToRun = [{ s: 'ig' }];
      else if (u.includes('spotify')) apisToRun = [{ s: 'sp' }];
      else if (u.includes('pinterest') || u.includes('pin.it')) apisToRun = [{ s: 'pi_dl' }];
      else { setStatus('error', '❌ Không nhận diện được link này — vui lòng chọn API thủ công.'); return; }
    } else {
      apisToRun = [{ s, quality: ytQ, type: ytT }];
    }

    resSingle.style.display = 'none';
    resGrid.style.display = 'none';
    closePlayer();
    rButtons.innerHTML = '';
    btnExecute.disabled = true;
    btnExecute.textContent = 'Đang chạy…';
    setStatus('loading', `Đang thực thi ${apisToRun.length} luồng API…`);

    let hasRenderedInfo = false;
    let successCount = 0;
    const failedSources = [];

    const promises = apisToRun.map((target) =>
      fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ u, ...target }),
        signal,
      })
        .then((r) => r.json())
        .then((data) => {
          if (data.ok) {
            successCount++;
            if (data.data.type === 'single') {
              if (!hasRenderedInfo) { hasRenderedInfo = true; renderSingle(data.data, u); }
              appendDlButtons(data.data.dls);
            } else if (data.data.type === 'grid') {
              renderGrid(data.data.items);
            }
          } else {
            failedSources.push(target.s + (data.msg ? ` (${data.msg})` : ''));
          }
        })
        .catch((err) => {
          if (err.name !== 'AbortError') failedSources.push(target.s + ' (network error)');
        })
    );

    await Promise.allSettled(promises);
    if (signal.aborted) return;

    btnExecute.disabled = false;
    btnExecute.textContent = 'Execute';

    if (successCount === 0) {
      setStatus('error', '❌ Không lấy được dữ liệu (link die hoặc nguồn đang lỗi). Thử lại hoặc đổi API thủ công.');
    } else if (failedSources.length) {
      setStatus('ok', `✅ Hoàn tất (${successCount}/${apisToRun.length} luồng thành công). Một vài nguồn không phản hồi.`);
    } else {
      setStatus('ok', `✅ Xử lý hoàn tất! (${successCount} luồng thành công)`);
    }
  });
})();
</script>
</body>
</html>
