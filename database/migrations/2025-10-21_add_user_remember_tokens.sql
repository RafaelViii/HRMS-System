-- Persistent remember-me tokens for long-lived sessions
CREATE TABLE user_remember_tokens (
  id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  selector VARCHAR(64) NOT NULL UNIQUE,
  token_hash VARCHAR(128) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_user_remember_tokens_user ON user_remember_tokens (user_id);
CREATE INDEX idx_user_remember_tokens_expires ON user_remember_tokens (expires_at);
