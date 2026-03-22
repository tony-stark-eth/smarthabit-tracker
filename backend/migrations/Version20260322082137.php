<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260322082137 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, email VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, display_name VARCHAR(100) NOT NULL, timezone VARCHAR(50) NOT NULL, locale VARCHAR(255) NOT NULL, theme VARCHAR(255) NOT NULL, push_subscriptions JSON DEFAULT NULL, consent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, consent_version VARCHAR(20) DEFAULT NULL, email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, household_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('CREATE INDEX IDX_8D93D649E79FF843 ON "user" (household_id)');
        $this->addSql('CREATE TABLE habit (id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, frequency VARCHAR(255) NOT NULL, icon VARCHAR(50) DEFAULT NULL, color VARCHAR(20) DEFAULT NULL, sort_order INT DEFAULT 0 NOT NULL, time_window_start TIME(0) WITHOUT TIME ZONE DEFAULT NULL, time_window_end TIME(0) WITHOUT TIME ZONE DEFAULT NULL, time_window_mode VARCHAR(255) DEFAULT \'manual\' NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, household_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_44FE2172E79FF843 ON habit (household_id)');
        $this->addSql('CREATE TABLE habit_log (id UUID NOT NULL, logged_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, habit_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C1637C45E7AEB3B2 ON habit_log (habit_id)');
        $this->addSql('CREATE INDEX IDX_C1637C45A76ED395 ON habit_log (user_id)');
        $this->addSql('CREATE TABLE household (id UUID NOT NULL, name VARCHAR(255) NOT NULL, invite_code VARCHAR(8) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_54C32FC06F21F112 ON household (invite_code)');
        $this->addSql('CREATE TABLE notification_log (id UUID NOT NULL, channel VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, message TEXT NOT NULL, user_id UUID NOT NULL, habit_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_ED15DF2A76ED395 ON notification_log (user_id)');
        $this->addSql('CREATE INDEX IDX_ED15DF2E7AEB3B2 ON notification_log (habit_id)');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT FK_8D93D649E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE habit ADD CONSTRAINT FK_44FE2172E79FF843 FOREIGN KEY (household_id) REFERENCES household (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE habit_log ADD CONSTRAINT FK_C1637C45E7AEB3B2 FOREIGN KEY (habit_id) REFERENCES habit (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE habit_log ADD CONSTRAINT FK_C1637C45A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_log ADD CONSTRAINT FK_ED15DF2A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_log ADD CONSTRAINT FK_ED15DF2E7AEB3B2 FOREIGN KEY (habit_id) REFERENCES habit (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT FK_8D93D649E79FF843');
        $this->addSql('ALTER TABLE habit DROP CONSTRAINT FK_44FE2172E79FF843');
        $this->addSql('ALTER TABLE habit_log DROP CONSTRAINT FK_C1637C45E7AEB3B2');
        $this->addSql('ALTER TABLE habit_log DROP CONSTRAINT FK_C1637C45A76ED395');
        $this->addSql('ALTER TABLE notification_log DROP CONSTRAINT FK_ED15DF2A76ED395');
        $this->addSql('ALTER TABLE notification_log DROP CONSTRAINT FK_ED15DF2E7AEB3B2');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE habit');
        $this->addSql('DROP TABLE habit_log');
        $this->addSql('DROP TABLE household');
        $this->addSql('DROP TABLE notification_log');
    }
}
