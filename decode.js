// functions/decode.js

// --- 配置区 ---
const API_BASE_URL = context.env.API_BASE_URL; // context.env 访问变量
const MAX_RETRIES = context.env.MAX_RETRIES;; // 失败重试次数
const RETRY_DELAY = context.env.RETRY_DELAY; // 重试延迟（毫秒）

// --- 增强型 User-Agent 池 ---
const userAgentList = [
    // ... 您的 User-Agent 列表 ...
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.7 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
];

// --- 辅助函数：随机延迟 ---
const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

// --- 辅助函数：增强型请求头生成 ---
function generateHeaders(rootUrl, userAgent) {
    const languages = [
        'zh-CN,zh;q=0.9,en;q=0.8',
        'en-US,en;q=0.9,zh-CN;q=0.8,zh;q=0.7',
        'zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
        'en-US,en;q=0.9'
    ];

    const headers = new Headers({
        'User-Agent': userAgent,
        'Accept': 'application/json, text/javascript, */*; q=0.01',
        'Accept-Language': languages[Math.floor(Math.random() * languages.length)],
        'Accept-Encoding': 'gzip, deflate, br',
        'Referer': rootUrl,
        'Origin': rootUrl.endsWith('/') ? rootUrl.slice(0, -1) : rootUrl,
        'X-Requested-With': 'XMLHttpRequest',
        'DNT': '1',
        'Connection': 'keep-alive',
        'Sec-Fetch-Dest': 'empty',
        'Sec-Fetch-Mode': 'cors',
        'Sec-Fetch-Site': 'same-origin',
        'Cache-Control': Math.random() < 0.5 ? 'no-cache' : 'max-age=0'
    });

    return headers;
}

// --- 主要请求处理函数 ---
export async function onRequest(context) {
    // --- 步骤 1: 获取参数 ---
    const url = new URL(context.request.url);
    const receivedValue = url.searchParams.get('url');

    if (!receivedValue) {
        return Response.json({
            code: 0,
            msg: '解码失败，缺少 ?url= 参数',
            yynburl: null
        }, { status: 400 }); // 返回 400 Bad Request
    }

    // --- 步骤 2: 构建目标 URL ---
    const targetUrl = API_BASE_URL + encodeURIComponent(receivedValue);
    const rootUrl = new URL(API_BASE_URL).origin + '/';

    let lastError = null;
    let lastStatusCode = null;
    let jsonResponse = null;
    let attempt = 0;

    // --- 步骤 3: 带重试机制的请求循环 ---
    for (attempt = 1; attempt <= MAX_RETRIES; attempt++) {
        const randomUserAgent = userAgentList[Math.floor(Math.random() * userAgentList.length)];
        const headers = generateHeaders(rootUrl, randomUserAgent);

        // 如果不是第一次尝试，增加延迟 (模拟人类行为/等待目标服务器冷却)
        if (attempt > 1) {
            await sleep(RETRY_DELAY + Math.random() * 1000); // 1-2 秒随机延迟
        }

        try {
            // 使用标准的 fetch API
            const response = await fetch(targetUrl, {
                method: 'GET',
                headers: headers,
                // Cloudflare Workers/Pages Functions 默认支持 HTTP/2 和连接复用
                // 默认行为不需要像 cURL 那样复杂的配置
            });

            lastStatusCode = response.status;

            // 成功响应 (2xx)
            if (lastStatusCode >= 200 && lastStatusCode < 300) {
                jsonResponse = await response.text();
                // 确保响应体不为空
                if (jsonResponse) {
                    break;
                }
            }

            // 如果状态码是 403 或 429 等，记录并准备重试
            lastError = `HTTP Error ${lastStatusCode}`;

        } catch (e) {
            // 网络错误 (如 DNS 解析失败、超时等)
            lastError = e.message;
        }

        // 如果是最后一次尝试，跳出循环
        if (attempt >= MAX_RETRIES) {
            break;
        }
    }

    // --- 步骤 4: 错误处理 ---
    if (!jsonResponse) {
        let errorMsg = '解码失败，无法连接到上游 API';

        if (lastStatusCode === 403) {
            errorMsg = '访问被拒绝(403)，目标服务器可能启用了高级防护';
        } else if (lastStatusCode === 429) {
            errorMsg = '请求过于频繁(429)，请稍后再试';
        } else if (lastStatusCode === 503) {
            errorMsg = '服务暂时不可用(503)';
        }

        return Response.json({
            code: 0,
            msg: errorMsg,
            yynburl: receivedValue,
            debug_info: {
                http_code: lastStatusCode,
                attempts: attempt,
                curl_error: lastError
            }
        }, { status: lastStatusCode || 500 }); // 返回上游状态码或 500
    }

    // --- 步骤 5: 解析并格式化响应 ---
    let data;
    try {
        data = JSON.parse(jsonResponse);
    } catch (e) {
        return Response.json({
            code: 0,
            msg: '解码失败，无法解析 API 响应',
            yynburl: receivedValue,
            debug_info: {
                json_error: e.message,
                raw_preview: jsonResponse.substring(0, 200)
            }
        }, { status: 502 }); // 502 Bad Gateway 适合上游响应无效的情况
    }

    // --- 步骤 6: 返回格式化结果 ---
    if (data && data.code === 1 && data.data) {
        return Response.json({
            code: 200,
            msg: data.msg || '解码成功',
            url: data.data,
            yynburl: receivedValue
        }, { 
            status: 200,
            headers: { 'Content-Type': 'application/json; charset=utf-8' }
        });
    } else {
        return Response.json({
            code: 0,
            msg: '解码失败，返回原始URL',
            yynburl: receivedValue,
            api_msg: data.msg || 'Unknown error'
        }, { status: 200 });
    }
}
