<?php
require "vendor/autoload.php";
$client = new GuzzleHttp\Client();

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type");

$method = $_POST["method"] ?? "GET";
$url = $_POST["endpoint"] ?? null;
$auth = $_POST["auth"] ?? "";

if (empty($url)) {
  echo json_encode([
    "status" => 400,
    "message" => "Endpoint is required",
    "request" => json_encode([
      "method" => $method,
      "endpoint" => $url,
      "auth" => $auth,
    ]),
  ]);
  exit();
}

// works for toggl api for now
// if it should work more generically one needs to add more GET params to determin how the request has to look like
$response = $client->request($method, $url, [
  "auth" => [$auth, "api_token"],
]);

$status = $response->getStatusCode();
$contentType = $response->getHeaderLine("Content-Type");
$body = $response->getBody()->getContents();
$data = json_decode($body, true);

echo json_encode([
  "status" => $status,
  "message" => "Data successfully proxied",
  "data" => $data,
]);
