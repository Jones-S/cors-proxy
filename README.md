Simply upload `index.php` and `.htaccess` by using an FTP client.

Then make request to  https://cors-proxy.jonasscheiwiller.ch/index.php with the following headers:

- `go-to-url`: Endpoint you want to request from without CORS rights.
- `Referer`: If the endpoint has some allowed referers
- `Authorization`: Use the necessary auth token

```
headers {
  go-to-url: https://api.spotify.com/v1/artists/4Z8W4fKeB5YxbusRsdQVPb
  Referer: http://localhost
  Authorization: Bearer YOUR_TOKEN
}
```
