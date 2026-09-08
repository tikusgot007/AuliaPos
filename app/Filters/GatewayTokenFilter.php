<?php

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;
use Config\Inbox as InboxConfig;

/**
 * Filter autentikasi khusus endpoint machine-to-machine Gateway
 * WhatsApp (/api/inbox/gateway/*). BUKAN session-based seperti
 * AuthFilter -- Gateway (proses Node.js terpisah) tidak pernah
 * punya cookie session, jadi tidak bisa lewat filter 'auth' biasa
 * (endpoint ini memang sengaja dikecualikan dari situ, lihat
 * app/Config/Filters.php).
 *
 * Cara kerja: header "Authorization: Bearer <token>" dibandingkan
 * dengan token yang dikonfigurasi di Config\Inbox::$gatewayToken
 * (diisi lewat .env, lihat app/Config/Inbox.php).
 *
 * Perbandingan pakai hash_equals() (timing-safe) supaya tidak bocor
 * informasi lewat perbedaan waktu respons.
 */
class GatewayTokenFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $configuredToken = (new InboxConfig())->gatewayToken;

        // Kalau token belum dikonfigurasi sama sekali di server (lupa
        // isi .env), TOLAK semua request -- jangan pernah menganggap
        // "token kosong" di kedua sisi sebagai valid, itu lubang
        // keamanan yang jelas.
        if ($configuredToken === '') {
            log_message('critical', 'INBOX_GATEWAY_TOKEN belum dikonfigurasi di .env (inbox.gatewayToken kosong). Semua request Gateway ditolak.');

            return service('response')
                ->setStatusCode(503)
                ->setJSON(['status' => 'error', 'message' => 'Gateway belum dikonfigurasi di server.']);
        }

        $header = $request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['status' => 'error', 'message' => 'Authorization header tidak ada atau formatnya salah.']);
        }

        $providedToken = trim($matches[1]);

        if (!hash_equals($configuredToken, $providedToken)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['status' => 'error', 'message' => 'Token tidak valid.']);
        }

        // Token valid, lanjutkan ke controller.
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Tidak ada yang perlu dilakukan setelah request.
    }
}
