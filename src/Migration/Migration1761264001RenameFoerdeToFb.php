<?php declare(strict_types=1);

namespace FoerdeClickCollect\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1761264001RenameFoerdeToFb extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        // 2025-10-24 00:00:01 UTC
        return 1761264001;
    }

    public function update(Connection $connection): void
    {
        $fbTypeId = $connection->fetchOne('SELECT id FROM mail_template_type WHERE technical_name = :name', [
            'name' => 'fb_click_collect.ready',
        ]);
        $foerdeTypeId = $connection->fetchOne('SELECT id FROM mail_template_type WHERE technical_name = :name', [
            'name' => 'foerde_click_collect.ready',
        ]);

        if (!$fbTypeId && $foerdeTypeId) {
            $connection->update('mail_template_type', ['technical_name' => 'fb_click_collect.ready'], ['id' => $foerdeTypeId]);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
        // no-op
    }
}
