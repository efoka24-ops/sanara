<?php
declare(strict_types=1);

namespace Sanara\Modules\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Rbac\RequirePermission;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

final class Routes
{
    public static function register(App $app, Services $s): void
    {
        $rf = $app->getResponseFactory();
        $json = static function (Response $res, mixed $data, int $status = 200): Response {
            $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            return $res->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
        };
        $limit = static function (Request $req, string $bucket, int $max, int $window) use ($s): void {
            $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
            $r = $s->limiter->hit("$bucket:$ip", $max, $window);
            if (!$r['allowed']) {
                throw new ApiException('too_many_requests', 429, ['retryAfter' => $r['retryAfter']]);
            }
        };
        $body = static fn (Request $r): array => (array) ($r->getParsedBody() ?? []);

        // --- Authentification ---
        $app->post('/auth/login', function (Request $req, Response $res) use ($s, $json, $limit, $body) {
            $limit($req, 'login', 10, 60);
            $b = $body($req);
            $r = $s->auth->login((string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''), $b['device_id'] ?? null);
            return $json($res, $r);
        });
        $app->post('/auth/refresh', function (Request $req, Response $res) use ($s, $json, $limit, $body) {
            $limit($req, 'refresh', 30, 60);
            return $json($res, $s->auth->refresh((string) ($body($req)['refresh'] ?? '')));
        });
        $app->post('/auth/logout', function (Request $req, Response $res) use ($s, $body) {
            $s->auth->logout((string) ($body($req)['refresh'] ?? ''));
            return $res->withStatus(204);
        });
        $app->get('/auth/me', function (Request $req, Response $res) use ($s, $json) {
            $id = (string) $req->getAttribute('userId');
            return $json($res, ['id' => $id, 'role' => $req->getAttribute('role'), 'tenant_id' => $req->getAttribute('tenantId'), 'permissions' => $s->authz->permissionsFor($id)]);
        })->add(new AuthMiddleware($s->jwt, $s->tenant));

        // --- Activation (US3) ---
        $app->get('/public/activation/{token}', function (Request $req, Response $res, array $a) use ($s, $json) {
            return $json($res, ['usable' => $s->activation->isUsable($a['token'])]);
        });
        $app->post('/public/activation/{token}', function (Request $req, Response $res, array $a) use ($s, $body, $limit) {
            $limit($req, 'activate', 10, 3600);
            $s->activation->activate($a['token'], (string) ($body($req)['password'] ?? ''));
            return $res->withStatus(204);
        });

        // --- Demandes d'accès (US1) ---
        $docs = static function (Request $req): array {
            $out = [];
            foreach ((array) ($req->getParsedBody()['documents'] ?? []) as $type => $d) {
                $bytes = is_array($d) ? base64_decode((string) ($d['base64'] ?? ''), true) : false;
                if ($bytes !== false) {
                    $out[(string) $type] = ['name' => (string) ($d['name'] ?? 'document'), 'bytes' => $bytes];
                }
            }
            foreach ($req->getUploadedFiles() as $type => $f) {
                if ($f->getError() === UPLOAD_ERR_OK) {
                    $out[(string) str_replace('doc_', '', (string) $type)] = ['name' => (string) $f->getClientFilename(), 'bytes' => (string) $f->getStream()];
                }
            }
            return $out;
        };
        $app->post('/public/applications', function (Request $req, Response $res) use ($s, $json, $limit, $body, $docs) {
            $limit($req, 'application', 5, 3600);
            return $json($res, $s->applications->submit($body($req), $docs($req)), 201);
        });
        $app->get('/public/applications/{code}/status', function (Request $req, Response $res, array $a) use ($s, $json) {
            return $json($res, $s->applications->status($a['code']));
        });
        $app->post('/public/applications/{code}/documents', function (Request $req, Response $res, array $a) use ($s, $limit, $docs, $body) {
            $limit($req, 'resend', 10, 3600);
            $type = (string) ($body($req)['doc_type'] ?? '');
            $d = $docs($req)[$type] ?? throw new ApiException('validation_failed', 422);
            $s->applications->resendDocument($a['code'], $type, $d['name'], $d['bytes']);
            return $res->withStatus(204);
        });

        // --- Console super admin (US2) ---
        $app->group('/admin', function (RouteCollectorProxy $g) use ($s, $json, $body, $rf) {
            $perm = fn (string $p) => new RequirePermission($s->authz, $p, $rf);
            $g->get('/applications', function (Request $req, Response $res) use ($s, $json) {
                $status = (string) ($req->getQueryParams()['status'] ?? '');
                $rows = $status !== ''
                    ? $s->db->all('SELECT id, number, status, pharmacy_name, city, region, owner_last_name, license_number, created_at FROM pharmacy_applications WHERE status = ? ORDER BY created_at DESC', [$status])
                    : $s->db->all('SELECT id, number, status, pharmacy_name, city, region, owner_last_name, license_number, created_at FROM pharmacy_applications ORDER BY created_at DESC');
                return $json($res, ['items' => $rows]);
            })->add($perm('applications.review'));
            $g->get('/applications/{id}', function (Request $req, Response $res, array $a) use ($s, $json) {
                $app = $s->db->one('SELECT * FROM pharmacy_applications WHERE id = ?', [$a['id']]) ?? throw new ApiException('not_found', 404);
                $docs = $s->db->all('SELECT id, doc_type, original_name, mime, size_bytes, storage_id FROM application_documents WHERE application_id = ? AND superseded = 0', [$a['id']]);
                foreach ($docs as &$d) {
                    $d['url'] = '/admin/documents/' . $d['storage_id'] . '?' . http_build_query($s->storage->sign((string) $d['storage_id'], 300));
                    unset($d['storage_id']);
                }
                unset($app['tracking_code']);
                return $json($res, ['application' => $app, 'documents' => $docs]);
            })->add($perm('applications.review'));
            $g->post('/applications/{id}/approve', function (Request $req, Response $res, array $a) use ($s, $json, $body) {
                $b = $body($req);
                $r = $s->review->approve($a['id'], (string) $req->getAttribute('userId'), (string) ($b['plan'] ?? 'trial'), (int) ($b['pos_count'] ?? 1));
                return $json($res, ['tenant_id' => $r['tenant_id']]);
            })->add($perm('applications.review'));
            $g->post('/applications/{id}/reject', function (Request $req, Response $res, array $a) use ($s, $body) {
                $b = $body($req);
                $s->review->reject($a['id'], (string) $req->getAttribute('userId'), (string) ($b['reason'] ?? ''), $b['note'] ?? null, !empty($b['allow_reapply']));
                return $res->withStatus(204);
            })->add($perm('applications.review'));
            $g->post('/applications/{id}/request-info', function (Request $req, Response $res, array $a) use ($s, $body) {
                $b = $body($req);
                $s->review->requestInfo($a['id'], (string) $req->getAttribute('userId'), (array) ($b['docs'] ?? []), (string) ($b['message'] ?? ''));
                return $res->withStatus(204);
            })->add($perm('applications.review'));
        })->add(new AuthMiddleware($s->jwt, $s->tenant));

        // Document chiffré : accès uniquement par URL signée à durée courte.
        $app->get('/admin/documents/{id}', function (Request $req, Response $res, array $a) use ($s) {
            $q = $req->getQueryParams();
            if (!$s->storage->verify($a['id'], (int) ($q['exp'] ?? 0), (string) ($q['sig'] ?? ''))) {
                throw new ApiException('forbidden', 403);
            }
            $meta = $s->db->one('SELECT mime, original_name FROM application_documents WHERE storage_id = ?', [$a['id']]) ?? throw new ApiException('not_found', 404);
            $res->getBody()->write($s->storage->get($a['id']));
            return $res->withHeader('Content-Type', (string) $meta['mime'])->withHeader('Content-Disposition', 'inline')->withHeader('X-Content-Type-Options', 'nosniff')->withHeader('Cache-Control', 'private, no-store');
        });
    }
}
