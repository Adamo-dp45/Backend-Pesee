<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260808021737 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE entreprise (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, codeentreprise VARCHAR(20) NOT NULL, adresse VARCHAR(255) DEFAULT NULL, contact1 VARCHAR(30) NOT NULL, contact2 VARCHAR(30) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, solde BIGINT DEFAULT 0 NOT NULL, statut VARCHAR(255) DEFAULT \'ACTIVE\' NOT NULL, UNIQUE INDEX uniq_entreprise_code (codeentreprise), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE refresh_tokens (refresh_token VARCHAR(128) NOT NULL, username VARCHAR(255) NOT NULL, valid DATETIME NOT NULL, family VARCHAR(32) DEFAULT NULL, family_valid DATETIME DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, UNIQUE INDEX UNIQ_9BACE7E1C74F2195 (refresh_token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, libellesite VARCHAR(255) NOT NULL, localite VARCHAR(255) DEFAULT NULL, solde BIGINT DEFAULT 0 NOT NULL, statut VARCHAR(255) DEFAULT \'ACTIF\' NOT NULL, entreprise_id INT NOT NULL, operateur_id INT DEFAULT NULL, INDEX IDX_694309E43F192FC (operateur_id), INDEX idx_site_entreprise (entreprise_id), UNIQUE INDEX uniq_site_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, nom VARCHAR(255) NOT NULL, prenom VARCHAR(255) NOT NULL, telephone VARCHAR(30) DEFAULT NULL, statut VARCHAR(255) DEFAULT \'ACTIF\' NOT NULL, fondateur TINYINT DEFAULT 0 NOT NULL, derniere_connexion_at DATETIME DEFAULT NULL, entreprise_id INT DEFAULT NULL, site_machine_id INT DEFAULT NULL, INDEX IDX_8D93D649A4AEAFEA (entreprise_id), UNIQUE INDEX UNIQ_8D93D649D6BED4E3 (site_machine_id), UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE site ADD CONSTRAINT FK_694309E4A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE site ADD CONSTRAINT FK_694309E43F192FC FOREIGN KEY (operateur_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id)');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649D6BED4E3 FOREIGN KEY (site_machine_id) REFERENCES site (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE site DROP FOREIGN KEY FK_694309E4A4AEAFEA');
        $this->addSql('ALTER TABLE site DROP FOREIGN KEY FK_694309E43F192FC');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D649A4AEAFEA');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D649D6BED4E3');
        $this->addSql('DROP TABLE entreprise');
        $this->addSql('DROP TABLE refresh_tokens');
        $this->addSql('DROP TABLE site');
        $this->addSql('DROP TABLE user');
    }
}
