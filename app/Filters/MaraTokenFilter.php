<?php

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;
use Config\Mara as MaraConfig;

/**
 * Filter autentikasi khusus endpoint machine-to-machine M.A.R.A
 * (/api/inbox/mara/*). Pola SAMA PERSIS dengan GatewayTokenFilter
 * (WA-Gateway) -- lihat catatan lengkap di sana -- tapi SENGAJA
 * token terpisah (Config\Mara::$apiToken, BUKAN Config\Inbox::
 * $gatewayToken) supaya M.A.R.A dan WA-Gateway adalah dua identitas
 * klien yang independen: mencabut/merotasi akses salah satu tidak
 * memutus yang lain.
 *
 * Header: "Authorization: Bearer ***", dibandingkan dengan
 * hash_equals() (timing-safe).
 */
class MaraTokenFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $configuredToken = (new MaraConfig())->apiToken;

        if ($configuredToken === '') {
            log_message('critical', 'MARA_API_TOKEN belum dikonfigurasi di .env (mara.apiToken kosong). Semua request M.A.R.A ditolak.');

            return service('response')
                ->setStatusCode(503)
                ->setJSON(['status' => 'error', 'message' => 'M.A.R.A belum dikonfigurasi di server.']);
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

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Tidak ada yang perlu dilakukan setelah request.
    }
}