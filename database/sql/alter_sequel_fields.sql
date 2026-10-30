-- Jalankan di phpMyAdmin Hostinger jika artisan migrate gagal/belum:

ALTER TABLE content_briefs
  ADD COLUMN series_title VARCHAR(180) NULL AFTER source_topic,
  ADD COLUMN episode_number TINYINT UNSIGNED NULL AFTER series_title,
  ADD COLUMN parent_blueprint_id BIGINT UNSIGNED NULL AFTER episode_number,
  ADD COLUMN previous_episodes JSON NULL AFTER parent_blueprint_id;

ALTER TABLE content_blueprints
  ADD COLUMN series_title VARCHAR(180) NULL AFTER source_topic,
  ADD COLUMN episode_number TINYINT UNSIGNED NULL AFTER series_title,
  ADD COLUMN parent_blueprint_id BIGINT UNSIGNED NULL AFTER episode_number,
  ADD COLUMN previous_episodes JSON NULL AFTER parent_blueprint_id;

-- FK (opsional; skip jika error duplicate constraint):
ALTER TABLE content_briefs
  ADD CONSTRAINT content_briefs_parent_blueprint_id_foreign
  FOREIGN KEY (parent_blueprint_id) REFERENCES content_blueprints(id) ON DELETE SET NULL;

ALTER TABLE content_blueprints
  ADD CONSTRAINT content_blueprints_parent_blueprint_id_foreign
  FOREIGN KEY (parent_blueprint_id) REFERENCES content_blueprints(id) ON DELETE SET NULL;
