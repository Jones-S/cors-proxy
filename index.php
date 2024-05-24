<?php
require "vendor/autoload.php";
$client = new GuzzleHttp\Client();

header("Content-Type: application/json; charset=utf-8");

$method = $_POST["method"] ?? null;
$url = $_POST["endpoint"] ?? null;
$auth = $_POST["auth"] ?? null;

if (empty($method) || empty($url)) {
  echo json_encode([
    "status" => 400,
    "message" => "Both method and endpoint are required",
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
