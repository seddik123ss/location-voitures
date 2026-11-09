<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108214824 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tables complaint, complaint_message et complaint_status_history';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE complaint (id CHAR(36) NOT NULL, user_id CHAR(36) NOT NULL, reservation_id CHAR(36) DEFAULT NULL, contract_id CHAR(36) DEFAULT NULL, category VARCHAR(20) NOT NULL, priority VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, subject VARCHAR(150) NOT NULL, description LONGTEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_complaint_user (user_id), INDEX idx_complaint_status (status), INDEX idx_complaint_category (category), INDEX idx_complaint_priority (priority), INDEX idx_complaint_reservation (reservation_id), INDEX idx_complaint_contract (contract_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE complaint_message (id CHAR(36) NOT NULL, author_id CHAR(36) NOT NULL, author_role VARCHAR(10) NOT NULL, content LONGTEXT NOT NULL, created_at DATETIME NOT NULL, complaint_id CHAR(36) NOT NULL, INDEX IDX_C3F5D02EEDAE188E (complaint_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE complaint_status_history (id CHAR(36) NOT NULL, from_status VARCHAR(20) DEFAULT NULL, to_status VARCHAR(20) NOT NULL, changed_by CHAR(36) NOT NULL, changed_by_role VARCHAR(10) NOT NULL, comment LONGTEXT DEFAULT NULL, changed_at DATETIME NOT NULL, complaint_id CHAR(36) NOT NULL, INDEX IDX_88EEBD69EDAE188E (complaint_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE complaint_message ADD CONSTRAINT FK_C3F5D02EEDAE188E FOREIGN KEY (complaint_id) REFERENCES complaint (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE complaint_status_history ADD CONSTRAINT FK_88EEBD69EDAE188E FOREIGN KEY (complaint_id) REFERENCES complaint (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE complaint_message DROP FOREIGN KEY FK_C3F5D02EEDAE188E');
        $this->addSql('ALTER TABLE complaint_status_history DROP FOREIGN KEY FK_88EEBD69EDAE188E');
        $this->addSql('DROP TABLE complaint');
        $this->addSql('DROP TABLE complaint_message');
        $this->addSql('DROP TABLE complaint_status_history');
    }
}
