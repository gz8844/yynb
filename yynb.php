<?php
// ---------------------------------------------------------------
// 增强版 PHP API 代理（反 403 稳定性增强）
// ---------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

// --- 配置区 ---
define('API_BASE_URL', 'https://qqqys.com/api.php/decode/url/?vodFrom=YYNB&url=');
define('MAX_RETRIES', 3); // 失败重试次数
define('RETRY_DELAY', 1); // 重试延迟（秒）

// --- 步骤 1: 获取参数 ---
if (empty($_GET['url'])) {
    echo json_encode([
        'code' => 0,
        'msg' => '解码失败，缺少 ?url= 参数',
        'yynburl' => null
    ]);
    exit;
}

$received_value = htmlspecialchars($_GET['url']);

// --- 步骤 2: 构建目标 URL ---
$target_url = API_BASE_URL . urlencode($received_value);
$urlParts = parse_url(API_BASE_URL);
$host = $urlParts['host'];
$scheme = $urlParts['scheme'];
$root_url = $scheme . '://' . $host . '/';

// --- 步骤 3: 增强型 User-Agent 池（定期更新） ---
$userAgentList = [
    // Windows Chrome (最新版本)
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
    
    // Windows Firefox
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0',
    
    // Windows Edge
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
    
    // macOS Safari
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15',
    
    // macOS Chrome
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    
    // iPhone Safari
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.7 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
    
    // Android Chrome
    'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.6778.104 Mobile Safari/537.36',
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
    
    // Android Firefox
    'Mozilla/5.0 (Android 14; Mobile; rv:132.0) Gecko/132.0 Firefox/132.0'
];

// --- 步骤 4: 增强型请求头生成函数 ---
function generateHeaders($root_url, $user_agent) {
    // 随机选择接受的语言顺序
    $languages = [
        'zh-CN,zh;q=0.9,en;q=0.8',
        'en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
        'zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
        'en-US,en;q=0.9'
    ];
    
    return [
        'User-Agent: ' . $user_agent,
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Accept-Language: ' . $languages[array_rand($languages)],
        'Accept-Encoding: gzip, deflate, br',
        'Referer: ' . $root_url,
        'Origin: ' . rtrim($root_url, '/'),
        'X-Requested-With: XMLHttpRequest',
        'DNT: 1', // Do Not Track
        'Connection: keep-alive',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-origin',
        // 添加一些随机性
        'Cache-Control: ' . (rand(0, 1) ? 'no-cache' : 'max-age=0')
    ];
}

// --- 步骤 5: Cookie 文件管理（增加文件名随机性） ---
$cookie_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'api_proxy_' . md5($host . session_id()) . '.txt';

// 定期清理旧 Cookie（超过 1 小时）
if (file_exists($cookie_file) && (time() - filemtime($cookie_file)) > 3600) {
    @unlink($cookie_file);
}

// --- 步骤 6: 带重试机制的请求函数 ---
function makeRequest($url, $headers, $cookie_file, $attempt = 1) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_ENCODING => '', // 自动处理所有编码
        CURLOPT_COOKIEJAR => $cookie_file,
        CURLOPT_COOKIEFILE => $cookie_file,
        
        // SSL 设置
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        
        // 反指纹识别
        CURLOPT_HTTPAUTH => CURLAUTH_ANY,
        CURLOPT_UNRESTRICTED_AUTH => false,
        
        // 模拟真实浏览器行为
        CURLOPT_AUTOREFERER => true,
        
        // HTTP/2 支持（如果可用）
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0
    ]);
    
    // 添加随机延迟（模拟人类行为）
    if ($attempt > 1) {
        usleep(rand(500000, 1500000)); // 0.5-1.5秒随机延迟
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    curl_close($ch);
    
    return [
        'response' => $response,
        'http_code' => $http_code,
        'error' => $curl_error
    ];
}

// --- 步骤 7: 主请求逻辑（带重试） ---
$last_error = null;
$last_http_code = null;

for ($attempt = 1; $attempt <= MAX_RETRIES; $attempt++) {
    // 每次重试使用不同的 User-Agent
    $randomUserAgent = $userAgentList[array_rand($userAgentList)];
    $headers = generateHeaders($root_url, $randomUserAgent);
    
    // 如果不是第一次尝试，先清理 Cookie
    if ($attempt > 1 && file_exists($cookie_file)) {
        @unlink($cookie_file);
        if ($attempt > 2) {
            sleep(RETRY_DELAY); // 重试前等待
        }
    }
    
    $result = makeRequest($target_url, $headers, $cookie_file, $attempt);
    
    // 成功响应
    if ($result['http_code'] >= 200 && $result['http_code'] < 300 && !empty($result['response'])) {
        $json_response = $result['response'];
        break;
    }
    
    // 记录错误信息
    $last_error = $result['error'];
    $last_http_code = $result['http_code'];
    
    // 如果是最后一次尝试，跳出循环
    if ($attempt >= MAX_RETRIES) {
        break;
    }
}

// --- 步骤 8: 错误处理 ---
if (empty($json_response) || $last_http_code < 200 || $last_http_code >= 300) {
    $error_msg = '解码失败，无法连接到上游 API';
    
    // 根据错误码提供更详细的信息
    if ($last_http_code == 403) {
        $error_msg = '访问被拒绝(403)，目标服务器可能启用了高级防护';
    } elseif ($last_http_code == 429) {
        $error_msg = '请求过于频繁(429)，请稍后再试';
    } elseif ($last_http_code == 503) {
        $error_msg = '服务暂时不可用(503)';
    }
    
    echo json_encode([
        'code' => 0,
        'msg' => $error_msg,
        'yynburl' => $received_value,
        'debug_info' => [
            'http_code' => $last_http_code,
            'attempts' => $attempt,
            'curl_error' => $last_error
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 步骤 9: 解析并格式化响应 ---
$data = json_decode($json_response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'code' => 0,
        'msg' => '解码失败，无法解析 API 响应',
        'yynburl' => $received_value,
        'debug_info' => [
            'json_error' => json_last_error_msg(),
            'raw_preview' => substr($json_response, 0, 200)
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 步骤 10: 返回格式化结果 ---
if (isset($data['code']) && $data['code'] == 1 && isset($data['data'])) {
    echo json_encode([
        'code' => 200,
        'msg' => $data['msg'] ?? '解码成功',
        'url' => $data['data'],
        'yynburl' => $received_value
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode([
        'code' => 0,
        'msg' => '解码失败，返回原始URL',
        'yynburl' => $received_value,
        'api_msg' => $data['msg'] ?? 'Unknown error'
    ], JSON_UNESCAPED_UNICODE);
}

?>