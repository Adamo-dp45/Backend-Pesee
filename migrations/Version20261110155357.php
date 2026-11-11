<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/*
    - La demande de mise en compte : le chemin par lequel un opérateur demande l'ouverture d'un
      prélèvement pour son planteur, et par lequel l'administrateur décide.
*/
final class Version20261110155357 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demande de mise en compte : table et index';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE demande_prelevement (created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, created_by INT DEFAULT NULL, updated_by INT DEFAULT NULL, deleted_by INT DEFAULT NULL, id INT AUTO_INCREMENT NOT NULL, taux_demande INT NOT NULL, motif LONGTEXT DEFAULT NULL, statut VARCHAR(255) DEFAULT \'EN_ATTENTE\' NOT NULL, traite_at DATETIME DEFAULT NULL, motif_rejet LONGTEXT DEFAULT NULL, fournisseur_id INT NOT NULL, site_id INT NOT NULL, demande_par_id INT DEFAULT NULL, traite_par_id INT DEFAULT NULL, INDEX IDX_29EF386B670C757F (fournisseur_id), INDEX IDX_29EF386BF6BD1646 (site_id), INDEX IDX_29EF386B4C0C045 (demande_par_id), INDEX IDX_29EF386B167FABE8 (traite_par_id), INDEX idx_demande_prelevement_statut (fournisseur_id, statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE demande_prelevement ADD CONSTRAINT FK_29EF386B670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id)');
        $this->addSql('ALTER TABLE demande_prelevement ADD CONSTRAINT FK_29EF386BF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE demande_prelevement ADD CONSTRAINT FK_29EF386B4C0C045 FOREIGN KEY (demande_par_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_prelevement ADD CONSTRAINT FK_29EF386B167FABE8 FOREIGN KEY (traite_par_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE demande_prelevement DROP FOREIGN KEY FK_29EF386B670C757F');
        $this->addSql('ALTER TABLE demande_prelevement DROP FOREIGN KEY FK_29EF386BF6BD1646');
        $this->addSql('ALTER TABLE demande_prelevement DROP FOREIGN KEY FK_29EF386B4C0C045');
        $this->addSql('ALTER TABLE demande_prelevement DROP FOREIGN KEY FK_29EF386B167FABE8');
        $this->addSql('DROP TABLE demande_prelevement');
    }
}
