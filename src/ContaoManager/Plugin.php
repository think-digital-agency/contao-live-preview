<?php

declare(strict_types=1);

namespace Vendor\ContaoLivePreviewBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Vendor\ContaoLivePreviewBundle\ContaoLivePreviewBundle;

class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(ContaoLivePreviewBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class]),
        ];
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?\Symfony\Component\Routing\RouteCollection
    {
        $file = __DIR__.'/../../config/routes.yaml';
        $loader = $resolver->resolve($file, 'yaml');

        return $loader ? $loader->load($file) : null;
    }
}
