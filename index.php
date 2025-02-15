<?php

/**
 * Copied from: https://github.com/Svish/php-cross-domain-proxy
 * @param whitelist
 * @param curl_opts
 * @param zlib
 */

// Example of a whitelist
$whitelist =
  $_SERVER["SERVER_NAME"] == "localhost"
    ? []
    : [
      // Exact matching
      // ["http://www.yr.no/place/Sweden/Stockholm/Stockholm/forecast.xml"],

      // URL component matching
      ["host" => "localhost"],
      ["host" => "api.track.toggl.com"],
      ["host" => "example.com", "scheme" => "https"],
      // use a dummy api for testing; https://dummyjson.com/docs/products#products-all
      ["host" => "dummyjson.com"],
      ["host" => "api.spotify.com"],

      // Regex matching
      ["regex" => "%^http://www.yr.no/place/Norway/%"],
    ];

// Get normalized headers and such
$headers = array_change_key_case(getallheaders());
$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
$url = $headers["go-to-url"] ?? null;
$cookie = $headers["x-proxy-cookie"] ?? null;

$authorization =
  $headers["authorization"] ?? ($_SERVER["HTTP_AUTHORIZATION"] ?? null);

if ($authorization) {
  $headers["Authorization"] = $authorization;
}

// Check that we have a URL
if (!$url) {
  failure(400, "Go-To-Url header missing");
}

// Check that the URL looks like an absolute URL
if (!parse_url($url, PHP_URL_SCHEME)) {
  failure(400, "Not an absolute URL: $url");
}

// Check referer hostname
if (
  !parse_url($headers["referer"] ?? null, PHP_URL_HOST) == $_SERVER["HTTP_HOST"]
) {
  failure(403, "Invalid referer");
}

// Check whitelist, if not empty
if (!array_reduce($whitelist ?? [], "is_bad", [$url, false])) {
  failure(403, "Not whitelisted: $url");
}

// Remove ignored headers
$ignore = ["cookie", "content-length", "host", "go-to-url", "x-proxy-cookie"];
$headers = array_diff_key($headers, array_flip($ignore));

// Set proxied cookie if we got one
if ($cookie) {
  $headers["Cookie"] = $cookie;
}

// Format headers for curl
foreach ($headers as $key => &$value) {
  $value = ucwords($key, "-") . ": $value";
}

$headerStrings = array_values($headers);

// Init curl
$curl = curl_init();
$maxredirs = $opts[CURLOPT_MAXREDIRS] ?? 20;
do {
  // Set options
  curl_setopt_array(
    $curl,
    [
      CURLOPT_URL => $url,
      CURLOPT_HTTPHEADER => $headerStrings,
      CURLOPT_HEADER => true,
    ] +
      ($curl_opts ?? []) + [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => $maxredirs,
      ]
  );

  // Method specific options
  switch ($method) {
    case "HEAD":
      curl_setopt($curl, CURLOPT_NOBODY, true);
      break;

    case "GET":
      break;

    case "PUT":
    case "POST":
    case "DELETE":
    default:
      curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
      curl_setopt($curl, CURLOPT_POSTFIELDS, file_get_contents("php://input"));
      break;
  }

  // Perform request
  ob_start();
  curl_exec($curl);
  $out = ob_get_clean();

  // Log curl command settings
  $curlInfo = curl_getinfo($curl);
  $curlCommand = "curl -X $method";
  foreach ($headers as $key => $value) {
    $curlCommand .= " -H '$key: $value'";
  }
  if ($method === "PUT" || $method === "POST" || $method === "DELETE") {
    $curlCommand .= " -d '" . file_get_contents("php://input") . "'";
  }
  $curlCommand .= " '$url'";
  error_log($curlCommand);

  // Light error handling
  if (curl_errno($curl)) {
    switch (curl_errno($curl)) {
      // Connect timeout => Service Unavailable
      case 7:
        failure(503, $curl);

      // Operation timeout => Gateway Timeout
      case 28:
        failure(504, $curl);

      // Other errors => Service Unavailable
      default:
        failure(503, $curl);
    }
  }

  // HACK: Workaround if not following, which happened once...
  $url = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
} while ($url and --$maxredirs > 0);

// Get curl info and close handler
$info = curl_getinfo($curl);

// echo json_encode($info);
// return;
curl_close($curl);

// Remove any existing headers
header_remove();

// Use zlib, if acceptable
ini_set("zlib.output_compression", $zlib ?? "On");

// Get content and headers
$content = substr($out, $info["header_size"]);
$headers = substr($out, 0, $info["header_size"]);

// Rename Set-Cookie header
$headers = preg_replace("/^Set-Cookie:/im", "X-Proxy-Set-Cookie:", $headers);

// Output headers
foreach (explode("\r\n", $headers) as $h) {
  // HACK: Prevent chunked encoding issues (Issue #1)
  if (!preg_match("/^Transfer-Encoding:/i", $h)) {
    header($h, false);
  }
}

// HACK: Prevent gzip issue (Issue #1)
// header("Content-Length: " . 5000, true);
header("Content-Length: " . strlen($content), true);

// Output content
echo $content;

function failure(int $status, $text)
{
  if (is_resource($text)) {
    $text = sprintf("[%s] %s", curl_errno($text), curl_error($text));
  }
  http_response_code($status);
  header("X-Proxy-Curl-Error: $text");
  exit($text);
}

function is_bad($carry, array $rule): bool
{
  static $url;
  if (is_array($carry)) {
    $url = parse_url($carry[0]);
    $url["raw"] = $carry[0];
    $carry = $carry[1];
  }

  // Equals full URL
  if (isset($rule[0])) {
    return $carry or $url["raw"] == $rule[0];
  }

  // Regex matches URL
  if (isset($rule["regex"])) {
    return $carry or preg_match($rule["regex"], $url["raw"]);
  }

  // Components in rule matches same components in URL
  return $carry or $rule == array_intersect_key($url, $rule);
}
