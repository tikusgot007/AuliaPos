<?php

namespace App\Libraries;

/**
 * URL WebSocket realtime Inbox untuk browser (murni, tanpa framework),
 * dipakai `Inbox::apiRealtimeTicket()`.
 *
 * Halaman POS yang dilayani HTTPS tidak boleh membuka `ws://` ke host lain
 * (mixed content -> diblokir browser). Karena itu, di halaman aman, URL
 * dikembalikan sebagai jalur same-origin `wss://<host>/realtime-ws` yang
 * di-proxy Apache ke gateway (`httpd-ssl.conf` di server). Halaman HTTP
 * biasa (mis. dev) tetap memakai WebSocket langsung ke gateway.
 */
class InboxRealtimeWs
{
    public static function url(
        bool $isSecure,
        string $host,
        string $gatewayBaseUrl,
        string $proxyPath = '/realtime-ws'
    ): string {
        if ($isSecure && $host !== '') {
            return 'wss://' . $host . $proxyPath;
        }

        $baseUrl = rtrim($gatewayBaseUrl, '/');
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $wsScheme = $scheme === 'https' ? 'wss' : 'ws';
        $wsHost = (string) parse_url($baseUrl, PHP_URL_HOST);
        $wsPort = parse_url($baseUrl, PHP_URL_PORT);
        $wsAuthority = $wsHost . ($wsPort ? ':' . $wsPort : '');

        return $wsScheme . '://' . $wsAuthority . '/realtime';
    }
}
