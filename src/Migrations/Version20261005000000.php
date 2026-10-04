<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bosta deliveries attached to Sylius shipments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(self::CREATE_TABLE);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE storkplus_bosta_delivery');
    }

    public const CREATE_TABLE = <<<'SQL'
        CREATE TABLE IF NOT EXISTS storkplus_bosta_delivery (
            id INT AUTO_INCREMENT NOT NULL,
            shipment_id INT NOT NULL,
            status VARCHAR(16) NOT NULL,
            delivery_id VARCHAR(64) DEFAULT NULL,
            tracking_number VARCHAR(64) DEFAULT NULL,
            sequence INT NOT NULL,
            state_code INT DEFAULT NULL,
            state_label VARCHAR(64) DEFAULT NULL,
            exception_reason VARCHAR(500) DEFAULT NULL,
            last_error LONGTEXT DEFAULT NULL,
            attempts INT NOT NULL,
            last_attempt_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            last_webhook_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            UNIQUE INDEX UNIQ_BOSTA_DELIVERY_SHIPMENT (shipment_id),
            INDEX idx_bosta_delivery_tracking (tracking_number),
            INDEX idx_bosta_delivery_status (status),
            PRIMARY KEY(id),
            CONSTRAINT FK_BOSTA_DELIVERY_SHIPMENT FOREIGN KEY (shipment_id) REFERENCES sylius_shipment (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL;
}
