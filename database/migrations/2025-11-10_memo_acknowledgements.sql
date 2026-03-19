-- Migration: Add memo_acknowledgements table
-- Date: 2025-11-10
-- Purpose: Track when users acknowledge/read memos they were sent

CREATE TABLE IF NOT EXISTS memo_acknowledgements (
  id BIGSERIAL PRIMARY KEY,
  memo_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  acknowledged_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_memo_ack_memo FOREIGN KEY (memo_id)
    REFERENCES memos(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_memo_ack_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT uq_memo_ack UNIQUE (memo_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_memo_ack_memo ON memo_acknowledgements(memo_id);
CREATE INDEX IF NOT EXISTS idx_memo_ack_user ON memo_acknowledgements(user_id);
CREATE INDEX IF NOT EXISTS idx_memo_ack_date ON memo_acknowledgements(acknowledged_at DESC);

COMMENT ON TABLE memo_acknowledgements IS 'Tracks when users acknowledge receipt/reading of memos';
COMMENT ON COLUMN memo_acknowledgements.acknowledged_at IS 'Timestamp when user clicked acknowledge button';
