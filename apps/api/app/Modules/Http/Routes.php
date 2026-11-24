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
    /** Envoie les e-mails en attente après la réponse : filet de sécurité si le cron n'est pas encore configuré. */
    private static function drainLater(Services $s): void
    {
        if ($s->config->env === 'testing') {
            return;
        }
        register_shutdown_function(static function () use ($s): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                $s->mail->drain(3);
            } catch (\Throwable) {
            }
        });
    }

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

        // Déclencheur des tâches de fond pour le cron Camoo (URL appelée chaque minute) ; clé secrète obligatoire.
        $app->get('/internal/run-jobs', function (Request $req, Response $res) use ($s, $json) {
            $key = (string) ($req->getQueryParams()['key'] ?? '');
            if ($s->config->cronKey === '' || !hash_equals($s->config->cronKey, $key)) {
                throw new ApiException('forbidden', 403);
            }
            return $json($res, ['mails_sent' => $s->mail->drain(20)]);
        });

        // --- Authentification ---
        $app->post('/auth/login', function (Request $req, Response $res) use ($s, $json, $limit, $body) {
            $limit($req, 'login', $s->config->env === 'testing' ? 1000 : 10, 60);
            $b = $body($req);
            $r = $s->auth->login((string) ($b['email'] ?? ''), (string) ($b['password'] ?? ''), $b['device_id'] ?? null, $b['device_label'] ?? null);
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
            $setup = $req->getAttribute('scope') === 'setup';
            return $json($res, [
                'id' => $id, 'role' => $req->getAttribute('role'), 'tenant_id' => $req->getAttribute('tenantId'),
                'permissions' => $setup ? [] : $s->authz->permissionsFor($id), 'mfa_setup_required' => $setup, 'mfa_enabled' => $s->mfa->isEnabled($id),
            ]);
        })->add(new AuthMiddleware($s->jwt, $s->tenant, true));

        // --- Double vérification (US5) ---
        $app->post('/auth/mfa/verify', function (Request $req, Response $res) use ($s, $json, $limit, $body) {
            $limit($req, 'mfa', $s->config->env === 'testing' ? 1000 : 10, 60);
            $b = $body($req);
            return $json($res, $s->auth->completeMfa((string) ($b['mfa_token'] ?? ''), (string) ($b['code'] ?? '')));
        });
        $app->group('/auth/mfa', function (RouteCollectorProxy $g) use ($s, $json, $body) {
            $g->post('/begin', fn (Request $q, Response $r) => $json($r, $s->mfa->begin((string) $q->getAttribute('userId'))));
            $g->post('/confirm', function (Request $q, Response $r) use ($s, $json, $body) {
                $codes = $s->mfa->confirm((string) $q->getAttribute('userId'), (string) ($body($q)['code'] ?? ''));
                $s->audit->record('mfa.enable', ['user_id' => (string) $q->getAttribute('userId'), 'tenant_id' => $q->getAttribute('tenantId')]);
                return $json($r, ['backup_codes' => $codes]);
            });
            $g->post('/disable', function (Request $q, Response $r) use ($s, $body) {
                $s->mfa->disable((string) $q->getAttribute('userId'), (string) ($body($q)['code'] ?? ''));
                $s->audit->record('mfa.disable', ['user_id' => (string) $q->getAttribute('userId'), 'tenant_id' => $q->getAttribute('tenantId')]);
                return $r->withStatus(204);
            });
        })->add(new AuthMiddleware($s->jwt, $s->tenant, true));

        // --- Mot de passe oublié ---
        $app->post('/auth/password/forgot', function (Request $req, Response $res) use ($s, $limit, $body) {
            $limit($req, 'forgot', 5, 3600);
            $s->passwordReset->request((string) ($body($req)['email'] ?? ''));
            self::drainLater($s);
            return $res->withStatus(204);
        });
        $app->get('/auth/password/reset/{token}', fn (Request $q, Response $r, array $a) => $json($r, ['usable' => $s->passwordReset->isUsable($a['token'])]));
        $app->post('/auth/password/reset/{token}', function (Request $req, Response $res, array $a) use ($s, $limit, $body) {
            $limit($req, 'reset', 10, 3600);
            $s->passwordReset->reset($a['token'], (string) ($body($req)['password'] ?? ''));
            return $res->withStatus(204);
        });

        // --- Sessions et PIN de l'utilisateur connecté ---
        $app->group('/auth', function (RouteCollectorProxy $g) use ($s, $json, $body, $limit) {
            $g->get('/sessions', fn (Request $q, Response $r) => $json($r, ['sessions' => $s->sessions->list((string) $q->getAttribute('userId'))]));
            $g->delete('/sessions/{family}', function (Request $q, Response $r, array $a) use ($s) {
                $s->sessions->revoke((string) $q->getAttribute('userId'), $a['family']);
                return $r->withStatus(204);
            });
            $g->post('/sessions/revoke-all', function (Request $q, Response $r) use ($s) {
                $s->sessions->revokeAll((string) $q->getAttribute('userId'));
                return $r->withStatus(204);
            });
            $g->put('/pin', function (Request $q, Response $r) use ($s, $body) {
                $s->pin->set((string) $q->getAttribute('userId'), (string) ($body($q)['pin'] ?? ''));
                return $r->withStatus(204);
            });
            $g->post('/pin/verify', function (Request $q, Response $r) use ($s, $json, $body, $limit) {
                $limit($q, 'pin', 30, 60);
                return $json($r, ['valid' => $s->pin->verify((string) $q->getAttribute('userId'), (string) ($body($q)['pin'] ?? ''))]);
            });
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

        $app->post('/public/activation-resend', function (Request $req, Response $res) use ($s, $limit, $body) {
            $limit($req, 'resend-activation', 5, 3600);
            $s->activationResend->resend((string) ($body($req)['email'] ?? ''));
            self::drainLater($s);
            return $res->withStatus(204); // même réponse que le compte existe ou non
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
            $out = $s->applications->submit($body($req), $docs($req));
            self::drainLater($s);
            return $json($res, $out, 201);
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
            $perm = fn (string $p) => new RequirePermission($s->authz, $p, $rf, $s->audit);
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
                self::drainLater($s);
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

        // --- Espace pharmacie : paramètres, configuration initiale, équipe (US3, US4) ---
        $app->group('/app', function (RouteCollectorProxy $g) use ($s, $json, $body, $rf) {
            $perm = fn (string $p) => new RequirePermission($s->authz, $p, $rf, $s->audit);
            $tenantId = static fn (Request $r): string => (string) ($r->getAttribute('tenantId') ?? throw new ApiException('forbidden', 403));
            $actor = static fn (Request $r): string => (string) $r->getAttribute('userId');

            $g->get('/settings', fn (Request $q, Response $r) => $json($r, ['settings' => $s->setup->settings(), 'opening_hours' => $s->setup->openingHours()]))->add($perm('settings.write'));
            $g->put('/settings', function (Request $q, Response $r) use ($s, $body) {
                $s->setup->setSettings((array) ($body($q)['settings'] ?? []));
                return $r->withStatus(204);
            })->add($perm('settings.write'));
            $g->put('/opening-hours', function (Request $q, Response $r) use ($s, $body) {
                $s->setup->setOpeningHours((array) ($body($q)['days'] ?? []));
                return $r->withStatus(204);
            })->add($perm('settings.write'));
            $g->put('/visibility', function (Request $q, Response $r) use ($s, $body) {
                $s->setup->setVisibility(!empty($body($q)['visible']));
                return $r->withStatus(204);
            })->add($perm('settings.write'));
            $g->get('/onboarding', fn (Request $q, Response $r) => $json($r, $s->setup->onboarding()))->add($perm('settings.write'));
            $g->post('/onboarding/{step}', function (Request $q, Response $r, array $a) use ($s, $body, $json) {
                !empty($body($q)['skip']) ? $s->setup->skipStep($a['step']) : $s->setup->completeStep($a['step']);
                return $json($r, $s->setup->onboarding());
            })->add($perm('settings.write'));

            // --- Catalogue (US6) ---
            $g->get('/products', fn (Request $q, Response $r) => $json($r, $s->catalog->search((string) ($q->getQueryParams()['q'] ?? ''), array_map('strval', $q->getQueryParams()))))->add($perm('products.read'));
            $g->get('/products/export', function (Request $q, Response $r) use ($s) {
                $r->getBody()->write($s->catalogTransfer->export());
                return $r->withHeader('Content-Type', 'text/csv; charset=utf-8')->withHeader('Content-Disposition', 'attachment; filename="catalogue.csv"');
            })->add($perm('products.read'));
            $g->post('/products/import', function (Request $q, Response $r) use ($s, $json, $body, $actor) {
                $s->audit->record('catalog.import', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId')]);
                return $json($r, $s->catalogTransfer->import((string) ($body($q)['csv'] ?? ''), $actor($q)));
            })->add($perm('products.write'));
            $g->post('/products/reference', function (Request $q, Response $r) use ($s, $json, $actor) {
                return $json($r, ['added' => $s->reference->importInto('CM', $actor($q))]);
            })->add($perm('products.write'));
            $g->get('/products/{id}', fn (Request $q, Response $r, array $a) => $json($r, $s->catalog->get($a['id'])))->add($perm('products.read'));
            $g->post('/products', fn (Request $q, Response $r) => $json($r, ['id' => $s->catalog->create($body($q), (string) $q->getAttribute('userId'))], 201))->add($perm('products.write'));
            $g->put('/products/{id}', function (Request $q, Response $r, array $a) use ($s, $body, $actor) {
                $s->catalog->update($a['id'], $body($q), $actor($q));
                return $r->withStatus(204);
            })->add($perm('products.write'));
            $g->post('/products/{id}/active', function (Request $q, Response $r, array $a) use ($s, $body, $actor) {
                $s->catalog->setActive($a['id'], !empty($body($q)['active']), $actor($q));
                return $r->withStatus(204);
            })->add($perm('products.write'));
            $g->get('/families', fn (Request $q, Response $r) => $json($r, ['items' => $s->catalog->families()]))->add($perm('products.read'));
            $g->post('/families', fn (Request $q, Response $r) => $json($r, ['id' => $s->catalog->createFamily((string) ($body($q)['name'] ?? ''))], 201))->add($perm('products.write'));
            $g->get('/generic-groups', fn (Request $q, Response $r) => $json($r, ['items' => $s->catalog->groups()]))->add($perm('products.read'));
            $g->post('/generic-groups', fn (Request $q, Response $r) => $json($r, ['id' => $s->catalog->createGroup((string) ($body($q)['name'] ?? ''))], 201))->add($perm('products.write'));

            $g->get('/team', fn (Request $q, Response $r) => $json($r, ['members' => $s->team->members($tenantId($q))]))->add($perm('team.manage'));
            $g->post('/team', function (Request $q, Response $r) use ($s, $body, $json, $tenantId, $actor) {
                return $json($r, ['id' => $s->team->invite($tenantId($q), $actor($q), $body($q))], 201);
            })->add($perm('team.manage'));
            $g->put('/team/{id}/role', function (Request $q, Response $r, array $a) use ($s, $body, $tenantId, $actor) {
                $s->team->changeRole($tenantId($q), $actor($q), $a['id'], (string) ($body($q)['role'] ?? ''));
                return $r->withStatus(204);
            })->add($perm('team.manage'));
            $g->post('/team/{id}/deactivate', function (Request $q, Response $r, array $a) use ($s, $tenantId, $actor) {
                $s->team->deactivate($tenantId($q), $actor($q), $a['id']);
                return $r->withStatus(204);
            })->add($perm('team.manage'));
            $g->post('/team/{id}/reactivate', function (Request $q, Response $r, array $a) use ($s, $tenantId, $actor) {
                $s->team->reactivate($tenantId($q), $actor($q), $a['id']);
                return $r->withStatus(204);
            })->add($perm('team.manage'));
            $g->put('/team/{id}/permissions/{code}', function (Request $q, Response $r, array $a) use ($s, $body, $tenantId, $actor) {
                $s->team->setOverride($tenantId($q), $actor($q), $a['id'], $a['code'], !empty($body($q)['granted']));
                return $r->withStatus(204);
            })->add($perm('team.manage'));
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
