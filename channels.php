<?php

// Route handling - simple router
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/tube/(.+)$#', $request_uri, $matches)) {
    tube($matches[1]);
} elseif ($request_uri === '/alkawthar.m3u8') {
    alkawthar();
} else {
    http_response_code(404);
    echo "404 Not Found";
}

// ─────────────────────────────────────────────
// /tube/<name>
// ─────────────────────────────────────────────
function tube($name) {
    $url  = "https://youtube.com/@{$name}/live";
    $data = http_get($url);

    $needle_start = 'hlsManifestUrl":"https://manif';
    $needle_end   = '.m3u8';

    $pos_start = strpos($data, $needle_start);
    $pos_end   = strpos($data, $needle_end, $pos_start) + strlen($needle_end);
    $tube_url  = substr($data, $pos_start + 17, $pos_end - ($pos_start + 17));

    redirect($tube_url);
}

// ─────────────────────────────────────────────
// /alkawthar.m3u8
// ─────────────────────────────────────────────
function alkawthar() {
    // Step 1 – get the JS chunk filename
    $data = http_get('https://sepehrtv.ir/frame/t/alkosar');
    $app_pos  = strpos($data, '_app');
    $data     = substr($data, $app_pos, 16 + 8);          // same slice as Python

    // Step 2 – fetch the JS chunk
    $js = http_get('https://sepehrtv.ir/_next/static/chunks/pages/' . $data);

    // Step 3 – extract OAuth credentials from JS
    $js = substr($js, strpos($js, '{consumer:{key:'));

    $key    = substr($js, 16, 32);

    $secret_needle = 'secret:';
    $secret_pos    = strpos($js, $secret_needle) + strlen($secret_needle) + 1;
    $secret        = substr($js, $secret_pos, 32);

    $token_needle = 'getItem("secret")';
    $token_pos    = strpos($js, $token_needle) + strlen($token_needle) + 3; // +3 skips )+
    $token_block  = substr($js, $token_pos);
    $tokenkey     = substr($token_block, strpos($token_block, '"') + 1, 64);

    $ts_needle    = 'getItem("token")';
    $ts_pos       = strpos($js, $ts_needle) + strlen($ts_needle) + 3;
    $ts_block     = substr($js, $ts_pos);
    $tokensecret  = substr($ts_block, strpos($ts_block, '"') + 1, 64);

    // Step 4 – build OAuth 1.0 HMAC-SHA1 signed request
    $api_url = 'https://sepehrapi.sepehrtv.ir/v3/channels/'
             . '?key=alkosar&include_media_resources=true&include_details=true';

    $oauth_params = [
        'oauth_consumer_key'     => $key,
        'oauth_nonce'            => generate_nonce(),
        'oauth_timestamp'        => (string) time(),
        'oauth_token'            => $tokenkey,
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_version'          => '1.0',
    ];

    $signature = build_oauth_signature('GET', $api_url, $oauth_params, $secret, $tokensecret);
    $oauth_params['oauth_signature'] = $signature;

    $auth_header = 'OAuth ' . implode(', ', array_map(
        fn($k, $v) => rawurlencode($k) . '="' . rawurlencode($v) . '"',
        array_keys($oauth_params),
        $oauth_params
    ));

    // Step 5 – call the API
    $response = http_get($api_url, ['Authorization: ' . $auth_header]);
    $lista    = json_decode($response, true);
    $channel  = $lista['list'][0]['streams'][0]['src'];

    // Step 6 – rewrite URL and redirect
    $channel = str_replace(
        ['lb-cdn',          'alkawtharsd.m3u8'],
        ['s3-cdn',          '576p.m3u8'],
        $channel
    );

    $final = str_replace('https://s3-cdn.sepehrtv.ir', 'http://20.84.80.199', $channel);
    redirect($final);
}

// ─────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────

/** Perform a GET request; returns response body as string. */
function http_get(string $url, array $extra_headers = []): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_HTTPHEADER     => $extra_headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body ?: '';
}

/** Issue an HTTP redirect and exit. */
function redirect(string $url): void {
    header('Location: ' . $url, true, 302);
    exit;
}

/** Generate a random OAuth nonce. */
function generate_nonce(): string {
    return bin2hex(random_bytes(16));
}

/**
 * Build an OAuth 1.0 HMAC-SHA1 signature.
 *
 * @param string $method          HTTP method (GET / POST …)
 * @param string $url             Base URL (without query string for signing)
 * @param array  $oauth_params    OAuth parameters (no oauth_signature yet)
 * @param string $consumer_secret
 * @param string $token_secret
 */
function build_oauth_signature(
    string $method,
    string $url,
    array  $oauth_params,
    string $consumer_secret,
    string $token_secret
): string {
    // Parse query string out of URL so we can include it in the base string
    $parts     = parse_url($url);
    $base_url  = $parts['scheme'] . '://' . $parts['host'] . $parts['path'];

    $query_params = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query_params);
    }

    // Merge and sort all parameters
    $all_params = array_merge($query_params, $oauth_params);
    ksort($all_params);

    $param_string = http_build_query($all_params, '', '&', PHP_QUERY_RFC3986);

    $base_string = strtoupper($method) . '&'
                 . rawurlencode($base_url) . '&'
                 . rawurlencode($param_string);

    $signing_key = rawurlencode($consumer_secret) . '&' . rawurlencode($token_secret);

    return base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));
}
