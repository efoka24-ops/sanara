<?php
declare(strict_types=1);

namespace Sanara\Modules\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sanara\Common\Errors\ApiException;

/**
 * T255 : abonnement échu (après la grâce) : la pharmacie consulte et exporte, mais ne modifie plus rien.
 * Clôturer une caisse ouverte reste permis, pour ne pas bloquer l'argent du jour.
 */
final class ReadOnlyGuard implements MiddlewareInterface
{
    public function __construct(private readonly SubscriptionPolicy $policy)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $tenant = $request->getAttribute('tenantId');
        $writes = !in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
        $closing = preg_match('#/cash/sessions/[^/]+/close$#', $request->getUri()->getPath()) === 1;
        if ($writes && !$closing && is_string($tenant) && $this->policy->readOnly($tenant)) {
            throw new ApiException('subscription_expired', 402);
        }
        return $handler->handle($request);
    }
}
