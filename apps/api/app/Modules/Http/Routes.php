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
            $daily = $s->limiter->hit('daily-stock', 1, 3600)['allowed'] ? $s->runDaily() : 0;
            return $json($res, ['mails_sent' => $s->mail->drain(20), 'daily_alerts' => $daily]);
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

        $app->get('/public/pharmacies', function (Request $req, Response $res) use ($s, $json) {
            $q = trim((string) ($req->getQueryParams()['q'] ?? ''));
            $region = trim((string) ($req->getQueryParams()['region'] ?? ''));
            $city = trim((string) ($req->getQueryParams()['city'] ?? ''));
            $limit = max(1, min(100, (int) ($req->getQueryParams()['limit'] ?? 20)));
            $offset = max(0, (int) ($req->getQueryParams()['offset'] ?? 0));
            return $json($res, $s->publicPharmacies->search($q, $region, $city, $limit, $offset));
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
            $b = $body($req);
            $s->captcha->verify(isset($b['captcha_token']) ? (string) $b['captcha_token'] : null);
            $out = $s->applications->submit($b, $docs($req));
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

            // --- Console : tableau de bord, pharmacies, formules, facturation, signalements, modèles, audit, paramètres, pays ---
            $admin = static fn (Request $q): string => (string) $q->getAttribute('userId');
            $qs = static fn (Request $q, string $k): ?string => isset($q->getQueryParams()[$k]) ? (string) $q->getQueryParams()[$k] : null;
            $audit = static fn (Request $q, string $action, array $extra = []) => $s->audit->record($action, ['user_id' => (string) $q->getAttribute('userId')] + $extra);
            $g->get('/dashboard', fn (Request $q, Response $r) => $json($r, $s->admin->dashboard()))->add($perm('platform.manage'));
            $g->get('/pharmacies', fn (Request $q, Response $r) => $json($r, ['items' => $s->admin->pharmacies($qs($q, 'q'), $qs($q, 'status'))]))->add($perm('platform.manage'));
            $g->get('/pharmacies/{id}', fn (Request $q, Response $r, array $a) => $json($r, $s->admin->pharmacy($a['id'])))->add($perm('platform.manage'));
            $g->post('/pharmacies/{id}/suspend', function (Request $q, Response $r, array $a) use ($s, $body, $admin, $audit) {
                $s->admin->suspend($a['id'], (string) ($body($q)['reason'] ?? ''), $admin($q));
                $audit($q, 'pharmacy.suspend', ['tenant_id' => $a['id'], 'reason' => (string) ($body($q)['reason'] ?? '')]);
                return $r->withStatus(204);
            })->add($perm('platform.manage'));
            $g->post('/pharmacies/{id}/reactivate', function (Request $q, Response $r, array $a) use ($s, $audit) {
                $s->admin->reactivate($a['id']);
                $audit($q, 'pharmacy.reactivate', ['tenant_id' => $a['id']]);
                return $r->withStatus(204);
            })->add($perm('platform.manage'));
            $g->put('/pharmacies/{id}/subscription', function (Request $q, Response $r, array $a) use ($s, $body, $audit) {
                $b = $body($q);
                $s->admin->setSubscription($a['id'], (string) ($b['plan_id'] ?? ''), (int) ($b['stations'] ?? 1), (string) ($b['status'] ?? 'trial'));
                $audit($q, 'subscription.set', ['tenant_id' => $a['id'], 'plan_id' => (string) ($b['plan_id'] ?? '')]);
                return $r->withStatus(204);
            })->add($perm('platform.manage'));
            $g->get('/plans', fn (Request $q, Response $r) => $json($r, ['items' => $s->admin->plans()]))->add($perm('platform.manage'));
            $g->post('/plans', fn (Request $q, Response $r) => $json($r, ['id' => $s->admin->savePlan(null, $body($q))], 201))->add($perm('platform.manage'));
            $g->put('/plans/{id}', function (Request $q, Response $r, array $a) use ($s, $body, $audit) {
                $s->admin->savePlan($a['id'], $body($q));
                $audit($q, 'plan.update', ['plan_id' => $a['id']]);
                return $r->withStatus(204);
            })->add($perm('platform.manage'));
            $g->get('/invoices', fn (Request $q, Response $r) => $json($r, ['items' => $s->admin->invoices($qs($q, 'status'))]))->add($perm('platform.manage'));
            $g->post('/invoices', function (Request $q, Response $r) use ($s, $json, $body, $audit) {
                $b = $body($q);
                $id = $s->admin->createInvoice((string) ($b['pharmacy_id'] ?? ''), (int) ($b['amount'] ?? 0), (int) ($b['due_days'] ?? 15));
                $audit($q, 'invoice.create', ['invoice_id' => $id]);
                return $json($r, ['id' => $id], 201);
            })->add($perm('platform.manage'));
            $g->post('/invoices/{id}/paid', function (Request $q, Response $r, array $a) use ($s, $body, $audit) {
                $s->admin->markPaid($a['id'], (string) ($body($q)['method'] ?? ''), (string) ($body($q)['reference'] ?? ''));
                $audit($q, 'invoice.paid', ['invoice_id' => $a['id']]);
                return $r->withStatus(204);
            })->add($perm('platform.manage'));
            $g->post('/invoices/{id}/void', function (Request $q, Response $r, array $a) use ($s, $audit) {
                $s->admin->voidInvoice($a['id']);
                $audit($q, 'invoice.void', ['invoice_id' => $a['id']]);
                return $r->withStatus(204);
            })->add($perm('platform.manage'));
            $g->get('/reports', fn (Request $q, Response $r) => $json($r, ['items' => $s->admin->reports($qs($q, 'status'))]))->add($perm('platform.manage'));
            $g->post('/reports', function (Request $q, Response $r) use ($s, $json, $body) {
                $b = $body($q);
                return $json($r, ['id' => $s->admin->createReport((string) ($b['kind'] ?? 'other'), isset($b['pharmacy_id']) ? (string) $b['pharmacy_id'] : null, (string) ($b['subject'] ?? ''), isset($b['details']) ? (string) $b['details'] : null)], 201);
            })->add($perm('platform.manage'));
            $g->post('/reports/{id}/handle', function (Request $q, Response $r, array $a) use ($s, $body, $admin, $audit) {
                $b = $body($q);
                $s->admin->handleReport($a['id'], (string) ($b['decision'] ?? ''), isset($b['note']) ? (string) $b['note'] : null, $admin($q));
                $audit($q, 'report.handle', ['report_id' => $a['id'], 'decision' => (string) ($b['decision'] ?? '')]);
                return $r->withStatus(204);
            })->add($perm('platform.manage'));
            $g->get('/templates', fn (Request $q, Response $r) => $json($r, ['items' => $s->admin->templates()]))->add($perm('platform.manage'));
            $g->put('/templates/{code}/{locale}', function (Request $q, Response $r, array $a) use ($s, $body, $admin, $audit) {
                $b = $body($q);
                $s->admin->saveTemplate($a['code'], $a['locale'], (string) ($b['subject'] ?? ''), (string) ($b['body'] ?? ''), $admin($q));
                $audit($q, 'template.update', ['entity' => $a['code'] . '/' . $a['locale']]);
                return $r->withStatus(204);
            })->add($perm('platform.manage'));
            $g->delete('/templates/{code}/{locale}', function (Request $q, Response $r, array $a) use ($s, $audit) {
                $s->admin->resetTemplate($a['code'], $a['locale']);
                $audit($q, 'template.reset', ['entity' => $a['code'] . '/' . $a['locale']]);
                return $r->withStatus(204);
            })->add($perm('platform.manage'));
            $g->post('/templates/{code}/{locale}/preview', function (Request $q, Response $r, array $a) use ($s, $json, $body) {
                $b = $body($q);
                return $json($r, $s->admin->previewTemplate($a['code'], $a['locale'], isset($b['subject']) ? (string) $b['subject'] : null, isset($b['body']) ? (string) $b['body'] : null));
            })->add($perm('platform.manage'));
            $g->get('/audit', fn (Request $q, Response $r) => $json($r, ['items' => $s->admin->audit($qs($q, 'action'), $qs($q, 'tenant_id'), $qs($q, 'from'), $qs($q, 'to'), (int) ($qs($q, 'limit') ?? 100))]))->add($perm('platform.manage'));
            $g->get('/settings', fn (Request $q, Response $r) => $json($r, ['settings' => $s->admin->settings()]))->add($perm('platform.manage'));
            $g->put('/settings', function (Request $q, Response $r) use ($s, $body, $audit) {
                $s->admin->setSettings((array) ($body($q)['settings'] ?? []));
                $audit($q, 'platform.settings', []);
                return $r->withStatus(204);
            })->add($perm('platform.manage'));
            $g->get('/countries', fn (Request $q, Response $r) => $json($r, ['items' => $s->admin->countries()]))->add($perm('platform.manage'));
            $g->put('/countries/{code}', function (Request $q, Response $r, array $a) use ($s, $body, $audit) {
                $s->admin->updateCountry(strtoupper($a['code']), $body($q));
                $audit($q, 'country.update', ['entity' => strtoupper($a['code'])]);
                return $r->withStatus(204);
            })->add($perm('platform.manage'));
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
            $g->put('/visibility', function (Request $q, Response $r) use ($s, $body, $tenantId) {
                $s->setup->setVisibility(!empty($body($q)['visible']));
                $s->publicProjection->refresh($tenantId($q));
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

            // --- Stock par lot (US7) ---
            $g->post('/stock/receipts', function (Request $q, Response $r) use ($s, $json, $body, $actor) {
                $id = $s->stock->receive($body($q), $actor($q));
                $s->audit->record('stock.receipt', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'movement_id' => $id]);
                return $json($r, ['movement_id' => $id, 'short_dated' => $s->stock->shortDated((string) ($body($q)['expiry'] ?? '9999-12-31'))], 201);
            })->add($perm('stock.write'));
            $g->get('/stock/products/{id}/lots', fn (Request $q, Response $r, array $a) => $json($r, ['items' => $s->stock->listLots($a['id'])]))->add($perm('stock.read'));
            $g->get('/stock/products/{id}/on-hand', fn (Request $q, Response $r, array $a) => $json($r, $s->stock->onHand($a['id'])))->add($perm('stock.read'));
            $g->get('/stock/overview', fn (Request $q, Response $r) => $json($r, ['items' => $s->stock->overview()]))->add($perm('stock.read'));
            $g->get('/stock/alerts', fn (Request $q, Response $r) => $json($r, ['items' => $s->stock->alerts(($q->getQueryParams()['unread'] ?? '') === '1')]))->add($perm('stock.read'));
            $g->post('/stock/alerts/read', function (Request $q, Response $r) use ($s) {
                $s->stock->markAlertsRead();
                return $r->withStatus(204);
            })->add($perm('stock.read'));
            $g->get('/stock/products/{id}/movements', fn (Request $q, Response $r, array $a) => $json($r, ['items' => $s->stock->movements($a['id'])]))->add($perm('stock.read'));
            $g->post('/stock/adjustments', function (Request $q, Response $r) use ($s, $json, $body, $actor) {
                $b = $body($q);
                $id = $s->stock->adjust((string) ($b['product_id'] ?? ''), (string) ($b['lot_id'] ?? ''), (int) ($b['delta'] ?? 0), (string) ($b['reason'] ?? ''), $actor($q), in_array($b['kind'] ?? '', ['breakage', 'loss', 'adjustment'], true) ? (string) $b['kind'] : 'adjustment');
                $s->audit->record('stock.adjust', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'movement_id' => $id]);
                return $json($r, ['movement_id' => $id], 201);
            })->add($perm('stock.write'));
            $g->post('/stock/movements/{id}/reverse', function (Request $q, Response $r, array $a) use ($s, $json, $body, $actor) {
                $id = $s->stock->reverse($a['id'], (string) ($body($q)['reason'] ?? ''), $actor($q));
                $s->audit->record('stock.reverse', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'movement_id' => $id, 'reversal_of' => $a['id']]);
                return $json($r, ['movement_id' => $id], 201);
            })->add($perm('stock.write'));
            $g->post('/stock/lots/{id}/quarantine', function (Request $q, Response $r, array $a) use ($s, $body, $actor) {
                $s->stock->quarantine($a['id'], (string) ($body($q)['reason'] ?? ''), $actor($q));
                $s->audit->record('stock.quarantine', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'lot_id' => $a['id']]);
                return $r->withStatus(204);
            })->add($perm('stock.write'));
            $g->post('/stock/lots/{id}/release', function (Request $q, Response $r, array $a) use ($s, $actor) {
                $s->stock->release($a['id']);
                $s->audit->record('stock.release', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'lot_id' => $a['id']]);
                return $r->withStatus(204);
            })->add($perm('stock.write'));
            $g->post('/stock/lots/{id}/destroy', function (Request $q, Response $r, array $a) use ($s, $json, $body, $actor) {
                $b = $body($q);
                $id = $s->stock->destroy($a['id'], (int) ($b['quantity'] ?? 0), (string) ($b['reason'] ?? ''), $b['method'] ?? null, $b['witness'] ?? null, $actor($q));
                $s->audit->record('stock.destroy', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'destruction_id' => $id]);
                return $json($r, ['id' => $id], 201);
            })->add($perm('stock.destroy'));
            $g->get('/stock/destructions', fn (Request $q, Response $r) => $json($r, ['items' => $s->stock->destructions()]))->add($perm('stock.read'));
            $g->get('/stock/destructions/{id}/document', function (Request $q, Response $r, array $a) use ($s) {
                $r->getBody()->write(\Sanara\Modules\Stock\StockDocuments::destruction($s->stock->destruction($a['id'])));
                return $r->withHeader('Content-Type', 'text/html; charset=utf-8');
            })->add($perm('stock.read'));
            // --- Inventaire (US8) ---
            $g->get('/inventories', fn (Request $q, Response $r) => $json($r, ['items' => $s->inventory->all()]))->add($perm('stock.read'));
            $g->post('/inventories', function (Request $q, Response $r) use ($s, $json, $body, $actor) {
                $b = $body($q);
                $id = $s->inventory->create((string) ($b['kind'] ?? 'full'), !empty($b['blind']), isset($b['family_id']) ? (string) $b['family_id'] : null, $actor($q));
                $s->audit->record('inventory.create', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'inventory_id' => $id]);
                return $json($r, ['id' => $id], 201);
            })->add($perm('stock.write'));
            $g->get('/inventories/{id}', fn (Request $q, Response $r, array $a) => $json($r, ['inventory' => $s->inventory->get($a['id']), 'lines' => $s->inventory->lines($a['id'])]))->add($perm('stock.read'));
            $g->post('/inventories/{id}/counts', function (Request $q, Response $r, array $a) use ($s, $json, $body, $actor) {
                $b = $body($q);
                return $json($r, ['recorded' => $s->inventory->count($a['id'], (string) ($b['device_id'] ?? 'web'), (array) ($b['counts'] ?? []), $actor($q))]);
            })->add($perm('stock.write'));
            $g->post('/inventories/{id}/submit', function (Request $q, Response $r, array $a) use ($s) {
                $s->inventory->submit($a['id']);
                return $r->withStatus(204);
            })->add($perm('stock.write'));
            $g->post('/inventories/{id}/cancel', function (Request $q, Response $r, array $a) use ($s) {
                $s->inventory->cancel($a['id']);
                return $r->withStatus(204);
            })->add($perm('stock.write'));
            $g->get('/inventories/{id}/report', fn (Request $q, Response $r, array $a) => $json($r, $s->inventory->report($a['id'])))->add($perm('stock.read'));
            $g->get('/inventories/{id}/report.csv', function (Request $q, Response $r, array $a) use ($s) {
                $r->getBody()->write($s->inventory->reportCsv($a['id']));
                return $r->withHeader('Content-Type', 'text/csv; charset=utf-8')->withHeader('Content-Disposition', 'attachment; filename="ecarts-inventaire.csv"');
            })->add($perm('stock.read'));
            $g->post('/inventories/{id}/validate', function (Request $q, Response $r, array $a) use ($s, $json, $actor) {
                $rep = $s->inventory->validate($a['id'], $actor($q), $s->stock);
                $s->audit->record('inventory.validate', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'inventory_id' => $a['id'], 'gap_value' => $rep['gap_value']]);
                return $json($r, $rep);
            })->add($perm('inventory.validate'));

            // --- Caisse et ventes (US10, US11) ---
            $tenantInfo = static fn (Request $q): array => $s->db->one('SELECT name, license_number, address, phone, currency FROM tenants WHERE id = ?', [(string) $q->getAttribute('tenantId')]) ?? [];
            $emailOf = static fn (string $id): string => (string) ($s->db->one('SELECT email FROM users WHERE id = ?', [$id])['email'] ?? '');
            $html = static function (Response $r, string $page): Response {
                $r->getBody()->write($page);
                return $r->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'private, no-store');
            };
            $g->get('/cash/current', fn (Request $q, Response $r) => $json($r, ['session' => $s->cash->current($actor($q))]))->add($perm('sales.create'));
            $g->post('/cash/open', function (Request $q, Response $r) use ($s, $json, $body, $actor) {
                $b = $body($q);
                $id = $s->cash->open($actor($q), (string) ($b['station'] ?? ''), (int) ($b['opening_float'] ?? 0));
                $s->audit->record('cash.open', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'session_id' => $id]);
                return $json($r, ['id' => $id], 201);
            })->add($perm('sales.create'));
            $g->get('/cash/sessions', fn (Request $q, Response $r) => $json($r, ['items' => $s->cash->sessions()]))->add($perm('cash.close'));
            $g->get('/cash/sessions/{id}/preview', fn (Request $q, Response $r, array $a) => $json($r, $s->cash->report($a['id'])))->add($perm('sales.create'));
            $g->post('/cash/sessions/{id}/close', function (Request $q, Response $r, array $a) use ($s, $json, $body, $actor) {
                $sess = $s->cash->session($a['id']);
                if ($sess['opened_by'] !== $actor($q) && !$s->authz->can($actor($q), 'cash.close')) {
                    throw new ApiException('forbidden', 403);
                }
                $b = $body($q);
                $rep = $s->cash->close($a['id'], $actor($q), (array) ($b['counts'] ?? []), isset($b['reason']) ? (string) $b['reason'] : null);
                $s->audit->record('cash.close', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'session_id' => $a['id'], 'gap' => $rep['session']['gap']]);
                return $json($r, $rep);
            })->add($perm('sales.create'));
            $g->get('/cash/sessions/{id}/z', fn (Request $q, Response $r, array $a) => $html($r, \Sanara\Modules\Sales\SalesDocuments::zReport($s->cash->report($a['id']))))->add($perm('sales.create'));

            $g->post('/sales', function (Request $q, Response $r) use ($s, $json, $body, $actor) {
                $sale = $s->sales->create($body($q), $actor($q));
                $s->audit->record('sale.create', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'sale_id' => $sale['id'], 'total' => $sale['total']]);
                return $json($r, $sale, 201);
            })->add($perm('sales.create'));
            $g->get('/sales', function (Request $q, Response $r) use ($s, $json, $actor) {
                $all = $s->authz->can($actor($q), 'reports.read') || $s->authz->can($actor($q), 'cash.close');
                return $json($r, ['items' => $s->sales->list($all ? null : $actor($q), ($q->getQueryParams()['date'] ?? null) ?: null)]);
            })->add($perm('sales.create'));
            $g->get('/sales/alternatives/{id}', fn (Request $q, Response $r, array $a) => $json($r, ['items' => $s->sales->alternatives($a['id'])]))->add($perm('sales.create'));
            $g->get('/sales/{id}', fn (Request $q, Response $r, array $a) => $json($r, $s->sales->get($a['id'])))->add($perm('sales.create'));
            $g->get('/sales/{id}/receipt', function (Request $q, Response $r, array $a) use ($s, $html, $tenantInfo, $emailOf) {
                $sale = $s->sales->get($a['id']);
                $n = $s->sales->markPrinted($a['id']);
                $page = \Sanara\Modules\Sales\SalesDocuments::receipt($sale, $tenantInfo($q), $s->setup->settings(), $emailOf((string) $sale['cashier_id']), $n > 1, isset($q->getQueryParams()['width']) ? (string) $q->getQueryParams()['width'] : null);
                return $html($r, $page);
            })->add($perm('sales.create'));
            $g->get('/sales/{id}/invoice', function (Request $q, Response $r, array $a) use ($s, $html, $tenantInfo, $emailOf) {
                $sale = $s->sales->get($a['id']);
                $num = $s->sales->invoiceNumber($a['id']);
                $dup = $s->sales->markPrinted($a['id']) > 1;
                return $html($r, \Sanara\Modules\Sales\SalesDocuments::invoice($s->sales->get($a['id']), $tenantInfo($q), $s->setup->settings(), $num, $emailOf((string) $sale['cashier_id']), $dup));
            })->add($perm('sales.create'));
            $g->post('/sales/{id}/returns', function (Request $q, Response $r, array $a) use ($s, $json, $body, $actor) {
                $ret = $s->returns->create($a['id'], $body($q), $actor($q));
                $s->audit->record('sale.return', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'sale_id' => $a['id'], 'return_id' => $ret['id'], 'amount' => $ret['refund_amount'], 'restock' => $ret['restock']]);
                return $json($r, $ret, 201);
            })->add($perm('sales.create'));
            $g->get('/sales/{id}/returns', fn (Request $q, Response $r, array $a) => $json($r, ['items' => $s->returns->forSale($a['id'])]))->add($perm('sales.create'));
            $g->get('/returns/{id}/credit-note', fn (Request $q, Response $r, array $a) => $html($r, \Sanara\Modules\Sales\SalesDocuments::creditNote($s->returns->creditNote($a['id']), $tenantInfo($q))))->add($perm('sales.create'));
            // --- Comptes clients (US13) ---
            $g->get('/customers', fn (Request $q, Response $r) => $json($r, ['items' => $s->customers->search((string) ($q->getQueryParams()['q'] ?? ''))]))->add($perm('customers.read'));
            $g->post('/customers', function (Request $q, Response $r) use ($s, $json, $body, $actor) {
                $id = $s->customers->create($body($q));
                $s->audit->record('customer.create', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'entity' => 'customer', 'entity_id' => $id]);
                return $json($r, ['id' => $id], 201);
            })->add($perm('customers.write'));
            $g->get('/customers/{id}', fn (Request $q, Response $r, array $a) => $json($r, $s->customers->get($a['id'])))->add($perm('customers.read'));
            $g->post('/customers/{id}', function (Request $q, Response $r, array $a) use ($s, $body, $actor) {
                $s->customers->update($a['id'], $body($q));
                $s->audit->record('customer.update', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'entity' => 'customer', 'entity_id' => $a['id']]);
                return $r->withStatus(204);
            })->add($perm('customers.write'));
            $g->post('/customers/{id}/payments', function (Request $q, Response $r, array $a) use ($s, $json, $body, $actor) {
                $paid = $s->customers->pay($a['id'], $body($q), $actor($q));
                $s->audit->record('customer.payment', ['user_id' => $actor($q), 'tenant_id' => $q->getAttribute('tenantId'), 'entity' => 'customer', 'entity_id' => $a['id'], 'receipt' => $paid['receipt_number']]);
                return $json($r, $paid, 201);
            })->add($perm('customers.collect'));
            $g->get('/customers/{id}/receipts/{entry}', fn (Request $q, Response $r, array $a) => $html($r, \Sanara\Modules\Sales\SalesDocuments::debtReceipt($s->customers->receipt($a['id'], $a['entry']), $tenantInfo($q))))->add($perm('customers.read'));
            $g->get('/pos/held', fn (Request $q, Response $r) => $json($r, ['items' => $s->sales->held($actor($q))]))->add($perm('sales.create'));
            $g->post('/pos/held', function (Request $q, Response $r) use ($s, $json, $body, $actor) {
                return $json($r, ['id' => $s->sales->hold($actor($q), (string) ($body($q)['label'] ?? ''), $body($q)['cart'] ?? null)], 201);
            })->add($perm('sales.create'));
            $g->delete('/pos/held/{id}', function (Request $q, Response $r, array $a) use ($s, $actor) {
                $s->sales->resume($actor($q), $a['id']);
                return $r->withStatus(204);
            })->add($perm('sales.create'));
            // Collègues pouvant valider une action sensible (remise, ordonnance, retour) par PIN.
            $g->get('/approvers', function (Request $q, Response $r) use ($s, $json) {
                $perm = (string) ($q->getQueryParams()['permission'] ?? '');
                if (!in_array($perm, ['sales.discount_override', 'narcotics.dispense', 'sales.cancel'], true)) {
                    throw new ApiException('validation_failed', 422, ['fields' => ['permission' => 'invalid']]);
                }
                $out = [];
                foreach ($s->db->all("SELECT id, email, role_code FROM users WHERE tenant_id = ? AND status = 'active' ORDER BY email", [(string) $q->getAttribute('tenantId')]) as $u) {
                    if ($s->authz->can((string) $u['id'], $perm)) {
                        $out[] = ['id' => $u['id'], 'email' => $u['email'], 'role' => $u['role_code']];
                    }
                }
                return $json($r, ['items' => $out]);
            })->add($perm('sales.create'));


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

        // --- Synchronisation des postes hors-ligne (US28) ---
        $app->group('/sync', function (RouteCollectorProxy $g) use ($s, $json, $body, $rf) {
            $perm = fn (string $p) => new RequirePermission($s->authz, $p, $rf, $s->audit);
            $g->post('/push', fn (Request $q, Response $r) => $json($r, $s->sync->push($body($q), (string) $q->getAttribute('userId'))))->add($perm('sales.create'));
            $g->get('/conflicts', fn (Request $q, Response $r) => $json($r, ['items' => $s->sync->openConflicts()]))->add($perm('stock.read'));
            $g->post('/conflicts/{id}/resolve', function (Request $q, Response $r, array $a) use ($s) {
                $s->sync->resolveConflict($a['id'], (string) $q->getAttribute('userId'));
                return $r->withStatus(204);
            })->add($perm('stock.write'));
            $g->post('/device', function (Request $q, Response $r) use ($s, $json, $body) {
                $b = $body($q);
                return $json($r, $s->devices->register((string) $q->getAttribute('userId'), (string) ($b['deviceId'] ?? ''), (string) ($b['name'] ?? '')));
            })->add($perm('sales.create'));
            $g->get('/pull', fn (Request $q, Response $r) => $json($r, $s->sync->pull((string) ($q->getQueryParams()['cursor'] ?? ''), (int) ($q->getQueryParams()['limit'] ?? 500), (string) ($q->getQueryParams()['since'] ?? ''))))->add($perm('sales.create'));
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
