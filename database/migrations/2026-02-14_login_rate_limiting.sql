-- Database-backed login rate limiting
-- Tracks failed login attempts by IP to prevent brute-force attacks
-- Even if sessions are cleared/rotated, rate limits persist

CREATE TABLE IF NOT EXISTS login_rate_limits (
    ip_address  VARCHAR(45) NOT NULL,  -- supports IPv4 and IPv6
    email       VARCHAR(255) NOT NULL DEFAULT '',
    attempts    INTEGER NOT NULL DEFAULT 1,
    first_attempt_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until    TIMESTAMP WITHOUT TIME ZONE,
    PRIMARY KEY (ip_address, email)
);

-- Index for cleanup of old entries
CREATE INDEX IF NOT EXISTS idx_login_rate_limits_first_attempt
    ON login_rate_limits (first_attempt_at);

-- Cleanup function: remove entries older than 1 hour (called periodically)
-- Can be run via: DELETE FROM login_rate_limits WHERE first_attempt_at < NOW() - INTERVAL '1 hour';
