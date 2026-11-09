<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108222700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonne position (ordre des messages et de l\'historique par réclamation), renseignée pour les lignes existantes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE complaint_message ADD position INT NOT NULL');
        $this->addSql('UPDATE complaint_message m JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY complaint_id ORDER BY created_at, id) AS rn FROM complaint_message) r ON r.id = m.id SET m.position = r.rn');
        $this->addSql('CREATE UNIQUE INDEX uniq_complaint_message_position ON complaint_message (complaint_id, position)');

        $this->addSql('ALTER TABLE complaint_status_history ADD position INT NOT NULL');
        // La création (from_status NULL) passe en premier à seconde égale.
        $this->addSql('UPDATE complaint_status_history h JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY complaint_id ORDER BY changed_at, from_status IS NOT NULL, id) AS rn FROM complaint_status_history) r ON r.id = h.id SET h.position = r.rn');
        $this->addSql('CREATE UNIQUE INDEX uniq_complaint_history_position ON complaint_status_history (complaint_id, position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_complaint_message_position ON complaint_message');
        $this->addSql('ALTER TABLE complaint_message DROP position');
        $this->addSql('DROP INDEX uniq_complaint_history_position ON complaint_status_history');
        $this->addSql('ALTER TABLE complaint_status_history DROP position');
    }
}
