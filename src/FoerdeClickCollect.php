<?php declare(strict_types=1);

namespace FoerdeClickCollect;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class FoerdeClickCollect extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        // Shipping method provisioning will be added in a later implementation step.
    }

    public function postInstall(InstallContext $installContext): void
    {
        parent::postInstall($installContext);
        // Post-install logic will be introduced together with the shipping setup.
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        // Keep uninstall lean until data removal requirements are defined.
    }
}
