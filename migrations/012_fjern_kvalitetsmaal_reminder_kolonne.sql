-- Migration: Fjerner last_reminder_sent_period_end fra quality_objectives.
-- Kolonnen hørte til den dagligt kørende e-mail-påmindelse (cron-job), som er droppet
-- igen — kvalitetsmål-påmindelsen er nu KUN opgaven på "Mine opgaver" (beregnes live,
-- intet cron-job, ingen e-mail). Kolonnen er dermed ubrugt.

ALTER TABLE `quality_objectives`
  DROP COLUMN `last_reminder_sent_period_end`;

INSERT INTO schema_migrations (filename) VALUES ('012_fjern_kvalitetsmaal_reminder_kolonne.sql');
