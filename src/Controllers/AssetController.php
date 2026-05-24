<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\TipoTurnoModel;
use App\Routing\Request;
use App\Routing\Response;

/**
 * Asset generati a runtime.
 *
 * `tipiTurnoCss`: foglio di stile coi colori dei tipi turno espressi come CLASSI
 * (`.tt-bg-{id}`), non come `style=` inline. Motivo: la CSP dell'app è
 * `style-src 'self'` (vedi SecurityHeaders) e blocca ogni attributo di stile
 * inline; uno stylesheet servito dalla stessa origine è invece permesso.
 *
 * Ogni regola imposta DUE proprietà:
 *  - `--bs-table-bg`: per le celle dentro `.table`, dove Bootstrap 5.3 ridipinge
 *    lo sfondo con `background-color: var(--bs-table-bg)` (un `background-color`
 *    secco verrebbe ignorato dal meccanismo della tabella);
 *  - `background-color`: per gli elementi FUORI tabella che riusano la classe
 *    (es. la banda laterale cross-setting).
 *
 * Solo colori esadecimali `#RRGGBB` validi vengono emessi (no CSS injection).
 */
final class AssetController extends BaseController
{
    public function tipiTurnoCss(Request $request): Response
    {
        $css = "/* colori tipi turno — generato da AssetController (CSP-safe, niente style inline) */\n";
        foreach ((new TipoTurnoModel())->listOrdered() as $t) {
            $colore = (string) ($t['colore'] ?? '');
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $colore) !== 1) {
                continue;
            }
            $id = (int) $t['id'];
            $css .= ".tt-bg-{$id}{--bs-table-bg:{$colore};background-color:{$colore};}\n";
        }

        // ETag + revalidazione: i colori cambiano di rado (admin), ma quando
        // cambiano si vedono al reload successivo senza attese di cache.
        $etag = '"' . md5($css) . '"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'no-cache'];

        $ifNoneMatch = isset($request->server['HTTP_IF_NONE_MATCH'])
            ? trim((string) $request->server['HTTP_IF_NONE_MATCH'])
            : null;
        if ($ifNoneMatch === $etag) {
            $resp = new Response('', 304);
        } else {
            $resp = new Response($css, 200, 'text/css; charset=utf-8');
        }
        foreach ($headers as $name => $value) {
            $resp->setHeader($name, $value);
        }
        return $resp;
    }
}
