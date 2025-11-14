<?php
/**
* IPTV完全代理脚本 - 带缓存版
*/

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 配置参数
$cacheTime = 600; // 缓存时间10分钟（秒）
$cacheFile = __DIR__.'/iptv_cache.txt';
$userAgent = 'Mozilla/5.0 (Linux; Android 13; 23013RK75C Build/TKQ1.220905.001) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.7339.207 Mobile Safari/537.36';
$requiredHeaders = [
 'Host: live.saileitv.com',
 'Connection: keep-alive',
 'sec-ch-ua-platform: "Android"',
 'Accept-Encoding: identity;q=1, *;q=0',
 'User-Agent: ' . $userAgent,
 'sec-ch-ua: "Chromium";v="140", "Not=A?Brand";v="24", "Android WebView";v="140"',
 'sec-ch-ua-mobile: ?1',
 'Accept: */*',
 'X-Requested-With: com.mmbox.xbrowser.pro',
 'Sec-Fetch-Site: same-site',
 'Sec-Fetch-Mode: no-cors',
 'Sec-Fetch-Dest: video',
 'Referer: https://saileitv.com/',
 'Accept-Language: zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
 'Range: bytes=0-'
];

// 获取参数
$channelId = isset($_GET['id']) ? trim($_GET['id']) : '';
$proxyUrl = isset($_GET['url']) ? trim($_GET['url']) : '';

// 当前脚本URL
$currentScriptUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";

/**
* 获取原始频道列表（带缓存）
*/
function getOriginalPlaylist() {
 global $cacheTime, $cacheFile, $userAgent, $requiredHeaders;

 // 检查缓存是否有效
 if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
 return file_get_contents($cacheFile);
 }

 // 获取最新列表
 $ch = curl_init();
 curl_setopt_array($ch, [
 CURLOPT_URL => 'https://live.saileitv.com/index.m3u',
 CURLOPT_RETURNTRANSFER => true,
 CURLOPT_FOLLOWLOCATION => true,
 CURLOPT_HTTPHEADER => $requiredHeaders,
 CURLOPT_REFERER => 'https://saileitv.com/',
 ]);

 $response = curl_exec($ch);
 curl_close($ch);

 // 保存到缓存
 if ($response !== false) {
 file_put_contents($cacheFile, $response);
 }

 return $response;
}

/**
* 生成纯TXT频道列表（不编码）
*/
function generateTxtPlaylist($content) {
 global $currentScriptUrl;

 $lines = explode("\n", $content);
 $output = '';
 $lastInfoLine = '';

 foreach ($lines as $line) {
 $trimmed = trim($line);

 // 保存EXTINF行
 if (strpos($trimmed, '#EXTINF') === 0) {
 $lastInfoLine = $line;
 continue;
 }

 // 处理URL行
 if (!empty($trimmed) && strpos($trimmed, 'http') === 0) {
 // 从EXTINF行提取频道名称
 if (preg_match('/,(.*?)$/', $lastInfoLine, $matches)) {
 $channelName = trim($matches[1]);
 // 输出原始频道名称（不编码）
 $output .= $channelName . ',' . $currentScriptUrl . '?id=' . $channelName . "\n";
 }
 $lastInfoLine = '';
 }
 }

 return $output;
}

/**
* 流式代理TS文件
*/
function proxyTsStream($url) {
 global $requiredHeaders;

 // 提取Cookie中的q值
 $qValue = '';
 if (preg_match('/q=(\d+)/', $url, $matches)) {
 $qValue = $matches[1];
 }

 // 添加Cookie头
 $headers = $requiredHeaders;
 if (!empty($qValue)) {
 $headers[] = 'Cookie: userId=q=' . $qValue;
 }

 // 流式输出
 header('Content-Type: video/MP2T');
 $ch = curl_init($url);
 curl_setopt_array($ch, [
 CURLOPT_RETURNTRANSFER => false,
 CURLOPT_FOLLOWLOCATION => true,
 CURLOPT_HTTPHEADER => $headers,
 CURLOPT_REFERER => 'https://saileitv.com/',
 CURLOPT_SSL_VERIFYPEER => false,
 CURLOPT_SSL_VERIFYHOST => false,
 CURLOPT_WRITEFUNCTION => function($ch, $data) {
 echo $data;
 return strlen($data);
 }
 ]);
 curl_exec($ch);
 curl_close($ch);
}

/**
* 代理M3U8并重写TS路径
*/
function proxyM3u8($url) {
 global $currentScriptUrl, $requiredHeaders;

 // 提取Cookie中的q值
 $qValue = '';
 if (preg_match('/q=(\d+)/', $url, $matches)) {
 $qValue = $matches[1];
 }

 // 添加Cookie头
 $headers = $requiredHeaders;
 if (!empty($qValue)) {
 $headers[] = 'Cookie: userId=q=' . $qValue;
 }

 // 获取M3U8内容
 $ch = curl_init();
 curl_setopt_array($ch, [
 CURLOPT_URL => $url,
 CURLOPT_RETURNTRANSFER => true,
 CURLOPT_FOLLOWLOCATION => true,
 CURLOPT_HTTPHEADER => $headers,
 CURLOPT_REFERER => 'https://saileitv.com/',
 ]);
 $content = curl_exec($ch);
 curl_close($ch);

 // 处理M3U8内容
 $lines = explode("\n", $content);
 $output = [];

 foreach ($lines as $line) {
 $trimmed = trim($line);

 // 保留所有注释行和加密信息
 if (empty($trimmed) || $line[0] === '#' || strpos($trimmed, '#EXT-X-KEY') === 0) {
 $output[] = $line;
 continue;
 }

 // 重写TS文件路径（不编码）
 if (strpos($trimmed, 'http') === 0 || strpos($trimmed, '/') === 0) {
 $output[] = $currentScriptUrl . '?url=' . $trimmed;
 } else {
 $output[] = $line;
 }
 }

 // 输出M3U8
 header('Content-Type: application/vnd.apple.mpegurl');
 header('Cache-Control: public, max-age=30');
 echo implode("\n", $output);
}

/**
* 查找频道URL
*/
function findChannelUrl($content, $channelId) {
 $lines = explode("\n", $content);
 $channelFound = false;

 foreach ($lines as $line) {
 $trimmed = trim($line);

 if (strpos($trimmed, '#EXTINF') !== false && stripos($trimmed, $channelId) !== false) {
 $channelFound = true;
 continue;
 }

 if ($channelFound && strpos($trimmed, 'http') === 0) {
 return $trimmed;
 }
 }

 return false;
}

// 主逻辑
if (!empty($proxyUrl)) {
 // TS文件代理
 if (strpos($proxyUrl, '.ts') !== false) {
 proxyTsStream($proxyUrl);
 // 其他文件类型
 proxyM3u8($proxyUrl);
 }
 exit;
} elseif (!empty($channelId)) {
 // 频道M3U8代理
 $playlist = getOriginalPlaylist();
 $channelUrl = findChannelUrl($playlist, $channelId);

 if ($channelUrl) {
 proxyM3u8($channelUrl);
 } else {
 header('HTTP/1.1 404 Not Found');
 echo 'Channel not found';
 }
 exit;
} else {
 // 输出纯TXT频道列表
 $playlist = getOriginalPlaylist();
 header('Content-Type: text/plain; charset=utf-8');
 echo generateTxtPlaylist($playlist);
 exit;
}