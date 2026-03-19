-- Memo module tables
CREATE TABLE IF NOT EXISTS memos (
  id BIGSERIAL PRIMARY KEY,
  memo_code VARCHAR(50) NOT NULL UNIQUE,
  header VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  issued_by_user_id INT NULL,
  issued_by_name VARCHAR(150) NOT NULL,
  issued_by_position VARCHAR(150) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  published_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_memos_user FOREIGN KEY (issued_by_user_id)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS memo_recipients (
  id BIGSERIAL PRIMARY KEY,
  memo_id BIGINT NOT NULL,
  audience_type VARCHAR(20) NOT NULL,
  audience_identifier VARCHAR(100) NULL,
  audience_label VARCHAR(150) NULL,
  created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_memo_recipient_memo FOREIGN KEY (memo_id)
    REFERENCES memos(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT chk_memo_audience_type CHECK (audience_type IN ('all','department','employee','role'))
);

CREATE INDEX IF NOT EXISTS idx_memo_recipients_memo ON memo_recipients (memo_id);
CREATE INDEX IF NOT EXISTS idx_memo_recipients_type ON memo_recipients (audience_type, audience_identifier);

CREATE TABLE IF NOT EXISTS memo_attachments (
  id BIGSERIAL PRIMARY KEY,
  memo_id BIGINT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  file_size BIGINT NOT NULL DEFAULT 0,
  mime_type VARCHAR(100) NULL,
  description TEXT NULL,
  uploaded_by INT NULL,
  uploaded_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_memo_attachment_memo FOREIGN KEY (memo_id)
    REFERENCES memos(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_memo_attachment_user FOREIGN KEY (uploaded_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_memo_attachments_memo ON memo_attachments (memo_id);
CREATE INDEX IF NOT EXISTS idx_memo_attachments_uploaded ON memo_attachments (uploaded_at DESC);

CREATE OR REPLACE FUNCTION fn_memo_set_updated()
RETURNS trigger AS $FN$
BEGIN
  NEW.updated_at = CURRENT_TIMESTAMP;
  RETURN NEW;
END;
$FN$ LANGUAGE plpgsql;

DO $TRG$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_memo_set_updated') THEN
    EXECUTE 'CREATE TRIGGER trg_memo_set_updated BEFORE UPDATE ON memos FOR EACH ROW EXECUTE FUNCTION fn_memo_set_updated()';
  END IF;
END;
$TRG$;
